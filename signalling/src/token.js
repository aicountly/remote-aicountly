/**
 * Verifying the signalling token the CI4 API minted.
 *
 * This service does not decide anything. It has no database, no policy engine
 * and no idea what a company is — it checks a signature and puts a connection
 * in the room the token names. Everything that *authorises* a participant
 * happened in the API before the token existed (see
 * app/Domain/Signalling/SignallingTokenService.php).
 *
 * That division is why a client cannot ask to join an arbitrary room: the room
 * is inside the signed payload, and there is no code path here that reads a
 * room name from a client message.
 */

import { createHmac, timingSafeEqual } from 'node:crypto';

/** Refuse a token whose lifetime is longer than the API is supposed to mint. */
const MAX_TOKEN_LIFETIME_SECONDS = 600;

/** Tolerated clock difference between the API host and this one. */
const CLOCK_SKEW_SECONDS = 30;

/**
 * Rooms belonging to a device rather than to a session.
 *
 * Mirrors `App\Domain\Device\DevicePresenceService::ROOM_PREFIX`. A session
 * room is a bare session uuid, so the prefix cannot collide with one.
 */
export const DEVICE_ROOM_PREFIX = 'device-';

export class TokenError extends Error {
  constructor(code, message) {
    super(message);
    this.name = 'TokenError';
    this.code = code;
  }
}

function base64UrlDecode(value) {
  return Buffer.from(value.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
}

/**
 * @param {string} token compact HS256 JWS from POST /sessions/{uuid}/signalling-token
 * @param {string} secret shared with the API (REMOTE_SIGNALLING_TOKEN_SECRET)
 * @returns {{room: string, participantUuid: string, role: string, name: string,
 *            capabilities: Record<string, boolean>, expiresAt: number}}
 */
export function verifySignallingToken(token, secret) {
  if (!secret) {
    // Without a secret every token would verify, which is worse than not
    // starting at all — so the server refuses to boot instead of reaching here.
    throw new TokenError('NO_SECRET', 'Signalling secret is not configured');
  }

  if (typeof token !== 'string' || token.length > 4096) {
    throw new TokenError('MALFORMED', 'Token is missing or implausibly large');
  }

  const parts = token.split('.');
  if (parts.length !== 3) {
    throw new TokenError('MALFORMED', 'Token is not a compact JWS');
  }

  const [encodedHeader, encodedPayload, encodedSignature] = parts;

  let header;
  try {
    header = JSON.parse(base64UrlDecode(encodedHeader).toString('utf8'));
  } catch {
    throw new TokenError('MALFORMED', 'Token header is not JSON');
  }

  // Named explicitly: accepting whatever `alg` says is how `alg: none` and the
  // algorithm-confusion family get in.
  if (header?.alg !== 'HS256') {
    throw new TokenError('ALG_UNSUPPORTED', 'Only HS256 is accepted');
  }

  const expected = createHmac('sha256', secret)
    .update(`${encodedHeader}.${encodedPayload}`)
    .digest();
  const provided = base64UrlDecode(encodedSignature);

  // Length check first: timingSafeEqual throws on a length mismatch.
  if (provided.length !== expected.length || !timingSafeEqual(provided, expected)) {
    throw new TokenError('BAD_SIGNATURE', 'Token signature does not match');
  }

  let claims;
  try {
    claims = JSON.parse(base64UrlDecode(encodedPayload).toString('utf8'));
  } catch {
    throw new TokenError('MALFORMED', 'Token payload is not JSON');
  }

  if (claims.iss !== 'aicountly-remote-api') {
    throw new TokenError('BAD_ISSUER', 'Token was not issued by the Remote API');
  }

  if (claims.aud !== 'aicountly-remote-signalling') {
    throw new TokenError('BAD_AUDIENCE', 'Token was not issued for this service');
  }

  const now = Math.floor(Date.now() / 1000);

  if (typeof claims.exp !== 'number' || claims.exp < now - CLOCK_SKEW_SECONDS) {
    throw new TokenError('EXPIRED', 'Token has expired');
  }

  if (typeof claims.iat !== 'number' || claims.iat > now + CLOCK_SKEW_SECONDS) {
    throw new TokenError('NOT_YET_VALID', 'Token is not valid yet');
  }

  // A token with an implausible lifetime did not come from the API this
  // service is paired with, whatever its signature says.
  if (claims.exp - claims.iat > MAX_TOKEN_LIFETIME_SECONDS) {
    throw new TokenError('LIFETIME_TOO_LONG', 'Token lifetime exceeds what the API issues');
  }

  if (typeof claims.room !== 'string' || claims.room.length < 8 || claims.room.length > 64) {
    throw new TokenError('BAD_ROOM', 'Token names no usable room');
  }

  if (typeof claims.sub !== 'string' || claims.sub.length < 8 || claims.sub.length > 64) {
    throw new TokenError('BAD_SUBJECT', 'Token names no participant');
  }

  // What the connection *is*. A session room carries SDP, ICE and the
  // collaboration channel; a device's presence room carries a heartbeat and an
  // invitation to join a session, and nothing else. Keeping them apart here —
  // rather than only in the API that mints the token — is what stops a device
  // presence credential being replayed to push an offer at somebody.
  //
  // Absent means 'session', so every token minted before this claim existed
  // keeps working. An unrecognised value is refused rather than defaulted:
  // guessing at a kind is how a room ends up relaying what it should not.
  const kind = claims.knd ?? 'session';
  if (kind !== 'session' && kind !== 'device') {
    throw new TokenError('BAD_KIND', 'Token names no usable connection kind');
  }

  // Belt and braces on the two the API is supposed to keep in step: a device
  // room is named after its device, and nothing else may claim to be one.
  if (kind === 'device' && !claims.room.startsWith(DEVICE_ROOM_PREFIX)) {
    throw new TokenError('BAD_ROOM', 'A device token must name a device room');
  }
  if (kind === 'session' && claims.room.startsWith(DEVICE_ROOM_PREFIX)) {
    throw new TokenError('BAD_ROOM', 'A session token cannot name a device room');
  }

  return {
    room: claims.room,
    kind,
    participantUuid: claims.sub,
    role: typeof claims.role === 'string' ? claims.role : 'VIEWER',
    name: typeof claims.name === 'string' ? claims.name.slice(0, 120) : 'Participant',
    capabilities: typeof claims.cap === 'object' && claims.cap !== null ? claims.cap : {},
    expiresAt: claims.exp,
  };
}
