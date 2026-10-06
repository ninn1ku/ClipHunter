/**
 * WebSocket protocol v1 (docs/WATCH_PARTY_PLAN.md §5): JSON text frames of at most 4096 bytes.
 *
 * Client messages are validated by hand (no schema library): type, an optional reqId and the
 * fields each type needs. Values are checked for type and size here; their meaning (a valid name,
 * a position within the video) is checked by the domain.
 */

import type { ErrorCode } from './domain/errors.ts';
import {
  PARTICIPANT_ID_PATTERN as PARTICIPANT,
  ROOM_ID_PATTERN as ROOM,
  TOKEN_PATTERN as TOKEN,
} from './security/ids.ts';

export const PROTOCOL_VERSION = 1;
export const MAX_FRAME_BYTES = 4096;

export const CLOSE = {
  normal: 1000,
  policy: 1008,
  tooBig: 1009,
  restart: 1012,
  overloaded: 1013,
  kicked: 4001,
  replaced: 4002,
  roomClosed: 4004,
} as const;

export type ClientMessage =
  | { type: 'ping'; reqId?: string; t: number }
  | { type: 'room.peek'; reqId?: string; roomId: string }
  | { type: 'room.create'; reqId?: string; name: unknown; ticket: string | null }
  | { type: 'room.join'; reqId?: string; roomId: string; name: unknown }
  | { type: 'room.resume'; reqId?: string; roomId: string; participantId: string; token: string }
  | { type: 'room.leave'; reqId?: string }
  | { type: 'playback.play' | 'playback.pause' | 'playback.seek'; reqId?: string; positionSec: unknown }
  | { type: 'media.set'; reqId?: string; ticket: string }
  | { type: 'chat.send'; reqId?: string; text: unknown }
  | { type: 'presence.set'; reqId?: string; state: unknown }
  | { type: 'participant.kick' | 'host.transfer'; reqId?: string; participantId: string }
  | { type: 'participant.rename'; reqId?: string; name: unknown };

export type ClientMessageType = ClientMessage['type'];

export type ParseResult = { ok: true; message: ClientMessage } | {
  ok: false;
  code: Extract<ErrorCode, 'BAD_MESSAGE' | 'UNKNOWN_TYPE'>;
  reqId?: string;
};

const TYPES: ReadonlySet<string> = new Set<ClientMessageType>([
  'ping',
  'room.peek',
  'room.create',
  'room.join',
  'room.resume',
  'room.leave',
  'playback.play',
  'playback.pause',
  'playback.seek',
  'media.set',
  'chat.send',
  'presence.set',
  'participant.kick',
  'host.transfer',
  'participant.rename',
]);

/** Types allowed before joining a room. Everything else needs a room. */
export const LOBBY_TYPES: ReadonlySet<ClientMessageType> = new Set([
  'ping',
  'room.peek',
  'room.create',
  'room.join',
  'room.resume',
]);

/** UTF-8 size of a string without allocating a buffer. */
export function utf8Length(text: string): number {
  let bytes = 0;
  for (let i = 0; i < text.length; i++) {
    const unit = text.charCodeAt(i);
    if (unit < 0x80) {
      bytes += 1;
    } else if (unit < 0x800) {
      bytes += 2;
    } else if (unit >= 0xd800 && unit <= 0xdbff && i + 1 < text.length) {
      bytes += 4;
      i++;
    } else {
      bytes += 3;
    }
  }

  return bytes;
}

export function parseClientMessage(raw: string): ParseResult {
  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    return { ok: false, code: 'BAD_MESSAGE' };
  }
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    return { ok: false, code: 'BAD_MESSAGE' };
  }
  const d = data as Record<string, unknown>;

  let reqId: string | undefined;
  if (d.reqId !== undefined) {
    if (typeof d.reqId !== 'string' || !/^[A-Za-z0-9_-]{1,16}$/.test(d.reqId)) {
      return { ok: false, code: 'BAD_MESSAGE' };
    }
    reqId = d.reqId;
  }
  const bad = (): ParseResult => (reqId === undefined ? { ok: false, code: 'BAD_MESSAGE' } : {
    ok: false,
    code: 'BAD_MESSAGE',
    reqId,
  });

  if (typeof d.type !== 'string' || !TYPES.has(d.type)) {
    return reqId === undefined
      ? { ok: false, code: 'UNKNOWN_TYPE' }
      : { ok: false, code: 'UNKNOWN_TYPE', reqId };
  }
  const type = d.type as ClientMessageType;
  const base = reqId === undefined ? {} : { reqId };
  const id = (value: unknown, pattern: RegExp): value is string =>
    typeof value === 'string' && pattern.test(value);

  switch (type) {
    case 'ping':
      return typeof d.t === 'number' && Number.isFinite(d.t) ? ok({ ...base, type, t: d.t }) : bad();
    case 'room.peek':
      return id(d.roomId, ROOM) ? ok({ ...base, type, roomId: d.roomId }) : bad();
    case 'room.create':
      if (d.ticket !== undefined && d.ticket !== null && typeof d.ticket !== 'string') {
        return bad();
      }
      return ok({ ...base, type, name: d.name, ticket: typeof d.ticket === 'string' ? d.ticket : null });
    case 'room.join':
      return id(d.roomId, ROOM) ? ok({ ...base, type, roomId: d.roomId, name: d.name }) : bad();
    case 'room.resume':
      return id(d.roomId, ROOM) && id(d.participantId, PARTICIPANT) && id(d.token, TOKEN)
        ? ok({ ...base, type, roomId: d.roomId, participantId: d.participantId, token: d.token })
        : bad();
    case 'room.leave':
      return ok({ ...base, type });
    case 'playback.play':
    case 'playback.pause':
    case 'playback.seek':
      return ok({ ...base, type, positionSec: d.positionSec });
    case 'media.set':
      return typeof d.ticket === 'string' ? ok({ ...base, type, ticket: d.ticket }) : bad();
    case 'chat.send':
      return ok({ ...base, type, text: d.text });
    case 'presence.set':
      return ok({ ...base, type, state: d.state });
    case 'participant.kick':
    case 'host.transfer':
      return id(d.participantId, PARTICIPANT) ? ok({ ...base, type, participantId: d.participantId }) : bad();
    case 'participant.rename':
      return ok({ ...base, type, name: d.name });
  }
}

function ok(message: ClientMessage): ParseResult {
  return { ok: true, message };
}
