// PlayerAdapter over the official VK Video embed: an iframe of vk.ru/video_ext.php?js_api=1 driven
// by VK.VideoPlayer from vk.ru/js/api/videoplayer.js (https://dev.vk.com/ru/widgets/video).
// Same interface as players/html5.js.
//
// - The documentation promises whole seconds; the embed currently reports fractions, but only on its
//   timeupdate ticks. CoarseClock turns either into a smooth estimate between reports.
// - The API has no rate control: sync corrects VK by seeking, with a wider window than YouTube.
// - Pre-roll ads (adStarted/adCompleted) count as buffering; meanwhile our controls let clicks
//   through to the player, so its ad controls stay usable.
// - The API reports no reason for a refusal. A player that never initialises within a minute, or
//   stays in the error state before it ever played, is reported as VK_UNAVAILABLE: deleted,
//   private, login-only or embedding disabled.

import { EMBED_PLAYER } from '../playback.js';
import { CoarseClock } from './coarse-clock.js';

const API_URL = 'https://vk.ru/js/api/videoplayer.js';
const EMBED_URL = 'https://vk.ru/video_ext.php';
// The embed answers our "init" request once its page runs; its load event comes much later
// (after ads and other resources: 14–24 s measured). So "init" is repeated until it answers.
const INIT_RETRY_MS = 1000;
const INIT_TIMEOUT_MS = 60000;
/** An error counts only if the player is still in the error state after this long. */
const ERROR_CONFIRM_MS = 3000;

let apiPromise = null;

function loadApi() {
  const w = /** @type {any} */ (window);
  if (w.VK?.VideoPlayer) {
    return Promise.resolve(w.VK);
  }
  apiPromise ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = API_URL;
    script.async = true;
    script.onload = () => (w.VK?.VideoPlayer ? resolve(w.VK) : reject(new Error('VK_API_UNAVAILABLE')));
    script.onerror = () => {
      apiPromise = null;
      script.remove();
      reject(new Error('VK_API_UNAVAILABLE'));
    };
    document.head.append(script);
  });

  return apiPromise;
}

/** @param {number} seconds → "1h2m3s" (the embed's t parameter) */
function embedTime(seconds) {
  const s = Math.floor(seconds);
  return `${Math.floor(s / 3600)}h${Math.floor((s % 3600) / 60)}m${s % 60}s`;
}

export class VkPlayerAdapter {
  /** @param {HTMLElement} mount */
  constructor(mount) {
    this.kind = 'vk';
    this.capabilities = EMBED_PLAYER;
    this.mount = mount;
    this.player = null;
    this.ready = false;
    this.started = false;
    this.inAd = false;
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
   * @param {{ref: string}} media ref is owner_video
   * @param {{startSec?: number}} [options]
   */
  async load(media, { startSec = 0 } = {}) {
    const VK = await loadApi();
    const [, owner, id] = /^(-?\d+)_(\d+)$/.exec(media.ref) ?? [];
    const params = new URLSearchParams({ oid: owner, id, js_api: '1' });
    if (startSec >= 1) {
      params.set('t', embedTime(startSec));
    }

    const iframe = document.createElement('iframe');
    iframe.src = `${EMBED_URL}?${params}`;
    iframe.title = 'Видео ВКонтакте';
    iframe.tabIndex = -1;
    iframe.allow = 'autoplay; encrypted-media; fullscreen; picture-in-picture';
    iframe.setAttribute('allowfullscreen', '');
    this.clock.seek(Math.floor(startSec), performance.now());

    let player;
    await new Promise((resolve, reject) => {
      const fail = () => {
        clearTimeout(this.timeout);
        reject(new Error('VK_UNAVAILABLE'));
      };
      this.timeout = setTimeout(fail, INIT_TIMEOUT_MS);
      const connect = () => {
        this.player?.destroy();
        player = VK.VideoPlayer(iframe);
        this.player = player;
        this.listen(player, resolve);
      };
      // Listen from the moment the iframe is in the page and repeat the API's "init" request
      // every second: the first ones reach the initial about:blank or a page still starting up
      // and get lost, and waiting for the iframe's load event costs 15–25 s.
      const retry = setInterval(() => {
        if (this.ready || this.mount.firstChild !== iframe) {
          clearInterval(retry);
        } else {
          connect();
        }
      }, INIT_RETRY_MS);
      this.retry = retry;
      this.mount.replaceChildren(iframe);
      connect();
    });
    this.subscribe(player);
  }

  /** Readiness: "inited" from the embed (only the timeout fails it). */
  listen(player, resolve) {
    player.on('inited', () => {
      clearTimeout(this.timeout);
      this.ready = true;
      this.emit('ready');
      resolve();
    });
    // The embed also reports errors it recovers from (a failed ad, for one) while the video
    // plays on. Before "inited" only the timeout decides; later an error counts only if the
    // player is still in the error state a moment after.
    player.on('error', () => {
      if (!this.ready) {
        return;
      }
      clearTimeout(this.errorTimer);
      this.errorTimer = setTimeout(() => {
        if (this.player === player && player.getState() === 'error') {
          // After it played once, an error is a playback failure, not a refusal.
          this.emit('error', { code: this.started ? 'MEDIA_ERROR' : 'VK_UNAVAILABLE' });
        }
      }, ERROR_CONFIRM_MS);
    });
  }

  subscribe(player) {
    const now = () => performance.now();
    player.on('timeupdate', (state) => {
      this.clock.report(state.time, now());
      this.emit('timeupdate');
    });
    player.on('started', (state) => this.resumed(state));
    player.on('resumed', (state) => this.resumed(state));
    player.on('paused', (state) => {
      this.clock.report(state.time, now());
      this.clock.setRunning(false, now());
      this.emit('pause');
    });
    player.on('seeked', (state) => {
      this.clock.report(state.time, now());
      this.emit('seeked');
    });
    player.on('ended', () => {
      this.clock.setRunning(false, now());
      this.emit('ended');
    });
    player.on('adStarted', () => {
      this.inAd = true;
      this.emit('ad', { active: true });
      this.emit('buffering');
    });
    player.on('adCompleted', () => {
      this.inAd = false;
      this.emit('ad', { active: false });
      if (player.getState() === 'playing') {
        this.emit('playing');
      }
    });
  }

  resumed(state) {
    this.started = true;
    this.clock.report(state.time, performance.now());
    this.clock.setRunning(true, performance.now());
    this.emit('play');
    if (!this.inAd) {
      this.emit('playing');
    }
  }

  play() {
    this.player?.play();
    return Promise.resolve();
  }

  pause() {
    this.player?.pause();
  }

  seek(seconds) {
    const target = Math.max(0, seconds);
    this.player?.seek(target);
    this.clock.seek(target, performance.now());
  }

  getCurrentTime() {
    return this.clock.value(performance.now());
  }

  getDuration() {
    const duration = this.player?.getDuration?.() ?? 0;
    return duration > 0 ? duration : null;
  }

  /** Playing an ad counts as playing: the intent is what matters for sync. */
  isPlaying() {
    return this.player?.getState?.() === 'playing' || this.inAd;
  }

  /** Whether the player has actually started (autoplay check). */
  isActuallyPlaying() {
    return this.player?.getState?.() === 'playing';
  }

  /** While an ad plays, our controls step aside so the player's own ad controls work. */
  isInAd() {
    return this.inAd;
  }

  setVolume(volume) {
    this.player?.setVolume(Math.max(0, Math.min(1, volume)));
  }

  getVolume() {
    return this.player?.getVolume?.() ?? 1;
  }

  setMuted(muted) {
    if (muted) {
      this.player?.mute();
    } else {
      this.player?.unmute();
    }
  }

  isMuted() {
    return this.player?.isMuted?.() ?? false;
  }

  setRate() {
    // Not available in the VK API: sync corrects by seeking.
  }

  enterNativeFullscreen() {
    return false;
  }

  destroy() {
    clearTimeout(this.timeout);
    clearInterval(this.retry);
    clearTimeout(this.errorTimer);
    this.listeners.clear();
    try {
      this.player?.destroy();
    } catch {
      // Already gone.
    }
    this.player = null;
    this.mount.replaceChildren();
  }
}
