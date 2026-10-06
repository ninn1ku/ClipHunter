// PlayerAdapter over the official YouTube IFrame Player API (youtube-nocookie.com host, our own controls).
// Same interface as players/html5.js. setRate is a no-op: sync corrects YouTube by seeking only.
//
// Error codes from the player: 2 bad parameter, 5 HTML5 player error, 100 not found / private,
// 101 and 150 embedding disabled by the owner, 153 the embed got no Referer (check Referrer-Policy).

const API_URL = 'https://www.youtube.com/iframe_api';
const STATE = { ENDED: 0, PLAYING: 1, PAUSED: 2, BUFFERING: 3 };

let apiPromise = null;

function loadApi() {
  const w = /** @type {any} */ (window);
  if (w.YT?.Player) {
    return Promise.resolve(w.YT);
  }
  apiPromise ??= new Promise((resolve, reject) => {
    const previous = w.onYouTubeIframeAPIReady;
    w.onYouTubeIframeAPIReady = () => {
      previous?.();
      resolve(w.YT);
    };
    const script = document.createElement('script');
    script.src = API_URL;
    script.async = true;
    script.onerror = () => {
      apiPromise = null;
      script.remove();
      reject(new Error('YT_API_UNAVAILABLE'));
    };
    document.head.append(script);
  });

  return apiPromise;
}

export class YouTubePlayerAdapter {
  /** @param {HTMLElement} mount */
  constructor(mount) {
    this.kind = 'youtube';
    this.mount = mount;
    this.player = null;
    this.ready = false;
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
  async load(media, { startSec = 0 } = {}) {
    const YT = await loadApi();
    const target = document.createElement('div');
    this.mount.replaceChildren(target);

    await new Promise((resolve, reject) => {
      this.player = new YT.Player(target, {
        host: 'https://www.youtube-nocookie.com',
        videoId: media.ref,
        width: '100%',
        height: '100%',
        playerVars: {
          controls: 0,
          disablekb: 1,
          playsinline: 1,
          rel: 0,
          iv_load_policy: 3,
          fs: 0,
          enablejsapi: 1,
          origin: location.origin,
          start: Math.floor(startSec),
        },
        events: {
          onReady: () => {
            this.ready = true;
            const iframe = this.mount.querySelector('iframe');
            iframe?.setAttribute('title', 'Видео YouTube');
            iframe?.setAttribute('tabindex', '-1');
            this.emit('ready');
            resolve();
          },
          onStateChange: (event) => this.stateChanged(event.data),
          onError: (event) => {
            const code = `YT_${event.data}`;
            this.emit('error', { code });
            if (!this.ready) {
              reject(new Error(code));
            }
          },
        },
      });
    });
  }

  stateChanged(state) {
    switch (state) {
      case STATE.PLAYING:
        this.emit('play');
        this.emit('playing');
        break;
      case STATE.PAUSED:
        this.emit('pause');
        break;
      case STATE.BUFFERING:
        this.emit('buffering');
        break;
      case STATE.ENDED:
        this.emit('ended');
        break;
    }
  }

  /** The title as the player knows it (tickets for YouTube carry no title). */
  getTitle() {
    try {
      return this.player?.getVideoData?.()?.title || null;
    } catch {
      return null;
    }
  }

  play() {
    this.player?.playVideo();
    return Promise.resolve();
  }

  pause() {
    this.player?.pauseVideo();
  }

  /** seekTo on a cued or paused-before-start video starts playback; keep it paused in that case. */
  seek(seconds) {
    const state = this.player?.getPlayerState?.();
    this.player?.seekTo(Math.max(0, seconds), true);
    if (state !== STATE.PLAYING && state !== STATE.BUFFERING) {
      this.player?.pauseVideo();
    }
  }

  getCurrentTime() {
    return this.player?.getCurrentTime?.() ?? 0;
  }

  getDuration() {
    const duration = this.player?.getDuration?.() ?? 0;
    return duration > 0 ? duration : null;
  }

  /** Buffering while trying to play counts as playing: the intent is what matters for sync. */
  isPlaying() {
    const state = this.player?.getPlayerState?.();
    return state === STATE.PLAYING || state === STATE.BUFFERING;
  }

  /** Whether the player has actually started (autoplay check). */
  isActuallyPlaying() {
    return this.player?.getPlayerState?.() === STATE.PLAYING;
  }

  setVolume(volume) {
    this.player?.setVolume(Math.round(Math.max(0, Math.min(1, volume)) * 100));
  }

  getVolume() {
    return (this.player?.getVolume?.() ?? 100) / 100;
  }

  setMuted(muted) {
    if (muted) {
      this.player?.mute();
    } else {
      this.player?.unMute();
    }
  }

  isMuted() {
    return this.player?.isMuted?.() ?? false;
  }

  setRate() {
    // Not used: YouTube is corrected by seeking.
  }

  enterNativeFullscreen() {
    return false;
  }

  destroy() {
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
