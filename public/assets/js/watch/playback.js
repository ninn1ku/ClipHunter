// Playback math for synchronisation (docs/WATCH_PARTY_PLAN.md §6.2–6.3). Pure: no DOM, no timers.

/** Drift (seconds) tolerated before any correction: inside it the room counts as in sync. */
export const IN_SYNC_SEC = 0.06;
/** Players that can nudge playbackRate: beyond this drift we seek; below it we nudge the rate. */
export const HTML5_SEEK_SEC = 0.5;
/** Embedded players cannot change rate smoothly through their APIs, so they only seek, with a wider window. */
export const YOUTUBE_SEEK_SEC = 1.0;
/** After a seek, corrections pause while the player settles. */
export const SEEK_FREEZE_MS = 1500;
/** Near the end the room counts as finished ("Смотреть заново"). */
export const END_MARGIN_SEC = 0.25;

/**
 * What a player adapter declares about itself (adapter.capabilities). Sync logic and views ask
 * these questions instead of checking the platform.
 *
 * @typedef {{
 *   rate: boolean,          // playbackRate can be nudged smoothly: fine drift correction
 *   seekSec: number,        // without rate: drift beyond which the player is seeked
 *   seekGuardMs: number,    // after our own seek, player events of this long are ours
 *   autoplayProbe: boolean, // play() does not report a blocked autoplay: check the state after a while
 *   nativeControls: boolean, // the platform's own controls stay visible; ours are not drawn over the video
 * }} Capabilities
 */

/** A <video> element we control completely. */
export const PRECISE_PLAYER = Object.freeze({
  rate: true,
  seekSec: HTML5_SEEK_SEC,
  seekGuardMs: 700,
  autoplayProbe: false,
  nativeControls: false,
});

/** An embedded iframe player driven through postMessage: seeks only, slower to settle. */
export const EMBED_PLAYER = Object.freeze({
  rate: false,
  seekSec: YOUTUBE_SEEK_SEC,
  seekGuardMs: 2500,
  autoplayProbe: true,
  nativeControls: false,
});

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
 * @param {{drift: number, player: Pick<Capabilities, 'rate'|'seekSec'>, frozen?: boolean,
 *   rateCorrecting?: boolean}} input
 * @returns {{action: 'none'} | {action: 'seek'} | {action: 'rate', rate: number}}
 */
export function decideCorrection({ drift, player, frozen = false, rateCorrecting = false }) {
  if (!Number.isFinite(drift) || frozen) {
    return { action: 'none' };
  }
  const size = Math.abs(drift);

  if (!player.rate) {
    return size > player.seekSec ? { action: 'seek' } : { action: 'none' };
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

/** How long an ignored seek is not repeated: the platform is busy (an ad, loading). */
export const SEEK_RETRY_MS = 8000;

/**
 * Whether our last seek has not taken effect yet: the player is still where it was when we
 * asked. Embedded players ignore seeks while they show an ad or are still loading, and repeating
 * the seek every tick only fights them. Pure.
 *
 * @param {{at: number, from: number, to: number}|null} lastSeek
 * @param {number} position the player's position now
 * @param {number} now ms, same clock as lastSeek.at
 */
export function seekIgnored(lastSeek, position, now) {
  return lastSeek !== null && now - lastSeek.at < SEEK_RETRY_MS &&
    Math.abs(position - lastSeek.from) < 0.5 && Math.abs(position - lastSeek.to) > 1;
}

/**
 * @param {number} expected
 * @param {number|null} durationSec
 */
export function isAtEnd(expected, durationSec) {
  return durationSec !== null && durationSec > 0 && expected >= durationSec - END_MARGIN_SEC;
}

/**
 * Whether the drift is inside the window the sync badge calls "Синхронизировано".
 * @param {number} drift
 * @param {Pick<Capabilities, 'rate'|'seekSec'>} player
 */
export function inSyncWindow(drift, player) {
  return Math.abs(drift) <= (player.rate ? HTML5_SEEK_SEC / 2 : player.seekSec);
}
