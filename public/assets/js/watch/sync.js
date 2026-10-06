// SyncController: keeps the local player in step with the room (docs/WATCH_PARTY_PLAN.md §6).
//
// - Clock: 5 pings after (re)connecting, then every 20 s; the min-RTT sample sets the offset.
// - Apply: on every playback state and on a tick (500 ms playing, 2 s paused) compare the player
//   with the expected position and fix status and drift (seek, or nudge the HTML5 rate).
// - Intents from our controls are sent at once and applied optimistically until the server's
//   state (higher seq) arrives. Seeks from the slider go out at most 4 times a second.
// - Actions that bypass our controls (a click on an embedded player, the platform's own controls,
//   media keys, native iOS controls) are recognised as play/pause/seek events we did not cause
//   and sent as intents.
// - What a player can do (rate nudging, seek window, captions, quality, live) is declared by its
//   adapter (adapter.capabilities and optional methods), never inferred from the platform.

import { clientNow, ClockSync } from './clock.js';
import {
  decideCorrection,
  expectedPosition,
  inSyncWindow,
  isAtEnd,
  isStalled,
  PRECISE_PLAYER,
  SEEK_FREEZE_MS,
  seekIgnored,
  trackMotion,
} from './playback.js';

const TICK_MS = 500;
const PAUSED_CHECK_MS = 2000;
const OWN_ACTION_WINDOW_MS = 700;
const BUFFERING_REPORT_MS = 500;
const AUTOPLAY_TIMEOUT_MS = 2000;
const PING_BURST = 5;
const PING_INTERVAL_MS = 20000;
const SEEK_SEND_INTERVAL_MS = 250;
const OPTIMISTIC_TTL_MS = 4000;
const EXTERNAL_SEEK_SEC = 1.0;
const CAPTIONS_KEY = 'ch:captions';

/**
 * @typedef {{
 *   ready: boolean, playing: boolean, position: number, duration: number|null,
 *   volume: number, muted: boolean, badge: 'offline'|'syncing'|'synced',
 *   overlay: null|'loading'|'autoplay'|'ended'|{error: string}, title: string|null, buffering: boolean,
 *   live?: boolean, ad?: boolean, nativeControls?: boolean, captions?: string|null,
 * }} SyncStatus
 */

export class SyncController {
  /**
   * @param {{
   *   request: (message: object) => Promise<any>,
   *   send: (message: object) => boolean,
   *   mount: HTMLElement,
   *   createAdapter: (kind: string, mount: HTMLElement) => any,
   *   onChange: () => void,
   *   onError: (code: string) => void,
   * }} deps
   */
  constructor(deps) {
    this.deps = deps;
    this.clock = new ClockSync();
    this.fallbackOffset = 0;
    this.adapter = null;
    this.mediaKey = null;
    this.media = null;
    /** @type {import('./store.js').Playback|null} */
    this.server = null;
    this.local = null;
    this.connection = 'idle';
    this.ownUntil = 0;
    this.pendingSeek = null;
    this.frozenUntil = 0;
    this.rateCorrecting = false;
    /** @type {{at: number, from: number, to: number}|null} the last correcting seek */
    this.lastSeek = null;
    /** @type {{position: number, at: number}|null} when the player's position last moved */
    this.motion = null;
    this.lastDrift = 0;
    this.lastCheck = 0;
    this.autoplayBlocked = false;
    this.autoplayTimer = null;
    this.bufferingTimer = null;
    this.reportedBuffering = false;
    this.presence = null;
    this.seekTimer = null;
    this.lastSeekSent = 0;
    this.pendingSeekTarget = null;
    this.pingTimer = null;
    this.volume = { level: 1, muted: false };
    this.roomMediaKey = null;
    /** @type {{base: string, ref: string}|null} */
    this.variant = null;
    /** @type {SyncStatus} */
    this.status = this.emptyStatus();
    this.ticker = setInterval(() => this.tick(), TICK_MS);
  }

  emptyStatus() {
    return {
      ready: false,
      playing: false,
      position: 0,
      duration: null,
      volume: this.volume?.level ?? 1,
      muted: this.volume?.muted ?? false,
      badge: 'offline',
      overlay: null,
      title: null,
      buffering: false,
      live: false,
    };
  }

  // ---------- Inputs ----------

  /**
   * Called on every render with the room state and whether the media is playable now.
   * @param {import('./store.js').RoomState} state
   * @param {boolean} playable
   */
  update(state, playable) {
    if (state.connection !== this.connection) {
      const wasOpen = this.connection === 'open';
      this.connection = state.connection;
      if (state.connection === 'open' && !wasOpen) {
        this.startPings();
      } else if (state.connection !== 'open') {
        this.stopPings();
      }
    }

    let media = playable ? state.media : null;
    this.roomMediaKey = media === null ? null : `${media.kind}:${media.ref}:${media.setAt}`;
    // Another quality of the same file, chosen by this member only; dropped when the room's video changes.
    if (media !== null && this.variant !== null && this.variant.base === this.roomMediaKey) {
      media = { ...media, ref: this.variant.ref };
    }
    const key = media === null ? null : `${media.kind}:${media.ref}:${media.setAt}`;
    if (key !== this.mediaKey) {
      // The room's playback belongs to the new media already; ours may still be the old video's.
      this.switchMedia(media, key, state.playback);
    }

    if (state.playback !== null && (this.server === null || state.playback.seq !== this.server.seq)) {
      this.server = state.playback;
      if (this.local !== null && state.playback.seq > this.local.baseSeq) {
        this.local = null;
      }
      this.apply(true);
    }
    // No notification: update() runs inside a render already.
    this.refreshStatus(false);
  }

  /** The welcome carries the server time: a rough offset until the first pong arrives. */
  seedClock(serverTime) {
    this.fallbackOffset = serverTime - clientNow();
  }

  serverNow() {
    return this.clock.ready ? this.clock.serverNow() : clientNow() + this.fallbackOffset;
  }

  // ---------- Intents (our controls) ----------

  togglePlay() {
    if (this.effective()?.status === 'playing') {
      this.pause();
    } else {
      this.play();
    }
  }

  play() {
    if (this.adapter === null) {
      return;
    }
    const position = this.atEnd() ? 0 : this.adapter.getCurrentTime();
    this.unlockAutoplay();
    this.command('play', position);
  }

  pause() {
    if (this.adapter !== null) {
      this.command('pause', this.adapter.getCurrentTime());
    }
  }

  /** From the progress slider (on release) and keyboard shortcuts; throttled to 4 per second. */
  seekTo(seconds) {
    if (this.adapter === null || this.isLive()) {
      return;
    }
    const duration = this.duration();
    const target = Math.max(0, duration === null ? seconds : Math.min(seconds, duration - 0.1));
    this.optimistic('seek', target);
    this.programmatic(() => {
      this.pendingSeek = target;
      this.adapter.seek(target);
    }, this.adapter.capabilities.seekGuardMs);
    this.pendingSeekTarget = target;
    const wait = this.lastSeekSent + SEEK_SEND_INTERVAL_MS - Date.now();
    clearTimeout(this.seekTimer);
    if (wait <= 0) {
      this.flushSeek();
    } else {
      this.seekTimer = setTimeout(() => this.flushSeek(), wait);
    }
  }

  seekBy(delta) {
    if (this.adapter !== null) {
      this.seekTo(this.adapter.getCurrentTime() + delta);
    }
  }

  replay() {
    this.unlockAutoplay();
    this.command('play', 0);
  }

  /** "Пересинхронизировать": jump straight to where the room is. */
  resync() {
    this.local = null;
    this.frozenUntil = 0;
    this.apply(true, true);
  }

  /** A click on "Нажмите, чтобы смотреть вместе" is the user activation autoplay needed. */
  unlockAutoplay() {
    if (!this.autoplayBlocked) {
      return;
    }
    this.autoplayBlocked = false;
    if (this.effective()?.status === 'playing' && this.adapter !== null) {
      this.programmatic(() => {
        void this.adapter.play()?.catch?.(() => {});
      });
    }
    this.apply(true);
  }

  setVolume(level) {
    this.volume.level = Math.max(0, Math.min(1, level));
    this.volume.muted = this.volume.level === 0;
    this.adapter?.setVolume(this.volume.level);
    this.adapter?.setMuted(this.volume.muted);
    this.refreshStatus();
  }

  toggleMute() {
    this.volume.muted = !this.volume.muted;
    if (!this.volume.muted && this.volume.level === 0) {
      this.volume.level = 0.6;
      this.adapter?.setVolume(this.volume.level);
    }
    this.adapter?.setMuted(this.volume.muted);
    this.refreshStatus();
  }

  enterNativeFullscreen() {
    return this.adapter?.enterNativeFullscreen?.() ?? false;
  }

  /** A live stream: no seeking, no shared position. */
  isLive() {
    return this.adapter?.isLive?.() ?? false;
  }

  /**
   * The player's own quality choice (per member, nothing is sent to the room), or null when the
   * adapter offers none.
   * @returns {{current: string|null, options: Array<{id: string, label: string}>}|null}
   */
  playerQualities() {
    return this.status.ready ? this.adapter?.getQualities?.() ?? null : null;
  }

  /** @param {string} id */
  setPlayerQuality(id) {
    this.adapter?.setQuality?.(id);
    this.refreshStatus();
  }

  /** The media id this member is playing (the room's file or a chosen variant of it). */
  currentRef() {
    return this.media?.ref ?? null;
  }

  /**
   * Plays another prepared variant of the room's file. The player reloads at the room's position;
   * nothing is sent to the room.
   * @param {string} ref media id of the variant
   */
  useVariant(ref) {
    if (this.roomMediaKey === null) {
      return;
    }
    this.variant = { base: this.roomMediaKey, ref };
    this.deps.onChange();
  }

  // ---------- Captions (adapters with getCaptions/setCaptions) ----------

  /** Saved choice: 'off' (default) or a language code. */
  captionPreference() {
    try {
      return localStorage.getItem(CAPTIONS_KEY) ?? 'off';
    } catch {
      return 'off';
    }
  }

  /** @returns {{tracks: Array<{code: string, label: string}>, active: string|null}|null} null when unsupported */
  captions() {
    return this.adapter?.getCaptions?.() ?? null;
  }

  /** @returns {Promise<Array<{code: string, label: string}>>} */
  loadCaptions() {
    return this.adapter?.loadCaptions?.() ?? Promise.resolve([]);
  }

  /** @param {string|null} code */
  setCaptions(code) {
    try {
      localStorage.setItem(CAPTIONS_KEY, code ?? 'off');
    } catch {
      // The choice simply is not remembered.
    }
    this.adapter?.setCaptions?.(code);
    setTimeout(() => this.refreshStatus(), 300);
  }

  /**
   * Applies the saved choice when the player loads and whenever YouTube loads its captions module
   * by itself (uploader defaults, language settings). Throttled: unloading the module can fire
   * the same event again.
   */
  applyCaptionPreference() {
    const adapter = this.adapter;
    const now = Date.now();
    if (typeof adapter?.setCaptions !== 'function' || now - (this.captionsAppliedAt ?? 0) < 2000) {
      return;
    }
    this.captionsAppliedAt = now;
    const wanted = this.captionPreference();
    adapter.setCaptions(wanted === 'off' ? null : wanted);
    this.refreshStatus();
  }

  // ---------- Core ----------

  /** The playback state to follow: an optimistic local one until the server confirms. */
  effective() {
    if (this.local !== null && Date.now() - this.local.at > OPTIMISTIC_TTL_MS) {
      this.local = null;
    }

    return this.local?.playback ?? this.server;
  }

  duration() {
    return this.media?.durationSec ?? this.adapter?.getDuration() ?? null;
  }

  expected() {
    const playback = this.effective();
    return playback === null ? 0 : expectedPosition(playback, this.serverNow(), this.duration());
  }

  atEnd() {
    return isAtEnd(this.expected(), this.duration());
  }

  /**
   * Brings the player to the room state.
   * @param {boolean} force run even if a paused room was checked recently
   * @param {boolean} [hardSeek] seek regardless of the drift window
   */
  apply(force = false, hardSeek = false) {
    const adapter = this.adapter;
    const playback = this.effective();
    if (adapter === null || !this.status.ready || playback === null) {
      return;
    }
    const now = Date.now();
    if (!force && playback.status === 'paused' && now - this.lastCheck < PAUSED_CHECK_MS) {
      return;
    }
    this.lastCheck = now;

    const expected = this.expected();
    const ended = isAtEnd(expected, this.duration());
    const shouldPlay = playback.status === 'playing' && !ended;

    if (shouldPlay && !adapter.isPlaying() && !this.autoplayBlocked) {
      this.startPlayback();
    } else if (!shouldPlay && adapter.isPlaying()) {
      this.programmatic(() => adapter.pause());
    }
    if (this.isLive()) {
      // A live stream has one position for everyone: only play and pause are shared.
      this.lastDrift = 0;
      return;
    }

    const position = adapter.getCurrentTime();
    const drift = position - expected;
    this.lastDrift = drift;
    this.motion = trackMotion(this.motion, position, now);
    const stalled = isStalled(this.motion, shouldPlay && adapter.isPlaying(), now);
    const decision = hardSeek && Math.abs(drift) > 0.05 ? { action: 'seek' } : decideCorrection({
      drift,
      player: adapter.capabilities,
      frozen: now < this.frozenUntil,
      rateCorrecting: this.rateCorrecting,
    });

    if (decision.action === 'seek' && !hardSeek && (stalled || seekIgnored(this.lastSeek, position, now))) {
      // The player is stuck (an ad, buffering) or ignored our last seek: wait for it to move.
      this.frozenUntil = now + SEEK_FREEZE_MS;
    } else if (decision.action === 'seek') {
      this.lastSeek = { at: now, from: position, to: expected };
      // An embedded player's seek can take a while to settle and passes through play states.
      this.programmatic(() => {
        this.pendingSeek = expected;
        adapter.seek(expected);
      }, adapter.capabilities.seekGuardMs);
      this.frozenUntil = now + SEEK_FREEZE_MS;
      if (this.rateCorrecting) {
        adapter.setRate(1);
        this.rateCorrecting = false;
      }
    } else if (decision.action === 'rate') {
      // Rate nudging only makes sense while playing; a paused player is simply seeked.
      if (shouldPlay) {
        adapter.setRate(decision.rate);
        this.rateCorrecting = decision.rate !== 1;
      } else if (Math.abs(drift) > 0.06) {
        this.programmatic(() => adapter.seek(expected));
      }
    }
  }

  startPlayback() {
    const adapter = this.adapter;
    this.programmatic(() => {
      const result = adapter.play();
      result?.catch?.((e) => {
        if (e?.name === 'NotAllowedError') {
          this.blockAutoplay();
        }
      });
    });
    if (adapter.capabilities.autoplayProbe) {
      clearTimeout(this.autoplayTimer);
      this.autoplayTimer = setTimeout(() => {
        if (
          this.adapter === adapter && this.effective()?.status === 'playing' && !adapter.isActuallyPlaying()
        ) {
          this.blockAutoplay();
        }
      }, AUTOPLAY_TIMEOUT_MS);
    }
  }

  blockAutoplay() {
    this.autoplayBlocked = true;
    this.refreshStatus();
  }

  tick() {
    if (this.adapter === null || !this.status.ready) {
      return;
    }
    this.apply(false);
    this.refreshStatus();
  }

  /** Optimistic local state for our own command, then the command itself. */
  command(type, positionSec) {
    const position = Math.max(0, Math.round(positionSec * 1000) / 1000);
    this.optimistic(type, position);
    this.apply(true, type === 'play' && position === 0);
    this.deps.request({ type: `playback.${type}`, positionSec: position }).catch((e) => {
      this.local = null;
      this.apply(true);
      this.deps.onError(e?.code ?? 'INTERNAL_ERROR');
    });
  }

  optimistic(type, position) {
    const base = this.effective();
    if (base === null || this.server === null) {
      return;
    }
    const status = type === 'play' ? 'playing' : type === 'pause' ? 'paused' : base.status;
    this.local = {
      baseSeq: this.server.seq,
      at: Date.now(),
      playback: { ...base, status, positionSec: position, updatedAt: this.serverNow(), seq: this.server.seq },
    };
  }

  flushSeek() {
    if (this.pendingSeekTarget === null) {
      return;
    }
    const target = this.pendingSeekTarget;
    this.pendingSeekTarget = null;
    this.lastSeekSent = Date.now();
    this.deps.request({ type: 'playback.seek', positionSec: Math.round(target * 1000) / 1000 }).catch((e) => {
      this.local = null;
      this.apply(true);
      this.deps.onError(e?.code ?? 'INTERNAL_ERROR');
    });
  }

  /** Marks the player events of the next moment as ours, not the user's. */
  programmatic(action, windowMs = OWN_ACTION_WINDOW_MS) {
    this.ownUntil = Math.max(this.ownUntil, clientNow() + windowMs);
    action();
  }

  isOwn() {
    return clientNow() < this.ownUntil;
  }

  // ---------- Player events ----------

  /**
   * @param {any} media
   * @param {string|null} key
   * @param {import('./store.js').Playback|null} [roomPlayback] the room's state for this media
   */
  switchMedia(media, key, roomPlayback = null) {
    this.destroyAdapter();
    this.mediaKey = key;
    this.media = media;
    this.status = { ...this.emptyStatus(), badge: this.status.badge };
    if (media === null) {
      this.setPresence('idle');
      this.deps.onChange();
      return;
    }

    const adapter = this.deps.createAdapter(media.kind, this.deps.mount);
    this.adapter = adapter;
    this.status.overlay = 'loading';
    adapter.on('play', () => this.onPlayerEvent('play'));
    adapter.on('pause', () => {
      // YouTube goes BUFFERING → PAUSED without a PLAYING in between (e.g. a seek while paused).
      if (this.status.buffering) {
        this.onBuffering(false);
      }
      this.onPlayerEvent('pause');
    });
    adapter.on('seeked', () => this.onPlayerEvent('seeked'));
    adapter.on('buffering', () => this.onBuffering(true));
    adapter.on('playing', () => this.onBuffering(false));
    adapter.on('ended', () => this.refreshStatus());
    adapter.on('error', ({ code }) => this.onPlayerError(code));
    adapter.on('captions', () => this.applyCaptionPreference());
    adapter.on('ad', () => this.refreshStatus());
    adapter.on('blocked', () => this.blockAutoplay());

    const playback = roomPlayback ?? this.effective();
    const startSec = playback === null
      ? media.startSec
      : expectedPosition(playback, this.serverNow(), media.durationSec);
    adapter.load(media, { startSec }).then(() => {
      if (this.adapter !== adapter) {
        return;
      }
      adapter.setVolume(this.volume.level);
      adapter.setMuted(this.volume.muted);
      this.status.ready = true;
      this.captionsAppliedAt = 0;
      this.applyCaptionPreference();
      if (this.status.overlay === 'loading') {
        this.status.overlay = null;
      }
      this.setPresence('watching');
      // A precise player jumps exactly to the room; embeds already got the start offset.
      this.apply(true, adapter.capabilities.rate);
      this.refreshStatus();
    }).catch((e) => {
      if (this.adapter === adapter) {
        this.onPlayerError(e?.message ?? 'MEDIA_ERROR');
      }
    });
    this.deps.onChange();
  }

  onPlayerEvent(type) {
    const adapter = this.adapter;
    const playback = this.effective();
    if (adapter === null || playback === null || !this.status.ready) {
      return;
    }
    if (
      type === 'seeked' && this.pendingSeek !== null &&
      Math.abs(adapter.getCurrentTime() - this.pendingSeek) < 1
    ) {
      this.pendingSeek = null;
      this.refreshStatus();
      return;
    }
    if (type === 'play' || type === 'seeked') {
      clearTimeout(this.autoplayTimer);
    }
    if (type === 'play' && this.autoplayBlocked) {
      // Started in the platform's own player: that click was the activation autoplay needed.
      this.autoplayBlocked = false;
    }
    if (this.isOwn() || this.atEnd()) {
      this.refreshStatus();
      return;
    }

    // Only events that contradict the room are the user's own doing.
    if (type === 'play' && playback.status !== 'playing') {
      this.autoplayBlocked = false;
      this.command('play', adapter.getCurrentTime());
    } else if (type === 'pause' && playback.status === 'playing') {
      this.command('pause', adapter.getCurrentTime());
    } else if (
      type === 'seeked' && !this.isLive() &&
      Math.abs(adapter.getCurrentTime() - this.expected()) > EXTERNAL_SEEK_SEC
    ) {
      this.seekTo(adapter.getCurrentTime());
    }
    this.refreshStatus();
  }

  onBuffering(buffering) {
    clearTimeout(this.bufferingTimer);
    this.status.buffering = buffering;
    if (buffering) {
      this.bufferingTimer = setTimeout(() => {
        this.reportedBuffering = true;
        this.setPresence('buffering');
      }, BUFFERING_REPORT_MS);
    } else if (this.reportedBuffering) {
      this.reportedBuffering = false;
      this.setPresence('watching');
    }
    this.refreshStatus();
  }

  onPlayerError(code) {
    this.status.overlay = { error: code };
    this.status.ready = false;
    this.refreshStatus();
  }

  setPresence(state) {
    if (this.presence !== state && this.deps.send({ type: 'presence.set', state })) {
      this.presence = state;
    }
  }

  // ---------- Clock ----------

  startPings() {
    this.stopPings();
    this.presence = null;
    let sent = 0;
    const ping = () => {
      const t0 = clientNow();
      this.deps.request({ type: 'ping', t: t0 }).then((pong) => {
        this.clock.addSample(t0, pong.serverTime, clientNow());
      }).catch(() => {});
      sent++;
      this.pingTimer = setTimeout(ping, sent < PING_BURST ? 200 : PING_INTERVAL_MS);
    };
    ping();
    if (this.adapter !== null && this.status.ready) {
      this.setPresence(this.status.buffering ? 'buffering' : 'watching');
    }
  }

  stopPings() {
    clearTimeout(this.pingTimer);
    this.pingTimer = null;
  }

  // ---------- Status for the view ----------

  refreshStatus(notify = true) {
    const adapter = this.adapter;
    const playback = this.effective();
    const s = this.status;
    if (adapter !== null && s.ready) {
      s.playing = playback?.status === 'playing' && !this.atEnd();
      s.position = adapter.getCurrentTime();
      s.duration = this.duration();
      s.title = adapter.getTitle?.() ?? null;
      s.live = this.isLive();
      s.ad = adapter.isInAd?.() ?? false;
      s.captions = typeof adapter.getCaptions === 'function'
        ? (adapter.getCaptions().active ?? null)
        : undefined;
      if (!(s.overlay !== null && typeof s.overlay === 'object')) {
        s.overlay = this.autoplayBlocked ? 'autoplay' : this.atEnd() && playback !== null ? 'ended' : null;
      }
    }
    s.volume = this.volume.level;
    s.muted = this.volume.muted;
    s.nativeControls = adapter?.capabilities.nativeControls ?? false;

    if (this.connection !== 'open') {
      s.badge = 'offline';
    } else if (!s.ready || s.buffering || Date.now() < this.frozenUntil) {
      s.badge = 'syncing';
    } else {
      s.badge = playback?.status !== 'playing' ||
          inSyncWindow(this.lastDrift, adapter?.capabilities ?? PRECISE_PLAYER)
        ? 'synced'
        : 'syncing';
    }
    if (notify) {
      this.deps.onChange();
    }
  }

  destroyAdapter() {
    clearTimeout(this.autoplayTimer);
    clearTimeout(this.bufferingTimer);
    clearTimeout(this.seekTimer);
    this.adapter?.destroy();
    this.adapter = null;
    this.local = null;
    this.pendingSeek = null;
    this.pendingSeekTarget = null;
    this.rateCorrecting = false;
    this.lastSeek = null;
    this.motion = null;
    this.autoplayBlocked = false;
    this.reportedBuffering = false;
    this.frozenUntil = 0;
  }

  /** Leaves the room: drop the player and all room state, keep the controller usable. */
  reset() {
    this.variant = null;
    this.stopPings();
    this.destroyAdapter();
    this.mediaKey = null;
    this.media = null;
    this.server = null;
    this.presence = null;
    this.connection = 'idle';
    this.clock = new ClockSync();
    this.status = this.emptyStatus();
  }

  destroy() {
    clearInterval(this.ticker);
    this.stopPings();
    this.destroyAdapter();
    this.mediaKey = null;
    this.media = null;
    this.server = null;
    this.connection = 'idle';
  }
}
