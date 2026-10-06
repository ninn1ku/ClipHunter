// Participants list (panel and modal): avatar, name ("Вы"), crown for the host, presence,
// a host-only menu for others, and "Пригласить друга".

import { presenceText } from '../messages.js';
import { avatarLetter } from '../text.js';
import { el, icon } from './dom.js';

export class ParticipantsView {
  /**
   * @param {Array<{list: HTMLUListElement, count: HTMLElement}>} targets
   * @param {{invite: () => void, makeHost: (id: string, name: string) => void, kick: (id: string, name: string) => void}} actions
   */
  constructor(targets, actions) {
    this.targets = targets;
    this.actions = actions;
    this.openMenu = null;

    document.addEventListener('click', (event) => {
      if (this.openMenu && !this.openMenu.contains(event.target)) {
        this.closeMenu();
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && this.openMenu) {
        const toggle = this.openMenu.querySelector('.people__menu-toggle');
        this.closeMenu();
        toggle?.focus();
      }
    });
  }

  /** @param {import('../store.js').RoomState} state */
  render(state) {
    const amHost = state.me !== null && state.hostId === state.me;
    const full = state.participants.length >= state.capacity;
    // Keep an open menu open across re-renders only if its participant is still there.
    const openFor = this.openMenu?.dataset.participant ?? null;
    this.openMenu = null;

    for (const { list, count } of this.targets) {
      count.textContent = `${state.participants.length} / ${state.capacity}`;
      const sorted = [...state.participants].sort((
        a,
        b,
      ) => (a.id === state.me ? -1 : b.id === state.me ? 1 : a.joinedAt - b.joinedAt));
      list.replaceChildren(
        ...sorted.map((p) => this.row(p, p.id === state.me, amHost, openFor === p.id)),
        this.inviteRow(full),
      );
    }
  }

  row(p, isMe, amHost, menuOpen) {
    const name = el('span', { className: 'people__name' }, el('span', { text: isMe ? 'Вы' : p.name }));
    if (p.isHost) {
      name.append(
        el(
          'span',
          { className: 'people__crown', attrs: { title: 'Ведущий' } },
          icon('crown'),
          el('span', { className: 'visually-hidden', text: ', ведущий' }),
        ),
      );
    }
    const row = el(
      'li',
      { className: `people__row${p.connected ? '' : ' is-offline'}` },
      el('span', {
        className: `avatar avatar--${p.color % 8}`,
        text: avatarLetter(isMe ? 'Я' : p.name),
        attrs: { 'aria-hidden': 'true' },
      }),
      el(
        'span',
        { className: 'people__info' },
        name,
        el('span', { className: 'people__status', text: presenceText(p) }),
      ),
    );
    if (amHost && !isMe) {
      row.append(this.menu(p, menuOpen));
    }

    return row;
  }

  menu(p, open) {
    const wrap = el('div', { className: 'people__menu', dataset: { participant: p.id } });
    const list = el(
      'ul',
      { className: 'people__menu-list', attrs: { role: 'list', hidden: !open } },
      el(
        'li',
        {},
        el('button', {
          text: 'Сделать ведущим',
          attrs: { type: 'button' },
          on: { click: () => this.pick(() => this.actions.makeHost(p.id, p.name)) },
        }),
      ),
      el(
        'li',
        {},
        el('button', {
          className: 'is-danger',
          text: 'Удалить из комнаты',
          attrs: { type: 'button' },
          on: { click: () => this.pick(() => this.actions.kick(p.id, p.name)) },
        }),
      ),
    );
    const toggle = el(
      'button',
      {
        className: 'w-icon-btn people__menu-toggle',
        attrs: {
          type: 'button',
          'aria-label': `Действия: ${p.name}`,
          'aria-expanded': String(open),
          'aria-haspopup': 'true',
        },
        on: {
          click: (event) => {
            event.stopPropagation();
            const willOpen = list.hidden;
            this.closeMenu();
            if (willOpen) {
              list.hidden = false;
              toggle.setAttribute('aria-expanded', 'true');
              this.openMenu = wrap;
              list.querySelector('button')?.focus();
            }
          },
        },
      },
      icon('kebab'),
    );
    wrap.append(toggle, list);
    if (open) {
      this.openMenu = wrap;
    }

    return wrap;
  }

  pick(action) {
    this.closeMenu();
    action();
  }

  closeMenu() {
    if (this.openMenu) {
      this.openMenu.querySelector('.people__menu-list')?.setAttribute('hidden', '');
      this.openMenu.querySelector('.people__menu-toggle')?.setAttribute('aria-expanded', 'false');
      this.openMenu = null;
    }
  }

  inviteRow(full) {
    return el(
      'li',
      { className: 'people__invite' },
      el(
        'button',
        {
          className: 'people__invite-btn',
          attrs: { type: 'button', disabled: full },
          on: { click: () => this.actions.invite() },
        },
        el('span', { className: 'people__invite-icon', attrs: { 'aria-hidden': 'true' } }, icon('plus')),
        el('span', { text: 'Пригласить друга' }),
        full ? el('span', { className: 'visually-hidden', text: ' — в комнате уже 5 человек' }) : null,
      ),
    );
  }
}
