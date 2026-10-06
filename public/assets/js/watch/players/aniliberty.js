// PlayerAdapter for AniLiberty episodes: the episode's HLS stream plays in a <video> straight from
// the AniLiberty CDN (our server only tells which playlists exist, it never touches the stream).
//
// - Wherever Media Source Extensions exist (Chrome, Edge, Firefox, Safari, iOS 17.1+ through
//   ManagedMediaSource) the stream goes through hls.js (assets/vendor/hls.js, loaded on first
//   use). Native HLS is used only without MSE (older iOS): Chrome 154 claims "maybe" for HLS in
//   canPlayType, yet fails these playlists with MEDIA_ERR_SRC_NOT_SUPPORTED (tested 2026-10-06).
//   hls.js runs without a worker, so the page needs no blob: workers in its CSP.
// - Each member picks a quality (480p / 720p / 1080p) for themselves; switching reloads the
//   playlist at the current position. Nothing is sent to the room.
// - Everything else (precise seeking, rate nudging, events) is the <video> adapter's.

import { api } from '../../api.js';
import { Html5PlayerAdapter } from './html5.js';

const HLS_SCRIPT = '/assets/vendor/hls.js/hls.light.min.js';
const QUALITY_KEY = 'ch:anime-quality';
const DEFAULT_HEIGHT = 720;

let hlsPromise = null;

function loadHls() {
  const w = /** @type {any} */ (window);
  if (w.Hls) {
    return Promise.resolve(w.Hls);
  }
  hlsPromise ??= new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = HLS_SCRIPT;
    script.async = true;
    script.onload = () => (w.Hls ? resolve(w.Hls) : reject(new Error('HLS_UNSUPPORTED')));
    script.onerror = () => {
      hlsPromise = null;
      script.remove();
      reject(new Error('HLS_UNSUPPORTED'));
    };
    document.head.append(script);
  });

  return hlsPromise;
}

function savedHeight() {
  try {
    const value = Number(localStorage.getItem(QUALITY_KEY));
    return Number.isInteger(value) && value > 0 ? value : DEFAULT_HEIGHT;
  } catch {
    return DEFAULT_HEIGHT;
  }
}

/**
 * The stream to start with: the saved height if offered, else the largest one below it, else the
 * smallest one. Pure.
 * @param {Array<{height: number}>} streams
 * @param {number} wanted
 */
export function pickStream(streams, wanted) {
  const sorted = [...streams].sort((a, b) => b.height - a.height);
  return sorted.find((s) => s.height <= wanted) ?? sorted.at(-1) ?? null;
}

export class AniLibertyPlayerAdapter extends Html5PlayerAdapter {
  /** @param {HTMLElement} mount */
  constructor(mount) {
    super(mount);
    this.kind = 'aniliberty';
    /** @type {Array<{height: number, label: string, url: string}>} */
    this.streams = [];
    this.stream = null;
    this.hls = null;
  }

  /**
   * @param {HTMLVideoElement} video
   * @param {{ref: string}} media ref is release_id:episode_uuid
   * @param {number} startSec
   */
  async attach(video, media, startSec) {
    const episodeId = media.ref.split(':')[1] ?? '';
    let episode;
    try {
      ({ episode } = await api(
        'GET',
        `/api/watch/aniliberty/episodes/${encodeURIComponent(episodeId)}`,
        null,
        {
          timeoutMs: 15000,
        },
      ));
    } catch {
      throw new Error('ANILIBERTY_UNAVAILABLE');
    }
    this.streams = Array.isArray(episode?.streams) ? episode.streams : [];
    const stream = pickStream(this.streams, savedHeight());
    if (stream === null) {
      throw new Error('ANILIBERTY_UNAVAILABLE');
    }
    await this.playStream(video, stream, startSec);
  }

  /**
   * @param {HTMLVideoElement} video
   * @param {{height: number, label: string, url: string}} stream
   * @param {number} startSec
   */
  async playStream(video, stream, startSec) {
    this.stream = stream;
    this.hls?.destroy();
    this.hls = null;

    const w = /** @type {any} */ (window);
    if (!w.MediaSource && !w.ManagedMediaSource) {
      if (video.canPlayType('application/vnd.apple.mpegurl') === '') {
        throw new Error('HLS_UNSUPPORTED');
      }
      video.src = stream.url;
      return;
    }
    const Hls = await loadHls();
    if (!Hls.isSupported()) {
      throw new Error('HLS_UNSUPPORTED');
    }
    const hls = new Hls({ enableWorker: false, startPosition: startSec, maxBufferLength: 30 });
    hls.on(Hls.Events.ERROR, (_event, data) => {
      if (data?.fatal) {
        this.fail?.(data.type === Hls.ErrorTypes.MEDIA_ERROR ? 'MEDIA_ERROR' : 'HLS_NETWORK');
      }
    });
    hls.loadSource(stream.url);
    hls.attachMedia(video);
    this.hls = hls;
  }

  /** @returns {{current: string|null, options: Array<{id: string, label: string}>}|null} */
  getQualities() {
    if (this.streams.length < 2) {
      return null;
    }
    return {
      current: this.stream === null ? null : String(this.stream.height),
      options: this.streams.map((s) => ({ id: String(s.height), label: s.label })),
    };
  }

  /**
   * Another quality for this member only: reload at the same position, keep playing if it was.
   * @param {string} id the height
   */
  setQuality(id) {
    const stream = this.streams.find((s) => String(s.height) === id);
    const video = this.video;
    if (stream === undefined || video === null || stream === this.stream) {
      return;
    }
    try {
      localStorage.setItem(QUALITY_KEY, id);
    } catch {
      // Not remembered, still switched.
    }
    const position = video.currentTime;
    const playing = !video.paused;
    video.addEventListener('loadedmetadata', () => {
      video.currentTime = position;
      if (playing) {
        void video.play().catch(() => {});
      }
    }, { once: true });
    void this.playStream(video, stream, position).catch(() => this.fail?.('HLS_UNSUPPORTED'));
  }

  destroy() {
    this.hls?.destroy();
    this.hls = null;
    super.destroy();
  }
}
