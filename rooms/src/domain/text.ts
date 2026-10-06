/**
 * Sanitization of user-provided text (names, chat) and of titles from tickets.
 *
 * Output is plain text: the client renders it with textContent only. What we strip here is what
 * makes text lie about itself: control characters, bidi overrides (U+202E "gpj.exe"), invisible
 * format characters. Zero-width joiners survive only inside emoji sequences.
 */

export const NAME_MAX = 24;
export const CHAT_MAX = 500;
export const TITLE_MAX = 200;

/** Names nobody may take: the client shows "Вы" for oneself. */
const RESERVED_NAMES = new Set(['вы']);

// Cc (controls), Cf (format: bidi, zero-width, BOM, soft hyphen, tags) except U+200D (handled below).
const INVISIBLE = /[\p{Cc}\p{Cf}]/gu;
const ZWJ = '\u200D';
const LONE_ZWJ = /(?<!\p{Extended_Pictographic}[\uFE0E\uFE0F]?)\u200D|\u200D(?!\p{Extended_Pictographic})/gu;
const WHITESPACE = /[\s\p{Zs}\p{Zl}\p{Zp}]+/gu;

/**
 * NFC, well-formed UTF-16, invisible characters removed, whitespace (including newlines) collapsed
 * to single spaces, trimmed.
 */
export function cleanText(input: string): string {
  return input
    .toWellFormed()
    .normalize('NFC')
    .replace(LONE_ZWJ, '')
    .replace(INVISIBLE, (ch) => (ch === ZWJ ? ZWJ : ch === '\t' || ch === '\n' || ch === '\r' ? ' ' : ''))
    .replace(WHITESPACE, ' ')
    .trim();
}

/** Length in code points (what a user perceives far better than UTF-16 units). */
export function codePointLength(text: string): number {
  let n = 0;
  for (const _ of text) {
    n++;
  }

  return n;
}

/** A display name, or null if invalid (empty, too long, reserved). */
export function sanitizeName(input: unknown): string | null {
  if (typeof input !== 'string' || input.length > NAME_MAX * 8) {
    return null;
  }
  const name = cleanText(input);
  const length = codePointLength(name);
  if (length < 1 || length > NAME_MAX || RESERVED_NAMES.has(nameKey(name))) {
    return null;
  }

  return name;
}

/**
 * The key for name uniqueness within a room: case-insensitive, compatibility-normalized and
 * blind to joiners and emoji variation selectors, so look-alike variants collide.
 */
export function nameKey(name: string): string {
  return name.normalize('NFKC').replace(/[\u200D\uFE0E\uFE0F]/g, '').toLocaleLowerCase('ru').replace(
    /ё/g,
    'е',
  );
}

/** A chat message, or null if invalid (empty or longer than CHAT_MAX). */
export function sanitizeChat(input: unknown): string | null {
  if (typeof input !== 'string' || input.length > CHAT_MAX * 8) {
    return null;
  }
  const text = cleanText(input);
  const length = codePointLength(text);

  return length >= 1 && length <= CHAT_MAX ? text : null;
}

/** A media title from a ticket: cleaned again (defense in depth), cut to TITLE_MAX, null if empty. */
export function sanitizeTitle(input: string): string | null {
  const text = cleanText(input);
  if (text === '') {
    return null;
  }

  return codePointLength(text) <= TITLE_MAX ? text : [...text].slice(0, TITLE_MAX).join('').trimEnd();
}
