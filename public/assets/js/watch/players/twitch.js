// PlayerAdapter over the official Twitch interactive embed (player.twitch.tv/js/embed/v1.js,
// https://dev.twitch.tv/docs/embed/video-and-clips/). Same interface as players/html5.js.
//
// - Twitch requires that its player is not covered and keeps its own controls, so the adapter
//   declares nativeControls: our controls are not drawn over it, and what members do in the
//   Twitch player reaches the room through its PLAY/PAUSE/SEEK events.
// - Recordings (video:<id>) are synchronised like other embeds, by seeking. The embed refreshes
//   its position about once a second, so CoarseClock smooths it between refreshes; otherwise
//   sync would see a stale position as drift and keep seeking.
// - A live channel (channel:<login>) has one position for everyone: only play/pause are shared.
// - Clips have no JavaScript API at all; they are prepared as files instead (see TwitchRef.php).
// - Ads: Twitch plays ads in the same player and tells the API nothing about them. An ad shows
//   up as a SEEK we did not ask for to the ad's own time (≈ 1 s), sometimes preceded by a PAUSE,
//   then PLAY; the player's time then runs on the ad or stands still. So a PAUSE followed at once
//   by a SEEK is not a member's pause, an unrequested SEEK to the first seconds while the
//   recording was well past them starts an ad, and the ad is over when the recording continues
//   from where it stopped (or after AD_MAX_MS). During an ad the adapter keeps reporting the
//   recording's time, isInAd() is true, and sync neither corrects nor relays its events.

import { EMBED_PLAYER } from '../playback.js';
import { CoarseClock } from './coarse-clock.js';

const API_URL = 'https://player.twitch.tv/js/embed/v1.js';
const READY_TIMEOUT_MS = 30000;
/** A PAUSE followed by a SEEK this soon is the start of an ad or a seek, not a pause. */
const PAUSE_HOLD_MS = 500;
/** An unrequested SEEK below this, while the recording was well past it, is an ad starting. */
const AD_START_MAX_SEC = 3;
/** Our own seeks are recognised by their target for this long. */
const OWN_SEEK_MS = 3000;
/** Positions this close count as the same place. */
const SAME_PLACE_SEC = 2;
/** Safety net: an ad break is never assumed to last longer. */
const AD_MAX_MS = 180_000;

let apiPromise = null;

function loadApi() {
  const w = /** @type {any} */ (window);
  if (w.Twitch?.Player) {
    return Promise.resolve(w.Twitch);
  }
  apiPromise ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = API_URL;
    script.async = true;
    script.onload =
      () => (w.Twitch?.Player ? resolve(w.Twitch) : reject(new Error('TWITCH_API_UNAVAILABLE')));
    script.onerror = () => {
      apiPromise = null;
      script.remove();
      reject(new Error('TWITCH_API_UNAVAILABLE'));
    };
    document.head.append(script);
  });

  return apiPromise;
}

/** @param {number} seconds → "1h2m3s" (the embed's time option) */
function embedTime(seconds) {
  const s = Math.floor(seconds);
  return `${Math.floor(s / 3600)}h${Math.floor((s % 3600) / 60)}m${s % 60}s`;
}

/**
 * Creates the player while the address bar shows only /watch. The embed script copies
 * location.href into the iframe URL as "referrer"; the room id in the path is an invitation and
 * must not leave our site (the same reason Referrer-Policy sends only the origin).
 */
function withoutRoomPath(create) {
  const { pathname, search, hash } = location;
  history.replaceState(history.state, '', '/watch');
  try {
    return create();
  } finally {
    history.replaceState(history.state, '', pathname + search + hash);
  }
}

export class TwitchPlayerAdapter {
  /** @param {HTMLElement} mount */
  constructor(mount) {
    this.kind = 'twitch';
    this.capabilities = { ...EMBED_PLAYER, nativeControls: true };
    this.mount = mount;
    this.player = null;
    this.ready = false;
    this.live = false;
    // Twitch's isPaused() is false before the first start, so the state comes from its events.
    this.playing = false;
    this.inAd = false;
    /** The recording's time when the ad started: the ad is over once it moves. */
    this.adAt = 0;
    this.adStartedAt = 0;
    this.pauseTimer = null;
    /** @type {{target: number, at: number}|null} */
    this.ownSeek = null;
    this.clock = new CoarseClock(1);
    /** @type {Map<string, Set<Function>>} */
    this.listeners = new Map();
    this.timeout = null;
  }

  on(type, callback) {
    if (!this.listeners.has(type)) {
      this.listeners.set(type, new Set());
    }
    this.listeners.get(type).add(callback);
  }

  emit(type, detail) {
    for (const callback of this.listeners.get(type) ?? []) {
      callback(detail);
    }
  }

  /**
   * @param {{ref: string}} media ref is video:<id> or channel:<login>
   * @param {{startSec?: number}} [options]
   */
  async load(media, { startSec = 0 } = {}) {
    const Twitch = await loadApi();
    const [, type, id] = /^(video|channel):(.+)$/.exec(media.ref) ?? [];
    this.live = type === 'channel';

    const target = document.createElement('div');
    target.id = `twitch-${Math.random().toString(36).slice(2)}`;
    this.mount.replaceChildren(target);

    const options = {
      width: '100%',
      height: '100%',
      parent: [location.hostname],
      autoplay: false,
      muted: false,
      ...(this.live ? { channel: id } : { video: id, time: embedTime(startSec) }),
    };
    const player = withoutRoomPath(() => new Twitch.Player(target.id, options));
    this.player = player;
    const iframe = target.querySelector('iframe');
    iframe?.setAttribute('title', this.live ? 'Прямой эфир Twitch' : 'Запись Twitch');

    await new Promise((resolve, reject) => {
      this.timeout = setTimeout(() => reject(new Error('TWITCH_UNAVAILABLE')), READY_TIMEOUT_MS);
      player.addEventListener(Twitch.Player.READY, () => {
        clearTimeout(this.timeout);
        this.ready = true;
        this.emit('ready');
        resolve();
      });
    });

    const P = Twitch.Player;
    const now = () => performance.now();
    player.addEventListener(P.PLAY, () => {
      this.playing = true;
      this.emit('play');
    });
    player.addEventListener(P.PLAYING, () => {
      this.playing = true;
      this.clock.setRunning(true, now());
      this.emit('playing');
    });
    player.addEventListener(P.PAUSE, () => {
      this.playing = false;
      this.clock.report(this.rawTime(), now());
      this.clock.setRunning(false, now());
      clearTimeout(this.pauseTimer);
      this.pauseTimer = setTimeout(() => {
        this.pauseTimer = null;
        if (!this.inAd) {
          this.emit('pause');
        }
      }, PAUSE_HOLD_MS);
    });
    player.addEventListener(P.SEEK, (event) => this.seekEvent(Number(event?.position)));
    player.addEventListener(P.ENDED, () => {
      this.playing = false;
      this.clock.setRunning(false, now());
      this.emit('ended');
    });
    player.addEventListener(P.PLAYBACK_BLOCKED, () => this.emit('blocked'));
    player.addEventListener(P.OFFLINE, () => this.emit('offline'));
  }

  /** @param {number} position the SEEK event's position */
  seekEvent(position) {
    // A pause right before a seek belongs to the seek (or to an ad), not to a member.
    clearTimeout(this.pauseTimer);
    this.pauseTimer = null;
    const now = performance.now();
    if (!Number.isFinite(position)) {
      return;
    }
    const own = this.ownSeek !== null && now - this.ownSeek.at < OWN_SEEK_MS &&
      Math.abs(this.ownSeek.target - position) <= SAME_PLACE_SEC;
    if (this.inAd) {
      if (Math.abs(position - this.adAt) <= SAME_PLACE_SEC || own) {
        this.endAd(); // back to the recording
        this.clock.seek(position, now);
      }
      return; // a seek inside the ad break
    }
    const recording = this.clock.value(now);
    if (!own && position < AD_START_MAX_SEC && recording > position + 10) {
      this.startAd(recording);
      return;
    }
    this.clock.seek(position, now);
    this.emit('seeked');
  }

  startAd(recordingTime) {
    this.inAd = true;
    this.adAt = recordingTime;
    this.adStartedAt = performance.now();
    this.clock.seek(recordingTime, this.adStartedAt);
    this.clock.setRunning(false, this.adStartedAt);
    this.emit('ad', { active: true });
    this.emit('buffering');
  }

  endAd() {
    this.inAd = false;
    this.emit('ad', { active: false });
    if (this.playing) {
      this.emit('playing');
    }
  }

  /** While an ad plays, the recording waits: sync neither corrects nor relays events. */
  isInAd() {
    return this.inAd;
  }

  play() {
    this.player?.play();
    return Promise.resolve();
  }

  pause() {
    this.player?.pause();
  }

  seek(seconds) {
    if (!this.live) {
      const target = Math.max(0, seconds);
      this.ownSeek = { target, at: performance.now() };
      this.player?.seek(target);
      this.clock.seek(target, performance.now());
    }
  }

  rawTime() {
    return this.player?.getCurrentTime?.() ?? 0;
  }

  getCurrentTime() {
    if (this.live) {
      return this.rawTime();
    }
    const now = performance.now();
    const raw = this.rawTime();
    if (this.inAd) {
      // Over when the recording continues from where it stopped; the ad's own time is ignored.
      const resumed = raw > this.adAt + 0.3 && raw - this.adAt <= SAME_PLACE_SEC;
      if (!resumed && now - this.adStartedAt < AD_MAX_MS) {
        return this.adAt;
      }
      this.endAd();
    }
    this.clock.report(raw, now);
    this.clock.setRunning(this.isPlaying() && !this.inAd, now);
    return this.clock.value(now);
  }

  getDuration() {
    const duration = this.live ? 0 : this.player?.getDuration?.() ?? 0;
    return duration > 0 ? duration : null;
  }

  isPlaying() {
    return this.playing;
  }

  isActuallyPlaying() {
    return this.isPlaying();
  }

  /** A live channel: no seeking, the position is the stream's own. */
  isLive() {
    return this.live;
  }

  setVolume(volume) {
    this.player?.setVolume(Math.max(0, Math.min(1, volume)));
  }

  getVolume() {
    return this.player?.getVolume?.() ?? 1;
  }

  setMuted(muted) {
    this.player?.setMuted(muted);
  }

  isMuted() {
    return this.player?.getMuted?.() ?? false;
  }

  setRate() {
    // Not available in the Twitch API: sync corrects recordings by seeking.
  }

  enterNativeFullscreen() {
    return false;
  }

  destroy() {
    clearTimeout(this.timeout);
    clearTimeout(this.pauseTimer);
    this.listeners.clear();
    this.player = null;
    // The embed has no destroy(): removing its iframe stops it.
    this.mount.replaceChildren();
  }
}
