// Playback math for synchronisation (docs/WATCH_PARTY_PLAN.md §6.2–6.3). Pure: no DOM, no timers.

/** Drift (seconds) tolerated before any correction: inside it the room counts as in sync. */
export const IN_SYNC_SEC = 0.06;
/** HTML5: beyond this drift we seek; below it we nudge playbackRate. */
export const HTML5_SEEK_SEC = 0.5;
/** YouTube cannot change rate smoothly through the API, so it only seeks, with a wider window. */
export const YOUTUBE_SEEK_SEC = 1.0;
/** After a seek, corrections pause while the player settles. */
export const SEEK_FREEZE_MS = 1500;
/** Near the end the room counts as finished ("Смотреть заново"). */
export const END_MARGIN_SEC = 0.25;

/**
 * Where the room should be now.
 * @param {import('./store.js').Playback} playback
 * @param {number} serverNow server time in ms
 * @param {number|null} durationSec
 * @returns {number}
 */
export function expectedPosition(playback, serverNow, durationSec) {
  const elapsed = playback.status === 'playing'
    ? Math.max(0, serverNow - playback.updatedAt) / 1000 * playback.rate
    : 0;
  const position = Math.max(0, playback.positionSec + elapsed);

  return durationSec !== null && durationSec > 0 ? Math.min(position, durationSec) : position;
}

/**
 * What to do about a drift (positive = we are ahead of the room).
 *
 * @param {{drift: number, kind: 'html5'|'youtube', frozen?: boolean, rateCorrecting?: boolean}} input
 * @returns {{action: 'none'} | {action: 'seek'} | {action: 'rate', rate: number}}
 */
export function decideCorrection({ drift, kind, frozen = false, rateCorrecting = false }) {
  if (!Number.isFinite(drift) || frozen) {
    return { action: 'none' };
  }
  const size = Math.abs(drift);

  if (kind === 'youtube') {
    return size > YOUTUBE_SEEK_SEC ? { action: 'seek' } : { action: 'none' };
  }
  if (size > HTML5_SEEK_SEC) {
    return { action: 'seek' };
  }
  if (size > IN_SYNC_SEC) {
    // Ahead → slow down, behind → speed up; at most ±10 %, proportional to the drift.
    const clamped = Math.max(-0.5, Math.min(0.5, drift));
    return { action: 'rate', rate: Math.round((1 - clamped * 0.2) * 1000) / 1000 };
  }

  return rateCorrecting ? { action: 'rate', rate: 1 } : { action: 'none' };
}

/**
 * @param {number} expected
 * @param {number|null} durationSec
 */
export function isAtEnd(expected, durationSec) {
  return durationSec !== null && durationSec > 0 && expected >= durationSec - END_MARGIN_SEC;
}

/** Whether the drift is inside the window the sync badge calls "Синхронизировано". */
export function inSyncWindow(drift, kind) {
  return Math.abs(drift) <= (kind === 'youtube' ? YOUTUBE_SEEK_SEC : HTML5_SEEK_SEC / 2);
}
