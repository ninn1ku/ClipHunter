/**
 * The Room aggregate: participants, host, media, playback and chat.
 *
 * Methods are synchronous and never touch the network: they validate, mutate and return the
 * events to broadcast. The connection layer decides who receives them and handles sockets.
 */

import { newParticipantId, newToken, sha256Hex, tokenMatches } from '../security/ids.ts';
import type { TicketMedia } from '../security/ticket.ts';
import { type ErrorCode, fail, type Failure } from './errors.ts';
import {
  applyCommand,
  initialPlayback,
  type Playback,
  type PlaybackCommand,
  validPosition,
} from './playback.ts';
import { nameKey, sanitizeChat, sanitizeName } from './text.ts';

export const AVATAR_COLORS = 8;
export const CHAT_HISTORY = 100;
export const CHAT_IN_WELCOME = 50;
export const KICK_BAN_MS = 10 * 60_000;

export type PresenceState = 'watching' | 'buffering' | 'idle';
export type LeaveReason = 'left' | 'timeout' | 'kicked';
export type SystemEvent = 'joined' | 'left' | 'kicked' | 'media' | 'host';

const PRESENCE_STATES: ReadonlySet<string> = new Set(['watching', 'buffering', 'idle']);

export interface Participant {
  id: string;
  name: string;
  /** Uniqueness key of the name, see {@link nameKey}. */
  key: string;
  color: number;
  /** SHA-256 hex of the reconnect token. The token itself is never stored. */
  tokenHash: string;
  ipHash: string;
  joinedAt: number;
  connected: boolean;
  disconnectedAt: number | null;
  status: PresenceState;
}

export interface PublicParticipant {
  id: string;
  name: string;
  color: number;
  isHost: boolean;
  connected: boolean;
  status: PresenceState;
  joinedAt: number;
}

export interface Media extends TicketMedia {
  setAt: number;
}

export interface ChatMessage {
  id: string;
  kind: 'user' | 'system';
  participantId: string;
  name: string;
  color: number;
  text?: string | null;
  event?: SystemEvent;
  ts: number;
}

export type RoomEvent =
  | { type: 'participant.joined'; participant: PublicParticipant }
  | { type: 'participant.updated'; participant: PublicParticipant }
  | { type: 'participant.left'; participantId: string; reason: LeaveReason }
  | { type: 'media.changed'; media: Media; playback: Playback }
  | { type: 'playback.state'; playback: Playback }
  | { type: 'chat.message'; message: ChatMessage };

export interface Success {
  ok: true;
  events: RoomEvent[];
}

export type Result<T = Record<never, never>> = (Success & T) | Failure;

export interface RoomSnapshot {
  roomId: string;
  capacity: number;
  hostId: string | null;
  participants: PublicParticipant[];
  media: Media | null;
  playback: Playback;
  chat: ChatMessage[];
}

/** What the snapshot file stores for one room (no raw tokens). */
export interface RoomState {
  id: string;
  createdAt: number;
  capacity: number;
  hostId: string | null;
  participants: Participant[];
  media: Media | null;
  playback: Playback;
  chat: ChatMessage[];
  chatSeq: number;
  bans: Array<[string, number]>;
  emptySince: number | null;
}

export class Room {
  /** Insertion order is join order: the earliest participant comes first. */
  readonly participants = new Map<string, Participant>();
  hostId: string | null = null;
  media: Media | null = null;
  playback: Playback;
  chat: ChatMessage[] = [];
  /** Since when the room has had no participants at all (null while anyone is in it, even in grace). */
  emptySince: number | null;
  private chatSeq = 0;
  private readonly bans = new Map<string, number>();

  constructor(
    readonly id: string,
    readonly createdAt: number,
    readonly capacity: number,
  ) {
    this.playback = initialPlayback(createdAt);
    this.emptySince = createdAt;
  }

  get size(): number {
    return this.participants.size;
  }

  connectedCount(): number {
    let n = 0;
    for (const p of this.participants.values()) {
      n += p.connected ? 1 : 0;
    }

    return n;
  }

  isHost(participantId: string): boolean {
    return this.hostId === participantId;
  }

  /** Whether joining is possible at all (for room.peek and early rejection). */
  admissionError(ipHash: string, now: number): ErrorCode | null {
    const bannedUntil = this.bans.get(ipHash);
    if (bannedUntil !== undefined && bannedUntil > now) {
      return 'KICKED';
    }

    // A participant in the reconnect grace period still holds a seat.
    return this.participants.size >= this.capacity ? 'ROOM_FULL' : null;
  }

  join(nameInput: unknown, ipHash: string, now: number): Result<{ participant: Participant; token: string }> {
    const admission = this.admissionError(ipHash, now);
    if (admission !== null) {
      return fail(admission);
    }
    const name = sanitizeName(nameInput);
    if (name === null) {
      return fail('NAME_INVALID');
    }
    const key = nameKey(name);
    if (this.nameTaken(key, null)) {
      return fail('NAME_TAKEN');
    }

    let id = newParticipantId();
    while (this.participants.has(id)) {
      id = newParticipantId();
    }
    const token = newToken();
    const participant: Participant = {
      id,
      name,
      key,
      color: this.freeColor(),
      tokenHash: sha256Hex(token),
      ipHash,
      joinedAt: now,
      connected: true,
      disconnectedAt: null,
      status: 'idle',
    };
    this.participants.set(id, participant);
    this.emptySince = null;
    this.hostId ??= id;

    return {
      ok: true,
      participant,
      token,
      events: [
        { type: 'participant.joined', participant: this.publicParticipant(participant) },
        this.system('joined', participant, now),
      ],
    };
  }

  /** Reconnect with the token from join. `replaced` means another socket held this identity. */
  resume(participantId: unknown, token: unknown): Result<{ participant: Participant; replaced: boolean }> {
    const participant = typeof participantId === 'string' ? this.participants.get(participantId) : undefined;
    if (participant === undefined || !tokenMatches(token, participant.tokenHash)) {
      return fail('RESUME_FAILED');
    }
    const replaced = participant.connected;
    participant.connected = true;
    participant.disconnectedAt = null;

    return {
      ok: true,
      participant,
      replaced,
      events: replaced
        ? []
        : [{ type: 'participant.updated', participant: this.publicParticipant(participant) }],
    };
  }

  /** The socket dropped: the participant keeps the seat for the reconnect grace period. */
  disconnect(participantId: string, now: number): RoomEvent[] {
    const participant = this.participants.get(participantId);
    if (participant === undefined || !participant.connected) {
      return [];
    }
    participant.connected = false;
    participant.disconnectedAt = now;

    return [{ type: 'participant.updated', participant: this.publicParticipant(participant) }];
  }

  leave(participantId: string, now: number): RoomEvent[] {
    const participant = this.participants.get(participantId);

    return participant === undefined ? [] : this.remove(participant, 'left', now);
  }

  /** Removes participants whose reconnect grace has run out. */
  expireGrace(now: number, graceMs: number): { removed: string[]; events: RoomEvent[] } {
    const removed: string[] = [];
    const events: RoomEvent[] = [];
    for (const participant of [...this.participants.values()]) {
      if (
        !participant.connected && participant.disconnectedAt !== null &&
        participant.disconnectedAt + graceMs <= now
      ) {
        removed.push(participant.id);
        events.push(...this.remove(participant, 'timeout', now));
      }
    }

    return { removed, events };
  }

  kick(byId: string, targetId: unknown, now: number): Result<{ kickedId: string }> {
    if (!this.isHost(byId)) {
      return fail('FORBIDDEN');
    }
    const target = typeof targetId === 'string' ? this.participants.get(targetId) : undefined;
    if (target === undefined || target.id === byId) {
      return fail('BAD_MESSAGE');
    }
    this.bans.set(target.ipHash, now + KICK_BAN_MS);

    return { ok: true, kickedId: target.id, events: this.remove(target, 'kicked', now) };
  }

  transferHost(byId: string, targetId: unknown, now: number): Result {
    if (!this.isHost(byId)) {
      return fail('FORBIDDEN');
    }
    const target = typeof targetId === 'string' ? this.participants.get(targetId) : undefined;
    const current = this.participants.get(byId);
    if (target === undefined || current === undefined || target.id === byId) {
      return fail('BAD_MESSAGE');
    }
    this.hostId = target.id;

    return {
      ok: true,
      events: [
        { type: 'participant.updated', participant: this.publicParticipant(current) },
        { type: 'participant.updated', participant: this.publicParticipant(target) },
        this.system('host', target, now),
      ],
    };
  }

  rename(participantId: string, nameInput: unknown): Result {
    const participant = this.participants.get(participantId);
    const name = sanitizeName(nameInput);
    if (participant === undefined || name === null) {
      return fail('NAME_INVALID');
    }
    const key = nameKey(name);
    if (this.nameTaken(key, participantId)) {
      return fail('NAME_TAKEN');
    }
    if (participant.name === name) {
      return { ok: true, events: [] };
    }
    participant.name = name;
    participant.key = key;

    return {
      ok: true,
      events: [{ type: 'participant.updated', participant: this.publicParticipant(participant) }],
    };
  }

  setPresence(participantId: string, state: unknown): Result {
    const participant = this.participants.get(participantId);
    if (participant === undefined || typeof state !== 'string' || !PRESENCE_STATES.has(state)) {
      return fail('BAD_MESSAGE');
    }
    if (participant.status === state) {
      return { ok: true, events: [] };
    }
    participant.status = state as PresenceState;

    return {
      ok: true,
      events: [{ type: 'participant.updated', participant: this.publicParticipant(participant) }],
    };
  }

  /** Host only. Media comes from a verified ticket; playback restarts paused at its start time. */
  setMedia(byId: string, media: TicketMedia, now: number): Result {
    const host = this.participants.get(byId);
    if (host === undefined || !this.isHost(byId)) {
      return fail('FORBIDDEN');
    }
    this.media = { ...media, setAt: now };
    this.playback = { ...initialPlayback(now, media.startSec, this.playback.seq + 1), by: byId };

    return {
      ok: true,
      events: [
        { type: 'media.changed', media: this.media, playback: this.playback },
        this.system('media', host, now, media.title),
      ],
    };
  }

  /** Any participant may play, pause and seek; the last command to arrive wins. */
  command(byId: string, command: PlaybackCommand, positionInput: unknown, now: number): Result {
    if (!this.participants.has(byId)) {
      return fail('NOT_IN_ROOM');
    }
    if (this.media === null) {
      return fail('NO_MEDIA');
    }
    const position = validPosition(positionInput, this.media.durationSec);
    if (position === null) {
      return fail('INVALID_POSITION');
    }
    this.playback = applyCommand(this.playback, command, position, now, byId);

    return { ok: true, events: [{ type: 'playback.state', playback: this.playback }] };
  }

  chatMessage(byId: string, textInput: unknown, now: number): Result {
    const participant = this.participants.get(byId);
    if (participant === undefined) {
      return fail('NOT_IN_ROOM');
    }
    const text = sanitizeChat(textInput);
    if (text === null) {
      return fail('CHAT_INVALID');
    }
    const message: ChatMessage = {
      id: String(++this.chatSeq),
      kind: 'user',
      participantId: participant.id,
      name: participant.name,
      color: participant.color,
      text,
      ts: now,
    };

    return { ok: true, events: [{ type: 'chat.message', message: this.pushChat(message) }] };
  }

  snapshot(): RoomSnapshot {
    return {
      roomId: this.id,
      capacity: this.capacity,
      hostId: this.hostId,
      participants: [...this.participants.values()].map((p) => this.publicParticipant(p)),
      media: this.media,
      playback: this.playback,
      chat: this.chat.slice(-CHAT_IN_WELCOME),
    };
  }

  publicParticipant(p: Participant): PublicParticipant {
    return {
      id: p.id,
      name: p.name,
      color: p.color,
      isHost: this.hostId === p.id,
      connected: p.connected,
      status: p.status,
      joinedAt: p.joinedAt,
    };
  }

  toState(): RoomState {
    return {
      id: this.id,
      createdAt: this.createdAt,
      capacity: this.capacity,
      hostId: this.hostId,
      participants: [...this.participants.values()].map((p) => ({ ...p })),
      media: this.media,
      playback: this.playback,
      chat: this.chat,
      chatSeq: this.chatSeq,
      bans: [...this.bans],
      emptySince: this.emptySince,
    };
  }

  /**
   * Rebuilds a room from a snapshot. Everyone starts disconnected with a fresh grace period:
   * clients reconnect on their own after a restart.
   */
  static fromState(state: RoomState, now: number): Room {
    const room = new Room(state.id, state.createdAt, state.capacity);
    for (const p of state.participants) {
      room.participants.set(p.id, { ...p, connected: false, disconnectedAt: now });
    }
    room.hostId = state.hostId !== null && room.participants.has(state.hostId) ? state.hostId : null;
    if (room.hostId === null && room.participants.size > 0) {
      room.hostId = room.participants.keys().next().value ?? null;
    }
    room.media = state.media;
    room.playback = state.playback;
    room.chat = state.chat.slice(-CHAT_HISTORY);
    room.chatSeq = state.chatSeq;
    for (const [ipHash, until] of state.bans) {
      if (until > now) {
        room.bans.set(ipHash, until);
      }
    }
    room.emptySince = room.participants.size === 0 ? (state.emptySince ?? now) : null;

    return room;
  }

  private remove(participant: Participant, reason: LeaveReason, now: number): RoomEvent[] {
    this.participants.delete(participant.id);
    const events: RoomEvent[] = [
      { type: 'participant.left', participantId: participant.id, reason },
      this.system(reason === 'kicked' ? 'kicked' : 'left', participant, now),
    ];

    if (this.hostId === participant.id) {
      this.hostId = this.successor();
      const host = this.hostId === null ? undefined : this.participants.get(this.hostId);
      if (host !== undefined) {
        events.push(
          { type: 'participant.updated', participant: this.publicParticipant(host) },
          this.system('host', host, now),
        );
      }
    }
    if (this.participants.size === 0) {
      this.emptySince = now;
    }

    return events;
  }

  /** The earliest connected participant; failing that, the earliest one still in grace. */
  private successor(): string | null {
    let fallback: string | null = null;
    for (const p of this.participants.values()) {
      if (p.connected) {
        return p.id;
      }
      fallback ??= p.id;
    }

    return fallback;
  }

  private nameTaken(key: string, exceptId: string | null): boolean {
    for (const p of this.participants.values()) {
      if (p.key === key && p.id !== exceptId) {
        return true;
      }
    }

    return false;
  }

  private freeColor(): number {
    const used = new Set([...this.participants.values()].map((p) => p.color));
    for (let color = 0; color < AVATAR_COLORS; color++) {
      if (!used.has(color)) {
        return color;
      }
    }

    return this.participants.size % AVATAR_COLORS;
  }

  private system(event: SystemEvent, p: Participant, now: number, text: string | null = null): RoomEvent {
    const message: ChatMessage = {
      id: String(++this.chatSeq),
      kind: 'system',
      participantId: p.id,
      name: p.name,
      color: p.color,
      event,
      ts: now,
    };
    if (event === 'media') {
      message.text = text;
    }

    return { type: 'chat.message', message: this.pushChat(message) };
  }

  private pushChat(message: ChatMessage): ChatMessage {
    this.chat.push(message);
    if (this.chat.length > CHAT_HISTORY) {
      this.chat.splice(0, this.chat.length - CHAT_HISTORY);
    }

    return message;
  }
}
