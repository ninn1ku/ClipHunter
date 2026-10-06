/**
 * One WebSocket session: frame size and rate limits, parsing, and dispatch to the room.
 *
 * Messages of a connection are handled strictly in order (ticket verification is async, so a
 * promise queue serializes them). Three violations in a row (flood, garbage) close the socket.
 */

import type { Clock } from './clock.ts';
import type { Config } from './config.ts';
import type { ErrorCode } from './domain/errors.ts';
import type { RoomRegistry } from './domain/registry.ts';
import type { Room, RoomEvent } from './domain/room.ts';
import { sanitizeName } from './domain/text.ts';
import { type Logger, roomTag } from './log.ts';
import {
  type ClientMessage,
  CLOSE,
  LOBBY_TYPES,
  MAX_FRAME_BYTES,
  parseClientMessage,
  PROTOCOL_VERSION,
  utf8Length,
} from './protocol.ts';
import { type KeyedWindowLimiter, TokenBucket } from './security/rate-limit.ts';
import type { TicketMedia, TicketVerifier } from './security/ticket.ts';

/** What a connection needs from the gateway. */
export interface Hub {
  readonly config: Config;
  readonly log: Logger;
  readonly clock: Clock;
  readonly registry: RoomRegistry;
  readonly tickets: TicketVerifier;
  readonly createLimiter: KeyedWindowLimiter;
  readonly joinLimiter: KeyedWindowLimiter;
  /** Binds the connection to a participant; returns the connection it replaces, if any. */
  attach(connection: Connection, room: Room, participantId: string): Connection | undefined;
  detach(connection: Connection): void;
  connectionOf(roomId: string, participantId: string): Connection | undefined;
  broadcast(room: Room, events: RoomEvent[], except?: Connection): void;
  closed(connection: Connection, code: number): void;
}

const MAX_VIOLATIONS = 3;
/** A client that does not read its socket must not make us buffer without limit. */
const MAX_BUFFERED_BYTES = 1024 * 1024;

type Reply = Record<string, unknown>;

export class Connection {
  room: Room | null = null;
  participantId: string | null = null;
  /** The room this connection was last in, for the close log line (survives detach). */
  lastRoomId: string | null = null;
  readonly openedAt: number;
  private closing = false;
  private violations = 0;
  private queue: Promise<void> = Promise.resolve();
  private readonly limits: Record<'all' | 'playback' | 'chat' | 'presence', TokenBucket>;

  constructor(
    private readonly socket: WebSocket,
    readonly ipHash: string,
    private readonly hub: Hub,
  ) {
    this.openedAt = hub.clock.now();
    this.limits = {
      all: new TokenBucket(30, 60, hub.clock),
      playback: new TokenBucket(4, 10, hub.clock),
      chat: new TokenBucket(1, 5, hub.clock),
      presence: new TokenBucket(2, 4, hub.clock),
    };
    socket.onmessage = (event) => this.receive(event.data);
    socket.onclose = (event) => hub.closed(this, event.code);
    socket.onerror = () => {
      // A close event always follows.
    };
  }

  get isClosing(): boolean {
    return this.closing;
  }

  receive(data: unknown): void {
    if (this.closing) {
      return;
    }
    if (typeof data !== 'string') {
      this.close(CLOSE.policy, 'binary frames are not part of the protocol');
      return;
    }
    if (
      data.length > MAX_FRAME_BYTES ||
      (data.length > MAX_FRAME_BYTES / 4 && utf8Length(data) > MAX_FRAME_BYTES)
    ) {
      this.hub.log.warning('limit.hit', { limit: 'frame_size', ip_hash: this.ipHash });
      this.close(CLOSE.tooBig, 'frame too large');
      return;
    }
    if (!this.limits.all.take()) {
      this.violation(undefined, 'RATE_LIMITED', 'messages');
      return;
    }

    const parsed = parseClientMessage(data);
    if (!parsed.ok) {
      this.violation(parsed.reqId, parsed.code);
      return;
    }
    const message = parsed.message;
    this.queue = this.queue
      .then(() => this.dispatch(message))
      .catch((e: unknown) => {
        this.hub.log.error('ws.handler_failed', {
          type: message.type,
          error: e instanceof Error ? `${e.name}: ${e.message}` : 'unknown',
        });
        this.error(message.reqId, 'INTERNAL_ERROR');
      });
  }

  send(reply: Reply): void {
    this.sendRaw(JSON.stringify(reply));
  }

  sendRaw(json: string): void {
    if (this.closing || this.socket.readyState !== WebSocket.OPEN) {
      return;
    }
    if (this.socket.bufferedAmount > MAX_BUFFERED_BYTES) {
      this.hub.log.warning('limit.hit', { limit: 'buffered', ip_hash: this.ipHash });
      this.close(CLOSE.policy, 'not reading');
      return;
    }
    this.socket.send(json);
  }

  close(code: number, reason = ''): void {
    if (this.closing) {
      return;
    }
    this.closing = true;
    try {
      this.socket.close(code, reason);
    } catch {
      // Already closed.
    }
  }

  private async dispatch(m: ClientMessage): Promise<void> {
    if (this.closing) {
      return;
    }
    const inLobby = LOBBY_TYPES.has(m.type);
    if (!inLobby && (this.room === null || this.participantId === null)) {
      this.error(m.reqId, 'NOT_IN_ROOM');
      return;
    }
    if (inLobby && m.type !== 'ping' && this.room !== null) {
      this.error(m.reqId, 'ALREADY_IN_ROOM');
      return;
    }

    switch (m.type) {
      case 'ping':
        this.send({ type: 'pong', ...req(m.reqId), t: m.t, serverTime: this.hub.clock.now() });
        return;
      case 'room.peek':
        return this.peek(m.reqId, m.roomId);
      case 'room.create':
        return await this.create(m.reqId, m.name, m.ticket);
      case 'room.join':
        return this.join(m.reqId, m.roomId, m.name);
      case 'room.resume':
        return this.resume(m.reqId, m.roomId, m.participantId, m.token);
    }

    // In a room from here on.
    const room = this.room as Room;
    const me = this.participantId as string;
    const now = this.hub.clock.now();

    switch (m.type) {
      case 'room.leave': {
        const events = room.leave(me, now);
        this.hub.detach(this);
        this.hub.broadcast(room, events);
        this.hub.log.info('room.left', { room: roomTag(room.id), reason: 'left' });
        this.ack(m.reqId);
        this.close(CLOSE.normal, 'left');
        return;
      }
      case 'playback.play':
      case 'playback.pause':
      case 'playback.seek': {
        if (!this.limits.playback.take()) {
          return this.violation(m.reqId, 'RATE_LIMITED', 'playback');
        }
        const command = m.type === 'playback.play' ? 'play' : m.type === 'playback.pause' ? 'pause' : 'seek';
        return this.apply(m.reqId, room, room.command(me, command, m.positionSec, now));
      }
      case 'media.set': {
        const media = await this.verifyTicket(m.reqId, m.ticket);
        if (media === null || this.closing || this.room !== room) {
          return;
        }
        const result = room.setMedia(me, media, this.hub.clock.now());
        if (result.ok) {
          this.hub.log.info('media.set', {
            room: roomTag(room.id),
            kind: media.kind,
            platform: media.platform,
          });
        }
        return this.apply(m.reqId, room, result);
      }
      case 'chat.send':
        if (!this.limits.chat.take()) {
          return this.violation(m.reqId, 'RATE_LIMITED', 'chat');
        }
        return this.apply(m.reqId, room, room.chatMessage(me, m.text, now));
      case 'presence.set':
        if (!this.limits.presence.take()) {
          return this.violation(m.reqId, 'RATE_LIMITED', 'presence');
        }
        return this.apply(m.reqId, room, room.setPresence(me, m.state));
      case 'participant.rename':
        return this.apply(m.reqId, room, room.rename(me, m.name));
      case 'host.transfer':
        return this.apply(m.reqId, room, room.transferHost(me, m.participantId, now));
      case 'participant.kick': {
        const result = room.kick(me, m.participantId, now);
        if (!result.ok) {
          return this.error(m.reqId, result.code);
        }
        const target = this.hub.connectionOf(room.id, result.kickedId);
        if (target !== undefined) {
          this.hub.detach(target);
          target.send({ type: 'kicked' });
          target.close(CLOSE.kicked, 'kicked');
        }
        this.hub.log.info('room.left', { room: roomTag(room.id), reason: 'kicked' });

        return this.apply(m.reqId, room, result);
      }
    }
  }

  private peek(reqId: string | undefined, roomId: string): void {
    if (!this.hub.joinLimiter.hit(this.ipHash)) {
      return this.violation(reqId, 'RATE_LIMITED', 'rate_join');
    }
    const room = this.hub.registry.get(roomId);
    if (room === undefined) {
      return this.error(reqId, 'ROOM_NOT_FOUND');
    }
    this.violations = 0;
    this.send({
      type: 'room.info',
      ...req(reqId),
      roomId: room.id,
      participants: room.size,
      capacity: room.capacity,
      hasMedia: room.media !== null,
    });
  }

  private async create(reqId: string | undefined, name: unknown, ticket: string | null): Promise<void> {
    if (!this.hub.createLimiter.hit(this.ipHash)) {
      return this.violation(reqId, 'RATE_LIMITED', 'rate_create');
    }
    if (sanitizeName(name) === null) {
      return this.error(reqId, 'NAME_INVALID');
    }
    let media: TicketMedia | null = null;
    if (ticket !== null) {
      media = await this.verifyTicket(reqId, ticket);
      if (media === null) {
        return;
      }
    }
    if (this.closing || this.room !== null) {
      return;
    }

    const room = this.hub.registry.create();
    if (room === null) {
      this.hub.log.warning('limit.hit', { limit: 'max_rooms', ip_hash: this.ipHash });
      return this.error(reqId, 'ROOM_LIMIT');
    }
    const now = this.hub.clock.now();
    const joined = room.join(name, this.ipHash, now);
    if (!joined.ok) {
      this.hub.registry.delete(room.id);
      return this.error(reqId, joined.code);
    }
    if (media !== null) {
      room.setMedia(joined.participant.id, media, now);
    }
    this.enter(room, joined.participant.id);
    this.welcome(reqId, room, joined.participant.id, joined.token);
    this.hub.log.info('room.created', {
      room: roomTag(room.id),
      ip_hash: this.ipHash,
      media: media?.kind ?? null,
      platform: media?.platform ?? null,
    });
  }

  private join(reqId: string | undefined, roomId: string, name: unknown): void {
    if (!this.hub.joinLimiter.hit(this.ipHash)) {
      return this.violation(reqId, 'RATE_LIMITED', 'rate_join');
    }
    const room = this.hub.registry.get(roomId);
    if (room === undefined) {
      return this.error(reqId, 'ROOM_NOT_FOUND');
    }
    const joined = room.join(name, this.ipHash, this.hub.clock.now());
    if (!joined.ok) {
      if (joined.code === 'ROOM_FULL') {
        this.hub.log.info('room.full', { room: roomTag(room.id), ip_hash: this.ipHash });
      }
      return this.error(reqId, joined.code);
    }
    this.enter(room, joined.participant.id);
    this.welcome(reqId, room, joined.participant.id, joined.token);
    this.hub.broadcast(room, joined.events, this);
    this.hub.log.info('room.joined', { room: roomTag(room.id), ip_hash: this.ipHash, size: room.size });
  }

  private resume(reqId: string | undefined, roomId: string, participantId: string, token: string): void {
    if (!this.hub.joinLimiter.hit(this.ipHash)) {
      return this.violation(reqId, 'RATE_LIMITED', 'rate_join');
    }
    const room = this.hub.registry.get(roomId);
    if (room === undefined) {
      return this.error(reqId, 'ROOM_NOT_FOUND');
    }
    const resumed = room.resume(participantId, token);
    if (!resumed.ok) {
      return this.error(reqId, resumed.code);
    }
    this.enter(room, participantId);
    this.welcome(reqId, room, participantId, null);
    this.hub.broadcast(room, resumed.events, this);
    this.hub.log.info('room.resumed', {
      room: roomTag(room.id),
      ip_hash: this.ipHash,
      replaced: resumed.replaced,
    });
  }

  private enter(room: Room, participantId: string): void {
    this.room = room;
    this.participantId = participantId;
    this.lastRoomId = room.id;
    this.violations = 0;
    const replaced = this.hub.attach(this, room, participantId);
    if (replaced !== undefined && replaced !== this) {
      replaced.room = null;
      replaced.participantId = null;
      replaced.close(CLOSE.replaced, 'opened elsewhere');
    }
  }

  private welcome(reqId: string | undefined, room: Room, participantId: string, token: string | null): void {
    this.send({
      type: 'welcome',
      ...req(reqId),
      you: { participantId, ...(token === null ? {} : { token }), isHost: room.isHost(participantId) },
      room: room.snapshot(),
      serverTime: this.hub.clock.now(),
      protocol: PROTOCOL_VERSION,
    });
  }

  private async verifyTicket(reqId: string | undefined, ticket: string): Promise<TicketMedia | null> {
    const result = await this.hub.tickets.verify(ticket, Math.floor(this.hub.clock.now() / 1000));
    if (!result.ok) {
      this.hub.log.warning('ticket.invalid', { reason: result.reason, ip_hash: this.ipHash });
      this.error(reqId, result.code);

      return null;
    }

    return result.media;
  }

  private apply(
    reqId: string | undefined,
    room: Room,
    result: { ok: true; events: RoomEvent[] } | { ok: false; code: ErrorCode },
  ): void {
    if (!result.ok) {
      return this.error(reqId, result.code);
    }
    this.violations = 0;
    this.hub.broadcast(room, result.events);
    this.ack(reqId);
  }

  private ack(reqId: string | undefined): void {
    if (reqId !== undefined) {
      this.send({ type: 'ack', reqId });
    }
  }

  private error(reqId: string | undefined, code: ErrorCode): void {
    this.send({ type: 'error', ...req(reqId), code });
  }

  private violation(reqId: string | undefined, code: ErrorCode, limit?: string): void {
    this.violations++;
    if (limit !== undefined) {
      this.hub.log.info('limit.hit', { limit, ip_hash: this.ipHash });
    }
    this.error(reqId, code);
    if (this.violations >= MAX_VIOLATIONS) {
      this.hub.log.warning('ws.flood', { ip_hash: this.ipHash, code });
      this.close(CLOSE.policy, 'protocol violation');
    }
  }
}

function req(reqId: string | undefined): { reqId?: string } {
  return reqId === undefined ? {} : { reqId };
}
