// Our own player controls (the YouTube iframe runs with controls=0): title chip, sync badge,
// centre play/pause, progress slider, volume, time, captions, settings menu and fullscreen.
// Shortcuts while the player has focus: Space/K play-pause, ←/→ ±5 s, M mute, F fullscreen, C captions.
//
// A transparent surface covers the video: it receives pointer movement even over the YouTube
// iframe (which would swallow it), toggles play on click and fullscreen on double click.

import { formatTime, spokenDuration } from '../text.js';
import { el, icon } from './dom.js';

const IDLE_MS = 3000;
const BADGE_TEXT = { synced: 'Синхронизировано', syncing: 'Синхронизация…', offline: 'Нет соединения' };

/**
 * @typedef {{current: string|null, options: Array<{id: string, label: string, note?: string}>, note?: string,
 *   busy?: boolean}} QualityMenu
 */

export class PlayerControls {
  /**
   * @param {HTMLElement} player the .player container
   * @param {import('../sync.js').SyncController} sync
   * @param {{isHost: () => boolean, changeMedia: () => void, quality: () => QualityMenu|null,
   *   selectQuality: (id: string) => void}} actions
   */
  constructor(player, sync, actions) {
    this.player = player;
    this.sync = sync;
    this.actions = actions;
    this.dragging = false;
    this.idleTimer = null;
    this.wasPlaying = false;
    this.lastPointer = 'mouse';
    this.build();
    this.bind();
  }

  build() {
    this.title = el('span', { className: 'player__chip-title' });
    this.chipTime = el('span', { className: 'player__chip-time' });
    this.badge = el(
      'span',
      { className: 'sync-badge', attrs: { role: 'status' } },
      el('span', { className: 'sync-badge__dot', attrs: { 'aria-hidden': 'true' } }),
      el('span', { className: 'sync-badge__text' }),
    );

    this.surface = el('div', { className: 'player__surface', attrs: { 'aria-hidden': 'true' } });
    this.center = el('button', {
      className: 'player__center',
      attrs: { type: 'button', 'aria-label': 'Смотреть' },
      on: { click: () => this.sync.togglePlay() },
    });

    this.progress = el('input', {
      className: 'player__progress',
      attrs: { type: 'range', min: '0', max: '1', step: '0.1', value: '0', 'aria-label': 'Позиция видео' },
    });
    this.playButton = el('button', {
      className: 'player__btn',
      attrs: { type: 'button' },
      on: { click: () => this.sync.togglePlay() },
    });
    this.muteButton = el('button', {
      className: 'player__btn',
      attrs: { type: 'button' },
      on: { click: () => this.sync.toggleMute() },
    });
    this.volume = el('input', {
      className: 'player__volume',
      attrs: { type: 'range', min: '0', max: '1', step: '0.05', value: '1', 'aria-label': 'Громкость' },
      on: { input: () => this.sync.setVolume(Number(this.volume.value)) },
    });
    this.time = el('span', { className: 'player__time', attrs: { 'aria-hidden': 'true' } });

    this.captionsMenu = el('ul', { className: 'player__menu', attrs: { role: 'list', hidden: true } });
    this.captionsButton = el('button', {
      className: 'player__btn',
      attrs: {
        type: 'button',
        'aria-label': 'Субтитры',
        'aria-expanded': 'false',
        'aria-haspopup': 'true',
        hidden: true,
      },
      on: {
        click: (event) => {
          event.stopPropagation();
          this.toggleMenu('captions');
        },
      },
    }, icon('captions'));

    this.settingsMenu = el('ul', { className: 'player__menu', attrs: { role: 'list', hidden: true } });
    this.settings = el('button', {
      className: 'player__btn',
      attrs: { type: 'button', 'aria-label': 'Настройки', 'aria-expanded': 'false', 'aria-haspopup': 'true' },
      on: {
        click: (event) => {
          event.stopPropagation();
          this.toggleMenu('settings');
        },
      },
    }, icon('settings'));
    this.fullscreen = el('button', {
      className: 'player__btn',
      attrs: { type: 'button' },
      on: { click: () => this.toggleFullscreen() },
    });

    this.ui = el(
      'div',
      { className: 'player__ui' },
      this.surface,
      el(
        'div',
        { className: 'player__top' },
        el('p', { className: 'player__chip' }, this.title, this.chipTime),
        this.badge,
      ),
      this.center,
      el(
        'div',
        { className: 'player__bar' },
        this.progress,
        el(
          'div',
          { className: 'player__row' },
          this.playButton,
          el('div', { className: 'player__sound' }, this.muteButton, this.volume),
          this.time,
          el('span', { className: 'player__spacer' }),
          el('div', { className: 'player__popup' }, this.captionsButton, this.captionsMenu),
          el('div', { className: 'player__popup' }, this.settings, this.settingsMenu),
          this.fullscreen,
        ),
      ),
    );
    this.player.append(this.ui);
    this.player.tabIndex = 0;
    this.player.setAttribute('aria-label', 'Плеер. Пробел — пауза, стрелки — перемотка на 5 секунд');
  }

  bind() {
    this.progress.addEventListener('input', () => {
      this.dragging = true;
      this.wake();
      this.time.textContent = `${formatTime(Number(this.progress.value))} / ${
        formatTime(Number(this.progress.max))
      }`;
    });
    this.progress.addEventListener('change', () => {
      this.dragging = false;
      this.sync.seekTo(Number(this.progress.value));
      this.wake();
    });

    this.player.addEventListener('keydown', (event) => this.onKey(event));
    this.player.addEventListener('pointermove', (event) => {
      this.lastPointer = event.pointerType;
      if (event.pointerType === 'mouse') {
        this.wake();
      }
    });
    this.player.addEventListener('pointerdown', (event) => {
      this.lastPointer = event.pointerType;
    });
    this.player.addEventListener('focusin', () => this.wake());

    // A tap on a touch screen first reveals the controls; a click with a mouse toggles play.
    this.surface.addEventListener('click', () => {
      const hidden = this.player.dataset.active === 'false';
      this.wake();
      if (this.lastPointer === 'touch' && hidden) {
        return;
      }
      this.sync.togglePlay();
    });
    this.surface.addEventListener('dblclick', () => this.toggleFullscreen());

    document.addEventListener('fullscreenchange', () => this.renderFullscreen());
    document.addEventListener('click', (event) => {
      const target = /** @type {Node} */ (event.target);
      if (!this.settingsMenu.hidden && !this.settingsMenu.contains(target)) {
        this.closeMenus();
      }
      if (!this.captionsMenu.hidden && !this.captionsMenu.contains(target)) {
        this.closeMenus();
      }
    });
  }

  onKey(event) {
    const target = /** @type {HTMLElement} */ (event.target);
    const onSlider = target instanceof HTMLInputElement && target.type === 'range';
    const inMenu = this.settingsMenu.contains(target) || this.captionsMenu.contains(target);
    if (event.altKey || event.ctrlKey || event.metaKey) {
      return;
    }
    if (inMenu) {
      if (event.key === 'Escape') {
        this.closeMenus(true);
      }
      return;
    }
    switch (event.key) {
      case ' ':
      case 'k':
      case 'K':
      case 'л':
      case 'Л':
        if (target instanceof HTMLButtonElement && event.key === ' ') {
          return; // Space activates the focused button itself.
        }
        event.preventDefault();
        this.sync.togglePlay();
        break;
      case 'ArrowLeft':
      case 'ArrowRight':
        if (onSlider) {
          return;
        }
        event.preventDefault();
        this.sync.seekBy(event.key === 'ArrowLeft' ? -5 : 5);
        break;
      case 'm':
      case 'M':
      case 'ь':
      case 'Ь':
        this.sync.toggleMute();
        break;
      case 'f':
      case 'F':
      case 'а':
      case 'А':
        this.toggleFullscreen();
        break;
      case 'c':
      case 'C':
      case 'с':
      case 'С':
        if (!this.captionsButton.hidden) {
          this.sync.setCaptions(this.sync.status.captions ? null : this.lastCaptionLanguage());
        }
        break;
      case 'Escape':
        this.closeMenus();
        break;
    }
    this.wake();
  }

  // ---------- Menus ----------

  /** @param {'settings'|'captions'} which */
  toggleMenu(which) {
    const menu = which === 'settings' ? this.settingsMenu : this.captionsMenu;
    const open = menu.hidden;
    this.closeMenus();
    if (!open) {
      return;
    }
    if (which === 'settings') {
      this.fillSettings();
    } else {
      // The track list is known only after the captions module has loaded once.
      this.fillCaptions();
      void this.sync.loadCaptions().then(() => {
        if (!this.captionsMenu.hidden) {
          this.fillCaptions();
        }
      });
    }
    // The player clips its content: the menu scrolls inside the room left above the control bar.
    menu.style.maxHeight = `${Math.max(120, this.player.clientHeight - 76)}px`;
    menu.hidden = false;
    (which === 'settings' ? this.settings : this.captionsButton).setAttribute('aria-expanded', 'true');
    menu.querySelector('button:not([disabled])')?.focus();
    this.wake();
  }

  closeMenus(returnFocus = false) {
    const opened = !this.settingsMenu.hidden
      ? this.settings
      : !this.captionsMenu.hidden
      ? this.captionsButton
      : null;
    this.settingsMenu.hidden = true;
    this.captionsMenu.hidden = true;
    this.settings.setAttribute('aria-expanded', 'false');
    this.captionsButton.setAttribute('aria-expanded', 'false');
    if (returnFocus) {
      opened?.focus();
    }
  }

  menusOpen() {
    return !this.settingsMenu.hidden || !this.captionsMenu.hidden;
  }

  fillSettings() {
    const items = [];
    const quality = this.actions.quality();
    if (quality !== null) {
      items.push(el('li', { className: 'player__menu-label', text: 'Качество' }));
      if (quality.note) {
        items.push(el('li', { className: 'player__menu-note', text: quality.note }));
      }
      for (const option of quality.options) {
        items.push(this.choice(option.label, option.id === quality.current, option.note ?? null, () => {
          this.closeMenus(true);
          this.actions.selectQuality(option.id);
        }));
      }
      items.push(el('li', { className: 'player__menu-sep', attrs: { role: 'presentation' } }));
    }
    items.push(el(
      'li',
      {},
      el('button', {
        text: 'Пересинхронизировать',
        attrs: { type: 'button' },
        on: {
          click: () => {
            this.closeMenus(true);
            this.sync.resync();
          },
        },
      }),
    ));
    if (this.actions.isHost()) {
      items.push(el(
        'li',
        {},
        el('button', {
          text: 'Сменить видео',
          attrs: { type: 'button' },
          on: {
            click: () => {
              this.closeMenus();
              this.actions.changeMedia();
            },
          },
        }),
      ));
    }
    this.settingsMenu.replaceChildren(...items);
  }

  fillCaptions() {
    const state = this.sync.captions() ?? { tracks: [], active: null };
    const items = [
      el('li', { className: 'player__menu-label', text: 'Субтитры' }),
      this.choice('Выключены', state.active === null, null, () => this.pickCaptions(null)),
    ];
    for (const track of state.tracks) {
      items.push(
        this.choice(track.label, state.active === track.code, null, () => this.pickCaptions(track.code)),
      );
    }
    if (state.tracks.length === 0) {
      items.push(
        el('li', {
          className: 'player__menu-note',
          text: 'У этого видео нет субтитров или они ещё загружаются.',
        }),
      );
    }
    this.captionsMenu.replaceChildren(...items);
  }

  pickCaptions(code) {
    if (code !== null) {
      try {
        localStorage.setItem('ch:captions-last', code);
      } catch {
        // Not remembered.
      }
    }
    this.sync.setCaptions(code);
    this.closeMenus(true);
  }

  lastCaptionLanguage() {
    try {
      return localStorage.getItem('ch:captions-last') ?? 'ru';
    } catch {
      return 'ru';
    }
  }

  choice(label, selected, note, onPick) {
    return el(
      'li',
      {},
      el(
        'button',
        {
          className: 'player__choice',
          attrs: { type: 'button', 'aria-pressed': String(selected) },
          on: { click: onPick },
        },
        el(
          'span',
          { className: 'player__choice-mark', attrs: { 'aria-hidden': 'true' } },
          selected ? icon('check') : null,
        ),
        el('span', { text: label }),
        note === null ? null : el('span', { className: 'player__choice-note', text: note }),
      ),
    );
  }

  // ---------- Fullscreen and idle ----------

  async toggleFullscreen() {
    if (document.fullscreenElement) {
      await document.exitFullscreen();
      return;
    }
    if (typeof this.player.requestFullscreen === 'function') {
      try {
        await this.player.requestFullscreen();
        return;
      } catch {
        // Fall through to the native iOS player.
      }
    }
    this.sync.enterNativeFullscreen();
  }

  /** Shows the controls and hides them again after a few idle seconds. */
  wake() {
    this.player.dataset.active = 'true';
    clearTimeout(this.idleTimer);
    this.idleTimer = setTimeout(() => this.idle(), IDLE_MS);
  }

  idle() {
    // Keep them up while a menu is open, the slider is dragged or keyboard focus is in the controls.
    if (this.menusOpen() || this.dragging || this.ui.querySelector(':focus-visible') !== null) {
      this.wake();
      return;
    }
    this.player.dataset.active = 'false';
  }

  /**
   * @param {import('../store.js').RoomState} state
   * @param {import('../sync.js').SyncStatus} status
   * @param {string} title
   */
  render(state, status, title) {
    const duration = status.duration ?? 0;
    const position = Math.min(status.position, duration || status.position);
    const disabled = !status.ready || state.connection !== 'open';

    // Starting playback starts the idle countdown, whatever started it (a click, another member).
    if (status.playing && !this.wasPlaying) {
      this.wake();
    }
    this.wasPlaying = status.playing;

    this.player.dataset.playing = String(status.playing);
    this.title.textContent = title;
    this.chipTime.textContent = `${formatTime(position)} / ${formatTime(duration)}`;

    this.badge.dataset.state = status.badge;
    this.badge.lastElementChild.textContent = BADGE_TEXT[status.badge];

    const playLabel = status.playing ? 'Пауза' : 'Смотреть';
    setIcon(this.center, status.playing ? 'pause' : 'play');
    this.center.setAttribute('aria-label', playLabel);
    this.center.disabled = disabled;
    setIcon(this.playButton, status.playing ? 'pause' : 'play');
    this.playButton.setAttribute('aria-label', playLabel);
    this.playButton.disabled = disabled;

    const muted = status.muted || status.volume === 0;
    setIcon(this.muteButton, muted ? 'mute' : 'volume');
    this.muteButton.setAttribute('aria-label', muted ? 'Включить звук' : 'Выключить звук');
    if (document.activeElement !== this.volume) {
      this.volume.value = String(muted ? 0 : status.volume);
    }

    this.progress.max = String(Math.max(duration, 0.1));
    this.progress.disabled = disabled || duration === 0;
    if (!this.dragging) {
      this.progress.value = String(position);
      this.time.textContent = `${formatTime(position)} / ${formatTime(duration)}`;
    }
    this.progress.setAttribute(
      'aria-valuetext',
      `${spokenDuration(Number(this.progress.value))} из ${spokenDuration(duration)}`,
    );
    this.progress.style.setProperty(
      '--progress',
      `${duration > 0 ? (Number(this.progress.value) / duration) * 100 : 0}%`,
    );

    const captionsSupported = status.captions !== undefined && status.ready;
    this.captionsButton.hidden = !captionsSupported;
    this.captionsButton.dataset.on = String(Boolean(status.captions));
    this.captionsButton.setAttribute('aria-label', status.captions ? 'Субтитры включены' : 'Субтитры');

    this.renderFullscreen();
  }

  renderFullscreen() {
    const active = document.fullscreenElement === this.player;
    setIcon(this.fullscreen, active ? 'exitFullscreen' : 'fullscreen');
    this.fullscreen.setAttribute('aria-label', active ? 'Выйти из полноэкранного режима' : 'Во весь экран');
  }
}

/** Swaps a button's icon only when it changes (render runs twice a second). */
function setIcon(button, name) {
  if (button.dataset.icon !== name) {
    button.dataset.icon = name;
    button.replaceChildren(icon(name));
  }
}
