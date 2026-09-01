/**
 * Room membership, in memory.
 *
 * A room is one Remote session and it exists only while somebody is connected
 * to it. Nothing here is persisted — the session, its participants and its
 * audit trail live in PostgreSQL behind the CI4 API, and duplicating any of
 * that here would create a second source of truth that drifts.
 *
 * One participant may hold only one live connection. A second connection for
 * the same participant uuid replaces the first, which is what makes a
 * reconnect after a dropped network work: the stale socket is closed rather
 * than left to double-deliver every offer.
 */

export class Rooms {
  constructor() {
    /** @type {Map<string, Map<string, {socket: import('ws').WebSocket, participant: object}>>} */
    this.rooms = new Map();
  }

  /**
   * Add a connection, evicting any previous one for the same participant.
   *
   * @returns {import('ws').WebSocket|null} the socket that was replaced
   */
  join(room, participant, socket) {
    if (!this.rooms.has(room)) {
      this.rooms.set(room, new Map());
    }

    const members = this.rooms.get(room);
    const previous = members.get(participant.participantUuid);

    members.set(participant.participantUuid, { socket, participant });

    return previous && previous.socket !== socket ? previous.socket : null;
  }

  leave(room, participantUuid, socket) {
    const members = this.rooms.get(room);
    if (!members) return;

    // Only remove the entry if it is still *this* socket: a reconnect that
    // already replaced it must not be torn down by the old socket's close
    // event arriving afterwards.
    const current = members.get(participantUuid);
    if (current && current.socket === socket) {
      members.delete(participantUuid);
    }

    if (members.size === 0) {
      this.rooms.delete(room);
    }
  }

  /** @returns {Array<{socket: import('ws').WebSocket, participant: object}>} */
  members(room) {
    return [...(this.rooms.get(room)?.values() ?? [])];
  }

  member(room, participantUuid) {
    return this.rooms.get(room)?.get(participantUuid) ?? null;
  }

  peers(room, exceptParticipantUuid) {
    return this.members(room).filter((m) => m.participant.participantUuid !== exceptParticipantUuid);
  }

  get roomCount() {
    return this.rooms.size;
  }

  get connectionCount() {
    let total = 0;
    for (const members of this.rooms.values()) total += members.size;

    return total;
  }
}
