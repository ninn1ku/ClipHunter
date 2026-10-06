// Chat panel: message list (role="log"), autoscroll, "new messages" pill, composer with emoji.

import { roomErrorText, systemLine } from '../messages.js';
import { avatarLetter, clockTime, participantsLabel } from '../text.js';
import { el } from './dom.js';
import { EmojiPicker } from './emoji.js';

/** Within this many pixels of the bottom the list follows new messages. */
const STICKY_PX = 48;

export class ChatView {
  /**
   * @param {{list: HTMLOListElement, form: HTMLFormElement, input: HTMLInputElement, pill: HTMLButtonElement,
   *   sub: HTMLElement, emojiToggle: HTMLButtonElement, emojiPanel: HTMLElement}} elements
   * @param {{send: (text: string) => Promise<void>, toast: (message: string, options?: object) => void}} actions
   */
  constructor(elements, actions) {
    this.el = elements;
    this.actions = actions;
    this.rendered = new Set();
    this.me = null;
    new EmojiPicker(elements.emojiToggle, elements.emojiPanel, elements.input);

    elements.form.addEventListener('submit', (event) => {
      event.preventDefault();
      void this.submit();
    });
    elements.list.addEventListener('scroll', () => {
      if (this.atBottom()) {
        elements.pill.hidden = true;
      }
    }, { passive: true });
    elements.pill.addEventListener('click', () => this.scrollToBottom(true));
  }

  /** @param {import('../store.js').RoomState} state */
  render(state) {
    this.me = state.me;
    this.el.sub.textContent = participantsLabel(state.participants.length);
    this.el.input.disabled = state.connection !== 'open';

    // A fresh welcome (join or resume) may replace history: rebuild when ids no longer line up.
    const ids = state.chat.map((m) => m.id);
    const stale = [...this.rendered].some((id) => !ids.includes(id));
    if (stale) {
      this.el.list.replaceChildren();
      this.rendered.clear();
    }

    const follow = this.atBottom();
    let added = 0;
    for (const message of state.chat) {
      if (!this.rendered.has(message.id)) {
        this.el.list.append(this.item(message));
        this.rendered.add(message.id);
        added++;
      }
    }
    while (this.el.list.children.length > 100) {
      this.el.list.firstElementChild?.remove();
    }
    if (added === 0) {
      return;
    }
    const mine = state.chat.at(-1)?.participantId === state.me && state.chat.at(-1)?.kind === 'user';
    if (follow || mine || stale) {
      this.scrollToBottom(false);
    } else {
      this.el.pill.hidden = false;
    }
  }

  async submit() {
    const text = this.el.input.value.trim();
    if (text === '') {
      return;
    }
    try {
      await this.actions.send(text);
      this.el.input.value = '';
    } catch (e) {
      this.actions.toast(roomErrorText(e?.code), { kind: 'error' });
    }
    this.el.input.focus();
  }

  item(message) {
    if (message.kind === 'system') {
      return el('li', { className: 'chat__system' }, el('span', { text: systemLine(message) }));
    }
    const isMe = message.participantId === this.me;

    return el(
      'li',
      { className: 'chat__message' },
      el('span', {
        className: `avatar avatar--${message.color % 8}`,
        text: avatarLetter(message.name),
        attrs: { 'aria-hidden': 'true' },
      }),
      el(
        'div',
        { className: 'chat__body' },
        el(
          'p',
          { className: 'chat__meta' },
          el('span', { className: 'chat__name', text: isMe ? 'Вы' : message.name }),
          el('time', {
            className: 'chat__time',
            text: clockTime(message.ts),
            attrs: { datetime: new Date(message.ts).toISOString() },
          }),
        ),
        el('p', { className: 'chat__text', text: message.text ?? '' }),
      ),
    );
  }

  atBottom() {
    const list = this.el.list;
    return list.scrollHeight - list.scrollTop - list.clientHeight <= STICKY_PX;
  }

  scrollToBottom(smooth) {
    const list = this.el.list;
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    list.scrollTo({ top: list.scrollHeight, behavior: smooth && !reduce ? 'smooth' : 'auto' });
    this.el.pill.hidden = true;
  }
}
