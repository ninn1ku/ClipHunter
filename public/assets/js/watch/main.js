// Watch rooms entry point: routing (/watch, /watch/{roomId}) and wiring of store, session and views.

import { api } from '../api.js';
import { formatBytes } from '../format.js';
import { fallbackTitle, needsPreparation, playerQualityNote, serverFallbackUrl } from './kinds.js';
import { MediaWatcher } from './media.js';
import { Html5PlayerAdapter } from './players/html5.js';
import { YouTubePlayerAdapter } from './players/youtube.js';
import { SyncController } from './sync.js';
import { roomErrorText, TERMINAL_SCREENS } from './messages.js';
import { RoomError } from './room-client.js';
import { identityStore, RoomSession } from './session.js';
import { isHost, Store } from './store.js';
import { participantsLabel } from './text.js';
import { ChatView } from './views/chat.js';
import { PlayerControls } from './views/controls.js';
import { HeaderView } from './views/header.js';
import { NameDialog } from './views/join-dialog.js';
import { ParticipantsView } from './views/participants.js';
import { PlayerView, stageKind } from './views/player.js';
import { resolveSource, SourceDialog, sourceErrorText } from './views/source-dialog.js';
import { StartView } from './views/start.js';
import { Toasts } from './views/toast.js';

const ROOM_PATH = /^\/watch\/([0-9A-HJKMNP-TV-Z]{12})\/?$/;
const FATAL_JOIN = { ROOM_FULL: 'full', KICKED: 'kicked', ROOM_NOT_FOUND: 'not_found' };
/** Tells the server a room file is still watched, so its idle cleanup does not remove it. */
const KEEPALIVE_MS = 5 * 60_000;

/** Player adapter per media kind; each loads its platform's script only when first used. */
const ADAPTERS = {
  youtube: YouTubePlayerAdapter,
  file: Html5PlayerAdapter,
};

const $ = (id) => /** @type {any} */ (document.getElementById(id));

class WatchApp {
  constructor() {
    this.store = new Store();
    /** @type {RoomSession|null} */
    this.session = null;
    this.preparation = null;
    this.renderQueued = false;
    this.toasts = new Toasts($('toasts'));

    this.nameDialog = new NameDialog({
      dialog: $('name-dialog'),
      form: $('name-form'),
      input: $('name-input'),
      error: $('name-error'),
      submit: $('name-submit'),
    }, identityStore);
    this.sourceDialog = new SourceDialog({
      dialog: $('source-dialog'),
      form: $('source-form'),
      input: $('source-url'),
      error: $('source-error'),
      submit: $('source-submit'),
    }, (ticket) => this.setMedia(ticket));
    this.start = new StartView({
      form: $('create-form'),
      input: $('create-url'),
      submit: $('create-submit'),
      hint: $('create-hint'),
    }, {
      askName: (onSubmit) =>
        this.nameDialog.open({ submitLabel: 'Создать комнату', cancellable: true, onSubmit }),
      create: (name, ticket) => this.create(name, ticket),
    });
    this.header = new HeaderView({
      badge: $('room-badge'),
      menuToggle: $('room-menu-toggle'),
      menu: $('room-menu'),
    });
    this.chat = new ChatView({
      list: $('chat-list'),
      form: $('chat-form'),
      input: $('chat-input'),
      pill: $('chat-new'),
      sub: $('chat-sub'),
      emojiToggle: $('emoji-toggle'),
      emojiPanel: $('emoji-panel'),
    }, {
      send: (text) => this.request({ type: 'chat.send', text }),
      toast: (message, options) => this.toasts.show(message, options),
    });
    this.participants = new ParticipantsView([
      { list: $('people-list'), count: $('people-count') },
      { list: $('people-dialog-list'), count: $('people-dialog-count') },
    ], {
      invite: () => void this.invite(),
      makeHost: (id) => void this.act({ type: 'host.transfer', participantId: id }),
      kick: (id, name) => {
        if (window.confirm(`Удалить ${name} из комнаты? Вернуться можно будет через 10 минут.`)) {
          void this.act({ type: 'participant.kick', participantId: id });
        }
      },
    });
    this.player = new PlayerView({
      player: $('player'),
      mount: $('player-mount'),
      poster: $('player-poster'),
      overlay: $('player-overlay'),
    }, {
      chooseSource: async (url) => {
        const { ticket } = await resolveSource(url);
        await this.setMedia(ticket);
      },
      openSourceDialog: () => this.sourceDialog.open(),
      prepareOnServer: async () => {
        const url = serverFallbackUrl(this.store.state.media);
        if (url === null) {
          return;
        }
        // The platform refuses to play this video here: let our server prepare the file instead.
        const { ticket } = await resolveSource(url, 'file');
        await this.setMedia(ticket);
      },
      unlockAutoplay: () => this.sync.unlockAutoplay(),
      replay: () => this.sync.replay(),
    });
    this.sync = new SyncController({
      request: (message) => this.request(message),
      send: (message) => this.session?.send(message) ?? false,
      mount: $('player-mount'),
      createAdapter: (kind, mount) => new (ADAPTERS[kind] ?? Html5PlayerAdapter)(mount),
      onChange: () => this.scheduleRender(),
      onError: (code) => this.toasts.show(roomErrorText(code), { kind: 'error' }),
    });
    this.controls = new PlayerControls($('player'), this.sync, {
      isHost: () => isHost(this.store.state),
      changeMedia: () => this.sourceDialog.open(),
      quality: () => this.qualityMenu(),
      selectQuality: (optionId) => void this.selectQuality(optionId),
    });
    /** @type {{ref: string, list: any[]|null, loading: boolean}|null} */
    this.variants = null;
    this.pendingVariant = null;
    setInterval(() => this.keepAlive(), KEEPALIVE_MS);
    this.media = new MediaWatcher((status) => {
      this.preparation = status;
      this.scheduleRender();
    });

    this.store.subscribe(() => this.scheduleRender());
    this.bindChrome();
  }

  init() {
    window.addEventListener('popstate', () => this.route());
    this.route();
  }

  route() {
    const match = ROOM_PATH.exec(location.pathname);
    if (match) {
      if (this.session === null || this.session.roomId !== match[1]) {
        void this.openRoom(match[1]);
      }
      return;
    }
    if (this.session !== null) {
      void this.session.leave();
      this.session = null;
    }
    this.showStart();
  }

  // ---------- Views ----------

  showView(name) {
    for (const view of ['start', 'room', 'screen']) {
      $(view).hidden = view !== name;
    }
    $('skip-link').setAttribute(
      'href',
      { start: '#start-main', room: '#room-main', screen: '#screen-main' }[name],
    );
  }

  showStart() {
    this.sync.reset();
    this.media.stop();
    this.preparation = null;
    this.showView('start');
    document.title = 'Смотреть вместе — ClipHunter';
    this.start.show(new URLSearchParams(location.search).get('url'));
  }

  showScreen(key) {
    const screen = TERMINAL_SCREENS[key] ?? TERMINAL_SCREENS.error;
    this.nameDialog.close();
    for (const dialog of document.querySelectorAll('dialog[open]')) {
      dialog.close();
    }
    this.sync.reset();
    this.media.stop();
    if (this.session !== null && this.session.phase !== 'terminated') {
      this.session.close();
    }
    this.session = null;
    $('screen-title').textContent = screen.title;
    $('screen-text').textContent = screen.text;
    this.showView('screen');
    document.title = `${screen.title} — ClipHunter`;
    $('screen-title').focus();
  }

  // ---------- Room lifecycle ----------

  newSession() {
    this.session?.close();
    const session = new RoomSession(this.store);
    session.addEventListener('terminated', (event) => {
      if (this.session === session) {
        this.showScreen(event.detail.screen);
      }
    });
    session.addEventListener('joined', (event) => {
      this.sync.seedClock(event.detail.serverTime);
      $('room').classList.remove('is-locked');
      this.announce('Вы в комнате');
    });
    this.session = session;

    return session;
  }

  async openRoom(roomId) {
    const session = this.newSession();
    this.showView('room');
    $('room').classList.add('is-locked');
    document.title = 'Комната — Смотреть вместе — ClipHunter';

    let outcome;
    try {
      outcome = await session.enter(roomId);
    } catch {
      if (this.session === session) {
        this.showScreen('error');
      }
      return;
    }
    if (this.session !== session) {
      return;
    }
    if (outcome === 'not_found' || outcome === 'full') {
      this.showScreen(outcome);
      return;
    }
    if (outcome === 'needs_name') {
      await this.nameDialog.open({
        submitLabel: 'Войти в комнату',
        cancellable: false,
        onSubmit: async (name) => {
          try {
            await session.join(name);
          } catch (e) {
            const screen = e instanceof RoomError ? FATAL_JOIN[e.code] : undefined;
            if (screen !== undefined) {
              this.showScreen(screen);
              return;
            }
            throw e;
          }
        },
      });
    }
  }

  async create(name, ticket) {
    const session = this.newSession();
    const roomId = await session.create(name, ticket);
    history.pushState({}, '', `/watch/${roomId}`);
    this.showView('room');
    $('room').classList.remove('is-locked');
    this.toasts.show('Комната создана. Отправьте ссылку друзьям — кнопка «Пригласить друга» её скопирует.');
  }

  async leave() {
    const session = this.session;
    this.session = null;
    await session?.leave();
    history.pushState({}, '', '/watch');
    this.showStart();
  }

  // ---------- Actions ----------

  /** A room request whose failure is shown as a toast. */
  async act(message) {
    try {
      await this.request(message);
    } catch (e) {
      this.toasts.show(roomErrorText(e?.code), { kind: 'error' });
    }
  }

  request(message) {
    if (this.session === null) {
      return Promise.reject(new RoomError('DISCONNECTED'));
    }

    return this.session.request(message);
  }

  setMedia(ticket) {
    return this.request({ type: 'media.set', ticket });
  }

  // ---------- Quality (per member: server variants of a file, or the player's own levels) ----------

  /** @returns {import('./views/controls.js').QualityMenu|null} */
  qualityMenu() {
    const media = this.store.state.media;
    if (media === null) {
      return null;
    }
    if (!needsPreparation(media)) {
      const own = this.sync.playerQualities();
      return own === null
        ? { current: null, options: [], note: playerQualityNote(media.kind) }
        : { ...own, note: 'Меняется только у вас.' };
    }
    void this.loadVariants(media.ref);
    const list = this.variants?.ref === media.ref ? this.variants.list : null;
    if (list === null) {
      return { current: null, options: [], note: 'Загружаем варианты качества…' };
    }
    const playing = this.sync.currentRef();
    const current = list.find((v) => v.mediaId !== null && v.mediaId === playing)?.optionId ?? null;

    return {
      current,
      note: 'Меняется только у вас.',
      options: list.map((v) => ({ id: v.optionId, label: v.label, note: this.variantNote(v) })),
    };
  }

  variantNote(variant) {
    if (this.pendingVariant?.optionId === variant.optionId) {
      return 'готовится…';
    }
    switch (variant.status) {
      case 'ready':
        return 'готово';
      case 'preparing':
        return variant.percent === null ? 'готовится' : `готовится ${Math.floor(variant.percent)} %`;
      case 'queued':
        return 'в очереди';
      default:
        return variant.sizeBytes === null ? 'подготовить' : `≈ ${formatBytes(variant.sizeBytes)}`;
    }
  }

  /** The variant list of the room's file (fetched once per video, refreshed when the menu opens). */
  async loadVariants(ref) {
    if (this.variants?.ref === ref && this.variants.loading) {
      return;
    }
    const keep = this.variants?.ref === ref ? this.variants.list : null;
    this.variants = { ref, list: keep, loading: true };
    try {
      const { variants } = await api('GET', `/api/watch/media/${ref}/variants`, null, { timeoutMs: 10000 });
      if (this.variants?.ref === ref) {
        this.variants = { ref, list: variants, loading: false };
      }
    } catch {
      if (this.variants?.ref === ref) {
        this.variants = { ref, list: keep, loading: false };
      }
    }
  }

  async selectQuality(optionId) {
    const media = this.store.state.media;
    if (media !== null && !needsPreparation(media)) {
      this.sync.setPlayerQuality(optionId);
      return;
    }
    if (media === null) {
      return;
    }
    const label = this.variants?.list?.find((v) => v.optionId === optionId)?.label ?? optionId;
    let status;
    try {
      status = await api('POST', `/api/watch/media/${media.ref}/variants`, { optionId }, {
        timeoutMs: 20000,
      });
    } catch (e) {
      this.toasts.show(sourceErrorText(e), { kind: 'error' });
      return;
    }
    if (status.status === 'ready') {
      this.sync.useVariant(status.mediaId);
      this.toasts.show(`Качество: ${label}`);
      return;
    }
    if (status.status === 'failed' || status.status === 'expired') {
      this.toasts.show(`Не удалось подготовить ${label}.`, { kind: 'error' });
      return;
    }
    this.toasts.show(`Готовим ${label} — переключим автоматически, когда будет готово.`);
    this.pendingVariant = { optionId, mediaId: status.mediaId, base: media.ref, label };
    void this.waitForVariant(this.pendingVariant);
  }

  async waitForVariant(pending) {
    while (this.pendingVariant === pending && this.store.state.media?.ref === pending.base) {
      await new Promise((resolve) => setTimeout(resolve, 2000 + Math.random() * 600));
      let status;
      try {
        status = await api('GET', `/api/watch/media/${pending.mediaId}`, null, { timeoutMs: 10000 });
      } catch {
        continue;
      }
      if (this.pendingVariant !== pending) {
        return;
      }
      if (status.status === 'ready') {
        this.pendingVariant = null;
        this.sync.useVariant(pending.mediaId);
        this.toasts.show(`Качество: ${pending.label}`);
        return;
      }
      if (status.status === 'failed' || status.status === 'expired') {
        this.pendingVariant = null;
        this.toasts.show(`Не удалось подготовить ${pending.label}.`, { kind: 'error' });
        return;
      }
    }
  }

  keepAlive() {
    const ref = this.sync.currentRef();
    if (needsPreparation(this.store.state.media) && ref !== null && this.session !== null) {
      void api('GET', `/api/watch/media/${ref}`, null, { timeoutMs: 10000 }).catch(() => {});
    }
  }

  roomUrl() {
    return `${location.origin}/watch/${this.store.state.roomId}`;
  }

  async copyLink() {
    const url = this.roomUrl();
    try {
      await navigator.clipboard.writeText(url);
      this.toasts.show('Ссылка скопирована');
    } catch {
      window.prompt('Скопируйте ссылку на комнату:', url);
    }
  }

  async invite() {
    const url = this.roomUrl();
    if (typeof navigator.share === 'function' && window.matchMedia('(pointer: coarse)').matches) {
      try {
        await navigator.share({
          title: 'Смотрим вместе в ClipHunter',
          text: 'Заходи смотреть видео вместе:',
          url,
        });
        return;
      } catch (e) {
        if (e?.name === 'AbortError') {
          return;
        }
      }
    }
    await this.copyLink();
  }

  announce(text) {
    $('announcer').textContent = '';
    requestAnimationFrame(() => {
      $('announcer').textContent = text;
    });
  }

  bindChrome() {
    document.addEventListener('click', (event) => {
      const target = event.target instanceof Element ? event.target.closest('[data-action]') : null;
      if (target === null) {
        return;
      }
      switch (target.dataset.action) {
        case 'copy-link':
          void this.copyLink();
          break;
        case 'leave':
          void this.leave();
          break;
        case 'people':
          $('people-dialog').showModal();
          break;
        case 'new-room':
          event.preventDefault();
          history.pushState({}, '', '/watch');
          this.route();
          break;
      }
    });
    $('people-dialog').querySelector('[data-close]').addEventListener(
      'click',
      () => $('people-dialog').close(),
    );
    $('change-media').addEventListener('click', () => this.sourceDialog.open());
    for (const dialog of document.querySelectorAll('dialog')) {
      // A click on the backdrop (the dialog element itself) closes dismissible dialogs.
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog && dialog.id !== 'name-dialog') {
          dialog.close();
        }
      });
    }
    this.bindTabs();
  }

  bindTabs() {
    const side = document.querySelector('.room__side');
    const tabs = [$('tab-chat'), $('tab-people')];
    const select = (tab, focus) => {
      side.dataset.tab = tab === tabs[0] ? 'chat' : 'people';
      for (const t of tabs) {
        const selected = t === tab;
        t.setAttribute('aria-selected', String(selected));
        t.tabIndex = selected ? 0 : -1;
      }
      if (focus) {
        tab.focus();
      }
    };
    for (const tab of tabs) {
      tab.addEventListener('click', () => select(tab, false));
      tab.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
          event.preventDefault();
          select(tabs[(tabs.indexOf(tab) + 1) % 2], true);
        }
      });
    }
  }

  // ---------- Rendering ----------

  scheduleRender() {
    if (this.renderQueued) {
      return;
    }
    this.renderQueued = true;
    requestAnimationFrame(() => {
      this.renderQueued = false;
      this.render();
    });
  }

  render() {
    const state = this.store.state;
    if (state.roomId === null) {
      return;
    }
    const media = state.media;
    if (needsPreparation(media)) {
      this.media.watch(media.ref);
    } else {
      this.media.stop();
      this.preparation = null;
    }

    this.header.render(state);
    this.chat.render(state);
    this.participants.render(state);
    const kind = stageKind(media, this.preparation);
    this.sync.update(state, kind === 'ready');
    this.player.render(state, this.preparation, this.sync.status.overlay);
    const title = media === null
      ? 'Видео ещё не выбрано'
      : media.title ?? this.sync.status.title ?? fallbackTitle(media);
    this.controls.render(state, this.sync.status, title);

    const count = participantsLabel(state.participants.length);
    $('people-summary').textContent = count;
    $('tab-people-count').textContent = `${state.participants.length}/${state.capacity}`;
    if ($('media-title').textContent !== title) {
      $('media-title').textContent = title;
    }
    $('media-platform').textContent = media === null
      ? 'Комната для совместного просмотра'
      : `Сейчас смотрим вместе · ${media.platform}`;
    $('change-media').hidden = !(isHost(state) && media !== null);
    $('conn-banner').hidden = state.connection !== 'reconnecting';
    document.title = `${media === null ? 'Комната' : title} — Смотреть вместе — ClipHunter`;
  }
}

new WatchApp().init();
