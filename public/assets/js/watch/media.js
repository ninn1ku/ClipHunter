// Polls the preparation status of a file-mode video (GET /api/watch/media/{id}) every 2 s ± 300 ms
// until it is ready, failed or expired.

import { api, ApiError } from '../api.js';

const INTERVAL_MS = 2000;
const JITTER_MS = 300;
const FINAL = new Set(['ready', 'failed', 'expired']);

export class MediaWatcher {
  /** @param {(status: {mediaId: string, status: string, queuePosition: number|null, percent: number|null, error: any}) => void} onUpdate */
  constructor(onUpdate) {
    this.onUpdate = onUpdate;
    this.mediaId = null;
    this.timer = null;
    this.last = null;
  }

  /** Starts watching a media id; watching the same id again is a no-op. */
  watch(mediaId) {
    if (mediaId === this.mediaId) {
      if (this.last !== null) {
        this.onUpdate(this.last);
      }
      return;
    }
    this.stop();
    this.mediaId = mediaId;
    void this.poll(mediaId);
  }

  stop() {
    clearTimeout(this.timer);
    this.timer = null;
    this.mediaId = null;
    this.last = null;
  }

  async poll(mediaId) {
    let status;
    try {
      status = await api('GET', `/api/watch/media/${mediaId}`, null, { timeoutMs: 10000 });
    } catch (e) {
      if (e instanceof ApiError && e.status === 404) {
        status = { mediaId, status: 'expired', queuePosition: null, percent: null, error: null };
      } else {
        status = null; // Network hiccup: keep the previous state and try again.
      }
    }
    if (this.mediaId !== mediaId) {
      return;
    }
    if (status !== null) {
      this.last = status;
      this.onUpdate(status);
      if (FINAL.has(status.status)) {
        return;
      }
    }
    this.timer = setTimeout(() => void this.poll(mediaId), INTERVAL_MS + (Math.random() * 2 - 1) * JITTER_MS);
  }
}
