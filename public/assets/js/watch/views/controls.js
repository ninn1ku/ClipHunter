// Our own player controls (the YouTube iframe runs with controls=0): title chip, sync badge,
// centre play/pause, progress slider, volume, time, settings menu and fullscreen.
// Shortcuts while the player has focus: Space/K play-pause, ←/→ ±5 s, M mute, F fullscreen.

import { formatTime, spokenDuration } from '../text.js';
import { el, icon } from './dom.js';

const IDLE_MS = 3000;
const BADGE_TEXT = { synced: 'Синхронизировано', syncing: 'Синхронизация…', offline: 'Нет соединения' };

export class PlayerControls {
  /**
   * @param {HTMLElement} player the .player container
   * @param {import('../sync.js').SyncController} sync
   * @param {{isHost: () => boolean, changeMedia: () => void}} actions
   */
  constructor(player, sync, actions) {
    this.player = player;
    this.sync = sync;
    this.actions = actions;
    this.dragging = false;
    this.idleTimer = null;
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

    this.menuItems = el(
      'ul',
      { className: 'player__menu', attrs: { role: 'list', hidden: true } },
      el(
        'li',
        {},
        el('button', {
          text: 'Пересинхронизировать',
          attrs: { type: 'button' },
          on: { click: () => this.menu(false, () => this.sync.resync()) },
        }),
      ),
      this.changeItem = el(
        'li',
        {},
        el('button', {
          text: 'Сменить видео',
          attrs: { type: 'button' },
          on: { click: () => this.menu(false, () => this.actions.changeMedia()) },
        }),
      ),
    );
    this.settings = el('button', {
      className: 'player__btn',
      attrs: { type: 'button', 'aria-label': 'Настройки', 'aria-expanded': 'false', 'aria-haspopup': 'true' },
      on: {
        click: (event) => {
          event.stopPropagation();
          this.menu(this.menuItems.hidden);
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
          el('div', { className: 'player__settings' }, this.settings, this.menuItems),
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
      this.time.textContent = `${formatTime(Number(this.progress.value))} / ${
        formatTime(Number(this.progress.max))
      }`;
    });
    this.progress.addEventListener('change', () => {
      this.dragging = false;
      this.sync.seekTo(Number(this.progress.value));
    });

    this.player.addEventListener('keydown', (event) => this.onKey(event));
    const wake = () => this.wake();
    this.player.addEventListener('pointermove', wake);
    this.player.addEventListener('pointerdown', wake);
    this.player.addEventListener('focusin', wake);
    document.addEventListener('fullscreenchange', () => this.renderFullscreen());
    document.addEventListener('click', (event) => {
      if (!this.menuItems.hidden && !this.menuItems.contains(event.target)) {
        this.menu(false);
      }
    });
  }

  onKey(event) {
    const target = /** @type {HTMLElement} */ (event.target);
    const onSlider = target instanceof HTMLInputElement && target.type === 'range';
    const inMenu = this.menuItems.contains(target);
    if (event.altKey || event.ctrlKey || event.metaKey || inMenu) {
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
      case 'Escape':
        this.menu(false);
        break;
    }
    this.wake();
  }

  menu(open, then) {
    this.menuItems.hidden = !open;
    this.settings.setAttribute('aria-expanded', String(open));
    if (open) {
      this.menuItems.querySelector('button')?.focus();
    }
    then?.();
  }

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

  wake() {
    this.player.dataset.active = 'true';
    clearTimeout(this.idleTimer);
    this.idleTimer = setTimeout(() => {
      if (!this.player.contains(document.activeElement) || document.activeElement === this.player) {
        this.player.dataset.active = 'false';
      }
    }, IDLE_MS);
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

    this.changeItem.hidden = !this.actions.isHost();
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
