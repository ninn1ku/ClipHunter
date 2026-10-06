/**
 * Playback state of a room and the pure math around it.
 *
 * The server stores a reference point (position at updatedAt) instead of ticking a clock: the
 * current position of a playing room is positionSec + (now - updatedAt) / 1000 × rate.
 */

export type PlaybackStatus = 'playing' | 'paused';
export type PlaybackCommand = 'play' | 'pause' | 'seek';

export interface Playback {
  status: PlaybackStatus;
  positionSec: number;
  /** Server time (ms) at which positionSec was true. */
  updatedAt: number;
  rate: number;
  /** Monotonic per room; clients ignore states with a seq they have already applied. */
  seq: number;
  /** Participant id of whoever caused this state, null for the system. */
  by: string | null;
}

/** Without a known duration a position is still bounded (a day). */
export const MAX_POSITION_SEC = 86_400;
/** Players report a little past the end; the server accepts that slack. */
export const DURATION_SLACK_SEC = 5;

export function initialPlayback(now: number, startSec = 0, seq = 0): Playback {
  return { status: 'paused', positionSec: startSec, updatedAt: now, rate: 1, seq, by: null };
}

/** Where the room is right now. */
export function currentPosition(playback: Playback, now: number, durationSec: number | null): number {
  const elapsed = playback.status === 'playing'
    ? Math.max(0, now - playback.updatedAt) / 1000 * playback.rate
    : 0;
  const position = playback.positionSec + elapsed;

  return durationSec === null ? Math.min(position, MAX_POSITION_SEC) : Math.min(position, durationSec);
}

/** A validated position, or null: finite, >= 0, <= duration + slack (or a day when unknown). */
export function validPosition(value: unknown, durationSec: number | null): number | null {
  if (typeof value !== 'number' || !Number.isFinite(value) || value < 0) {
    return null;
  }
  const max = durationSec === null ? MAX_POSITION_SEC : durationSec + DURATION_SLACK_SEC;
  if (value > max) {
    return null;
  }

  // Millisecond precision is plenty and keeps the JSON small.
  return Math.round(value * 1000) / 1000;
}

/** Applies a participant's command. The client's position wins (last writer wins). */
export function applyCommand(
  playback: Playback,
  command: PlaybackCommand,
  positionSec: number,
  now: number,
  by: string,
): Playback {
  const status: PlaybackStatus = command === 'play'
    ? 'playing'
    : command === 'pause'
    ? 'paused'
    : playback.status;

  return { status, positionSec, updatedAt: now, rate: playback.rate, seq: playback.seq + 1, by };
}
