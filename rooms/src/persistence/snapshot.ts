/**
 * State snapshots: storage/rooms/state.json, written atomically (temp file + rename, mode 0640).
 *
 * The file holds token hashes, never tokens. A snapshot with an unknown version, or a room that
 * does not parse, is skipped with a warning: losing rooms on an upgrade is acceptable, crashing
 * the service on startup is not.
 */

import type { Logger } from '../log.ts';
import type { ChatMessage, Media, Participant, RoomState } from '../domain/room.ts';
import type { Playback } from '../domain/playback.ts';
import { PARTICIPANT_ID_PATTERN, ROOM_ID_PATTERN } from '../security/ids.ts';
import { isMediaKind, isValidRef } from '../security/ticket.ts';

export const SNAPSHOT_VERSION = 1;
export const SNAPSHOT_FILE = 'state.json';

interface SnapshotFile {
  version: number;
  savedAt: number;
  rooms: RoomState[];
}

export async function writeSnapshot(dir: string, rooms: RoomState[], now: number): Promise<void> {
  const file: SnapshotFile = { version: SNAPSHOT_VERSION, savedAt: now, rooms };
  const target = `${dir}/${SNAPSHOT_FILE}`;
  const tmp = `${target}.tmp`;

  await Deno.writeTextFile(tmp, JSON.stringify(file), { mode: 0o640 });
  if (Deno.build.os !== 'windows') {
    await Deno.chmod(tmp, 0o640);
  }
  await Deno.rename(tmp, target);
}

/** The rooms of the last snapshot; [] when there is none or it cannot be used. */
export async function readSnapshot(dir: string, log: Logger): Promise<RoomState[]> {
  let raw: string;
  try {
    raw = await Deno.readTextFile(`${dir}/${SNAPSHOT_FILE}`);
  } catch (e) {
    if (!(e instanceof Deno.errors.NotFound)) {
      log.warning('snapshot.unreadable', { error: e instanceof Error ? e.name : 'unknown' });
    }

    return [];
  }

  let data: unknown;
  try {
    data = JSON.parse(raw);
  } catch {
    log.warning('snapshot.invalid', { reason: 'json' });

    return [];
  }
  if (!isObject(data) || data.version !== SNAPSHOT_VERSION || !Array.isArray(data.rooms)) {
    log.warning('snapshot.invalid', {
      reason: 'version',
      version: isObject(data) ? String(data.version) : null,
    });

    return [];
  }

  const rooms: RoomState[] = [];
  let skipped = 0;
  for (const room of data.rooms) {
    const parsed = parseRoom(room);
    if (parsed === null) {
      skipped++;
    } else {
      rooms.push(parsed);
    }
  }
  if (skipped > 0) {
    log.warning('snapshot.rooms_skipped', { count: skipped });
  }

  return rooms;
}

function parseRoom(v: unknown): RoomState | null {
  if (!isObject(v) || typeof v.id !== 'string' || !ROOM_ID_PATTERN.test(v.id)) {
    return null;
  }
  if (!isInt(v.createdAt) || !isInt(v.capacity) || v.capacity < 1 || v.capacity > 5 || !isInt(v.chatSeq)) {
    return null;
  }
  if (!Array.isArray(v.participants) || !Array.isArray(v.chat) || !Array.isArray(v.bans)) {
    return null;
  }
  const participants = v.participants.map(parseParticipant);
  const playback = parsePlayback(v.playback);
  const media = v.media === null ? null : parseMedia(v.media);
  const chat = v.chat.map(parseChat);
  if (participants.includes(null) || playback === null || media === undefined || chat.includes(null)) {
    return null;
  }
  const bans: Array<[string, number]> = [];
  for (const ban of v.bans) {
    if (Array.isArray(ban) && typeof ban[0] === 'string' && /^[a-f0-9]{16}$/.test(ban[0]) && isInt(ban[1])) {
      bans.push([ban[0], ban[1]]);
    }
  }

  return {
    id: v.id,
    createdAt: v.createdAt,
    capacity: v.capacity,
    hostId: typeof v.hostId === 'string' ? v.hostId : null,
    participants: participants as Participant[],
    media,
    playback,
    chat: chat as ChatMessage[],
    chatSeq: v.chatSeq,
    bans,
    emptySince: isInt(v.emptySince) ? v.emptySince : null,
  };
}

function parseParticipant(v: unknown): Participant | null {
  if (!isObject(v) || typeof v.id !== 'string' || !PARTICIPANT_ID_PATTERN.test(v.id)) {
    return null;
  }
  if (typeof v.name !== 'string' || typeof v.key !== 'string' || !isInt(v.color) || !isInt(v.joinedAt)) {
    return null;
  }
  if (typeof v.tokenHash !== 'string' || !/^[a-f0-9]{64}$/.test(v.tokenHash)) {
    return null;
  }
  if (typeof v.ipHash !== 'string' || !['watching', 'buffering', 'idle'].includes(String(v.status))) {
    return null;
  }

  return {
    id: v.id,
    name: v.name,
    key: v.key,
    color: v.color,
    tokenHash: v.tokenHash,
    ipHash: v.ipHash,
    joinedAt: v.joinedAt,
    connected: false,
    disconnectedAt: null,
    status: v.status as Participant['status'],
  };
}

function parsePlayback(v: unknown): Playback | null {
  if (!isObject(v) || (v.status !== 'playing' && v.status !== 'paused')) {
    return null;
  }
  if (!isFiniteNumber(v.positionSec) || !isInt(v.updatedAt) || !isFiniteNumber(v.rate) || !isInt(v.seq)) {
    return null;
  }

  return {
    status: v.status,
    positionSec: v.positionSec,
    updatedAt: v.updatedAt,
    rate: v.rate,
    seq: v.seq,
    by: typeof v.by === 'string' ? v.by : null,
  };
}

/** undefined when invalid (null is a valid "no media"). */
function parseMedia(v: unknown): Media | undefined {
  if (!isObject(v) || !isMediaKind(v.kind) || !isValidRef(v.kind, v.ref)) {
    return undefined;
  }
  if (typeof v.platform !== 'string' || !isInt(v.startSec) || !isInt(v.setAt)) {
    return undefined;
  }

  return {
    kind: v.kind,
    ref: v.ref,
    platform: v.platform,
    title: typeof v.title === 'string' ? v.title : null,
    durationSec: isInt(v.durationSec) ? v.durationSec : null,
    thumbnailUrl: typeof v.thumbnailUrl === 'string' && v.thumbnailUrl.startsWith('https://')
      ? v.thumbnailUrl
      : null,
    startSec: v.startSec,
    setAt: v.setAt,
  };
}

function parseChat(v: unknown): ChatMessage | null {
  if (!isObject(v) || typeof v.id !== 'string' || (v.kind !== 'user' && v.kind !== 'system')) {
    return null;
  }
  if (typeof v.participantId !== 'string' || typeof v.name !== 'string' || !isInt(v.color) || !isInt(v.ts)) {
    return null;
  }
  const message: ChatMessage = {
    id: v.id,
    kind: v.kind,
    participantId: v.participantId,
    name: v.name,
    color: v.color,
    ts: v.ts,
  };
  if (typeof v.text === 'string' || v.text === null) {
    message.text = v.text;
  }
  if (typeof v.event === 'string' && ['joined', 'left', 'kicked', 'media', 'host'].includes(v.event)) {
    message.event = v.event as ChatMessage['event'];
  }

  return message;
}

function isObject(v: unknown): v is Record<string, unknown> {
  return typeof v === 'object' && v !== null && !Array.isArray(v);
}

function isInt(v: unknown): v is number {
  return typeof v === 'number' && Number.isSafeInteger(v);
}

function isFiniteNumber(v: unknown): v is number {
  return typeof v === 'number' && Number.isFinite(v);
}
