/**
 * How a browser presents its signalling token (§19).
 *
 * The WebSocket API cannot set an Authorization header, so the token travels as
 * the subprotocol `aicountly-remote.<token>`, which the service reads and
 * agrees to. Not in the query string: a URL is what reverse proxies write to
 * their access logs. (The desktop agent still sends `?token=`, which the service
 * also accepts.)
 */

export const SIGNALLING_SUBPROTOCOL_PREFIX = 'aicountly-remote.'

export function signallingProtocols(token: string): string[] {
  return [`${SIGNALLING_SUBPROTOCOL_PREFIX}${token}`]
}
