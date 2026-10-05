import { App } from './app.js';

function initHeader() {
  const header = document.querySelector('.site-header');
  const toggle = document.querySelector('.nav__toggle');
  const list = document.getElementById('nav-list');
  if (!header) {
    return;
  }

  const onScroll = () => {
    header.dataset.scrolled = String(window.scrollY > 4);
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  if (!toggle || !list) {
    return;
  }

  const setOpen = (open) => {
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? 'Закрыть меню' : 'Открыть меню');
    list.dataset.open = String(open);
  };

  toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
  list.addEventListener('click', (event) => {
    if (event.target.closest('a')) {
      setOpen(false);
    }
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
      setOpen(false);
      toggle.focus();
    }
  });
}

initHeader();

const root = document.getElementById('app');
if (root) {
  new App(root).init();
}
