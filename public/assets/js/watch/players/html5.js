// PlayerAdapter over a <video> element, for files prepared by our server (seekable via Range).
//
// Interface shared with players/youtube.js:
//   load(media, {startSec}) → Promise; play() → Promise; pause(); seek(sec); getCurrentTime(); getDuration();
//   isPlaying(); setVolume(0..1); getVolume(); setMuted(b); isMuted(); setRate(r); destroy();
//   on('ready'|'play'|'pause'|'seeked'|'buffering'|'playing'|'ended'|'error'|'timeupdate', cb)
// and declares what it can do in `capabilities` (see playback.js). Optional: getCaptions/loadCaptions/
// setCaptions, getQualities/setQuality, isLive, getTitle, enterNativeFullscreen.

import { PRECISE_PLAYER } from '../playback.js';

export class Html5PlayerAdapter {
  /** @param {HTMLElement} mount */
  constructor(mount) {
    this.kind = 'html5';
    this.capabilities = PRECISE_PLAYER;
    this.mount = mount;
    /** @type {HTMLVideoElement|null} */
    this.video = null;
    /** @type {Map<string, Set<Function>>} */
    this.listeners = new Map();
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
   * @param {{ref: string}} media
   * @param {{startSec?: number}} [options]
   */
  load(media, { startSec = 0 } = {}) {
    const video = document.createElement('video');
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.setAttribute('webkit-playsinline', '');
    video.preload = 'auto';
    video.controls = false;
    video.disableRemotePlayback = true;
    video.setAttribute('aria-hidden', 'true');
    this.video = video;

    const forward = {
      play: 'play',
      pause: 'pause',
      seeked: 'seeked',
      waiting: 'buffering',
      playing: 'playing',
      ended: 'ended',
      timeupdate: 'timeupdate',
    };
    for (const [from, to] of Object.entries(forward)) {
      video.addEventListener(from, () => this.emit(to));
    }

    const ready = new Promise((resolve, reject) => {
      video.addEventListener('loadedmetadata', () => {
        if (startSec > 0 && Number.isFinite(video.duration)) {
          video.currentTime = Math.min(startSec, video.duration);
        }
        this.emit('ready');
        resolve();
      }, { once: true });
      video.addEventListener('error', () => {
        const code = video.error?.code === 4 ? 'MEDIA_UNSUPPORTED' : 'MEDIA_ERROR';
        this.emit('error', { code });
        reject(new Error(code));
      });
    });

    video.src = `/api/watch/media/${encodeURIComponent(media.ref)}/file`;
    this.mount.replaceChildren(video);

    return ready;
  }

  play() {
    return this.video?.play() ?? Promise.resolve();
  }

  pause() {
    this.video?.pause();
  }

  seek(seconds) {
    if (this.video !== null) {
      this.video.currentTime = Math.max(0, seconds);
    }
  }

  getCurrentTime() {
    return this.video?.currentTime ?? 0;
  }

  getDuration() {
    const duration = this.video?.duration;
    return typeof duration === 'number' && Number.isFinite(duration) && duration > 0 ? duration : null;
  }

  isPlaying() {
    return this.video !== null && !this.video.paused && !this.video.ended;
  }

  setVolume(volume) {
    if (this.video !== null) {
      this.video.volume = Math.max(0, Math.min(1, volume));
    }
  }

  getVolume() {
    return this.video?.volume ?? 1;
  }

  setMuted(muted) {
    if (this.video !== null) {
      this.video.muted = muted;
    }
  }

  isMuted() {
    return this.video?.muted ?? false;
  }

  setRate(rate) {
    if (this.video !== null && this.video.playbackRate !== rate) {
      this.video.playbackRate = rate;
    }
  }

  /** iOS Safari only allows fullscreen on the video element itself. */
  enterNativeFullscreen() {
    const video = /** @type {any} */ (this.video);
    if (video !== null && typeof video.webkitEnterFullscreen === 'function') {
      video.webkitEnterFullscreen();
      return true;
    }
    return false;
  }

  destroy() {
    const video = this.video;
    this.video = null;
    this.listeners.clear();
    if (video !== null) {
      video.pause();
      video.removeAttribute('src');
      video.load();
      video.remove();
    }
  }
}
