// Room header: the room badge and the mobile menu.

import { roomLabel } from '../text.js';

export class HeaderView {
  /** @param {{badge: HTMLElement, menuToggle: HTMLButtonElement, menu: HTMLElement}} elements */
  constructor(elements) {
    this.el = elements;
    elements.menuToggle.addEventListener('click', (event) => {
      event.stopPropagation();
      this.setMenu(elements.menu.hidden);
    });
    elements.menu.addEventListener('click', (event) => {
      if (event.target.closest('button')) {
        this.setMenu(false);
      }
    });
    document.addEventListener('click', (event) => {
      if (!elements.menu.hidden && !elements.menu.contains(event.target)) {
        this.setMenu(false);
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !elements.menu.hidden) {
        this.setMenu(false);
        elements.menuToggle.focus();
      }
    });
  }

  render(state) {
    const label = state.roomId === null ? 'Комната' : roomLabel(state.roomId);
    if (this.el.badge.textContent !== label) {
      this.el.badge.textContent = label;
    }
  }

  setMenu(open) {
    this.el.menu.hidden = !open;
    this.el.menuToggle.setAttribute('aria-expanded', String(open));
    if (open) {
      this.el.menu.querySelector('button')?.focus();
    }
  }
}
