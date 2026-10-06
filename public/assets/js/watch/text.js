// Text helpers for the watch room: avatar letters, Russian plurals, time formats. Pure, no DOM.

const segmenter = typeof Intl !== 'undefined' && 'Segmenter' in Intl
  ? new Intl.Segmenter('ru', { granularity: 'grapheme' })
  : null;

/**
 * The first user-perceived character of a name, upper-cased (emoji and combined characters stay whole).
 * @param {string} name
 * @returns {string}
 */
export function avatarLetter(name) {
  const trimmed = String(name ?? '').trim();
  if (trimmed === '') {
    return '?';
  }
  const first = segmenter === null
    ? Array.from(trimmed)[0]
    : segmenter.segment(trimmed)[Symbol.iterator]().next().value?.segment;

  return (first ?? '?').toLocaleUpperCase('ru');
}

/**
 * Russian plural form: plural(4, ['участник', 'участника', 'участников']) → 'участника'.
 * @param {number} n
 * @param {[string, string, string]} forms one, few, many
 * @returns {string}
 */
export function plural(n, forms) {
  const abs = Math.abs(Math.trunc(n));
  const mod10 = abs % 10;
  const mod100 = abs % 100;
  if (mod10 === 1 && mod100 !== 11) {
    return forms[0];
  }
  if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
    return forms[1];
  }

  return forms[2];
}

/** "4 участника" */
export function participantsLabel(n) {
  return `${n} ${plural(n, ['участник', 'участника', 'участников'])}`;
}

/**
 * Media time: 768 → "12:48", 3136 → "52:16", 4000 → "1:06:40".
 * @param {number} seconds
 * @returns {string}
 */
export function formatTime(seconds) {
  const total = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const pad = (v) => String(v).padStart(2, '0');

  return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}

/**
 * For screen readers: 768 → "12 минут 48 секунд".
 * @param {number} seconds
 * @returns {string}
 */
export function spokenDuration(seconds) {
  const total = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 0;
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  const parts = [];
  if (h > 0) {
    parts.push(`${h} ${plural(h, ['час', 'часа', 'часов'])}`);
  }
  if (m > 0) {
    parts.push(`${m} ${plural(m, ['минута', 'минуты', 'минут'])}`);
  }
  if (s > 0 || parts.length === 0) {
    parts.push(`${s} ${plural(s, ['секунда', 'секунды', 'секунд'])}`);
  }

  return parts.join(' ');
}

/**
 * Wall-clock time of a chat message: "12:36" in the viewer's time zone.
 * @param {number} ms
 * @returns {string}
 */
export function clockTime(ms) {
  const date = new Date(ms);
  const pad = (v) => String(v).padStart(2, '0');

  return `${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** "Комната #7F4K2": the first five characters are enough to tell rooms apart in conversation. */
export function roomLabel(roomId) {
  return `Комната #${String(roomId).slice(0, 5)}`;
}
