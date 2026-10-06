// Emoji picker: a small popover of common emoji that inserts at the caret of the chat input.

import { el } from './dom.js';

const EMOJI = [
  '😀',
  '😂',
  '🤣',
  '😊',
  '😍',
  '🥰',
  '😎',
  '🤔',
  '😮',
  '😱',
  '😢',
  '😭',
  '😡',
  '🥱',
  '😴',
  '🤯',
  '👍',
  '👎',
  '👏',
  '🙌',
  '🙏',
  '💪',
  '🔥',
  '❤\uFE0F',
  '💔',
  '✨',
  '🎉',
  '🍿',
  '🎬',
  '🚀',
  '💯',
  '👀',
];

export class EmojiPicker {
  /**
   * @param {HTMLButtonElement} toggle
   * @param {HTMLElement} panel
   * @param {HTMLInputElement} input
   */
  constructor(toggle, panel, input) {
    this.toggle = toggle;
    this.panel = panel;
    this.input = input;

    for (const emoji of EMOJI) {
      panel.append(el('button', {
        className: 'emoji__item',
        text: emoji,
        attrs: { type: 'button', 'aria-label': `Вставить ${emoji}` },
        on: { click: () => this.insert(emoji) },
      }));
    }
    toggle.addEventListener('click', () => this.setOpen(panel.hidden));
    panel.addEventListener('keydown', (event) => this.navigate(event));
    document.addEventListener('click', (event) => {
      if (!panel.hidden && !panel.contains(event.target) && !toggle.contains(event.target)) {
        this.setOpen(false);
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !panel.hidden) {
        this.setOpen(false);
        toggle.focus();
      }
    });
  }

  setOpen(open) {
    this.panel.hidden = !open;
    this.toggle.setAttribute('aria-expanded', String(open));
    if (open) {
      this.panel.querySelector('button')?.focus();
    }
  }

  insert(emoji) {
    const { input } = this;
    const start = input.selectionStart ?? input.value.length;
    const end = input.selectionEnd ?? input.value.length;
    const next = input.value.slice(0, start) + emoji + input.value.slice(end);
    if (next.length > input.maxLength && input.maxLength > 0) {
      return;
    }
    input.value = next;
    const caret = start + emoji.length;
    input.focus();
    input.setSelectionRange(caret, caret);
    this.setOpen(false);
  }

  /** Arrow keys move within the 8-column grid. */
  navigate(event) {
    const items = [...this.panel.querySelectorAll('button')];
    const index = items.indexOf(document.activeElement);
    if (index < 0) {
      return;
    }
    const step = { ArrowRight: 1, ArrowLeft: -1, ArrowDown: 8, ArrowUp: -8 }[event.key];
    if (step !== undefined) {
      event.preventDefault();
      items[(index + step + items.length) % items.length]?.focus();
    }
  }
}
