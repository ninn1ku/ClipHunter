import assert from 'node:assert/strict';
import {
  CHAT_MAX,
  cleanText,
  codePointLength,
  nameKey,
  sanitizeChat,
  sanitizeName,
  sanitizeTitle,
} from '../src/domain/text.ts';

Deno.test('names: control, bidi and zero-width characters are removed', () => {
  assert.equal(sanitizeName('  Маша  '), 'Маша');
  assert.equal(sanitizeName('Ma\u0000sha'), 'Masha');
  assert.equal(sanitizeName('\u202Egpj.exe'), 'gpj.exe');
  assert.equal(sanitizeName('a\u2066b\u2069c'), 'abc');
  assert.equal(sanitizeName('Ма\u200Bша\uFEFF'), 'Маша');
  assert.equal(sanitizeName('Ма\u200Dша'), 'Маша', 'a joiner outside an emoji sequence is dropped');
  assert.equal(sanitizeName('Иван\n\tПетров'), 'Иван Петров');
  assert.equal(sanitizeName('a     b'), 'a b');
  assert.equal(sanitizeName('a\u2028b'), 'a b');
});

Deno.test('names: NFC and emoji', () => {
  assert.equal(sanitizeName('Андреи\u0306'), 'Андрей', 'и + combining breve becomes й');
  assert.equal(sanitizeName('e\u0301'), 'é');
  const family = '\u{1F468}\u200D\u{1F469}\u200D\u{1F467}';
  assert.equal(sanitizeName(`Семья ${family}`), `Семья ${family}`, 'joiners inside emoji survive');
  assert.equal(sanitizeName('❤\uFE0F Кот'), '❤\uFE0F Кот');
});

Deno.test('names: length is counted in code points', () => {
  assert.equal(sanitizeName('я'.repeat(24)), 'я'.repeat(24));
  assert.equal(sanitizeName('я'.repeat(25)), null);
  assert.equal(
    sanitizeName('\u{1F600}'.repeat(24)),
    '\u{1F600}'.repeat(24),
    'an emoji is one character, not two',
  );
  assert.equal(sanitizeName('\u{1F600}'.repeat(25)), null);
  assert.equal(codePointLength('\u{1F600}a'), 2);
});

Deno.test('names: empty, reserved and non-string input is rejected', () => {
  assert.equal(sanitizeName(''), null);
  assert.equal(sanitizeName('   '), null);
  assert.equal(sanitizeName('\u200B\u202E'), null);
  assert.equal(sanitizeName('Вы'), null);
  assert.equal(sanitizeName(' вы '), null);
  assert.equal(sanitizeName(42), null);
  assert.equal(sanitizeName(null), null);
  assert.equal(sanitizeName(['Маша']), null);
  assert.equal(sanitizeName('a'.repeat(10_000)), null);
});

Deno.test('name keys collide for look-alike variants', () => {
  assert.equal(nameKey('Маша'), nameKey('МАША'));
  assert.equal(nameKey('Алёна'), nameKey('Алена'));
  assert.equal(nameKey('Ｍａｓｈａ'), nameKey('masha'), 'full-width letters');
  assert.equal(nameKey('❤\uFE0F'), nameKey('❤'));
  assert.notEqual(nameKey('Маша'), nameKey('Миша'));
});

Deno.test('chat: newlines become spaces, length 1..500', () => {
  assert.equal(sanitizeChat('привет\nкак дела?'), 'привет как дела?');
  assert.equal(
    sanitizeChat('<script>alert(1)</script>'),
    '<script>alert(1)</script>',
    'markup is plain text',
  );
  assert.equal(sanitizeChat('x'.repeat(CHAT_MAX)), 'x'.repeat(CHAT_MAX));
  assert.equal(sanitizeChat('x'.repeat(CHAT_MAX + 1)), null);
  assert.equal(sanitizeChat(' \n '), null);
  assert.equal(sanitizeChat({ text: 'hi' }), null);
});

Deno.test('lone surrogates are repaired, not passed through', () => {
  const text = cleanText('a\uD800b');

  assert.ok(text.isWellFormed());
  assert.equal(codePointLength(text), 3);
});

Deno.test('titles are cleaned and cut to 200 characters', () => {
  assert.equal(sanitizeTitle('  The \u202ELast\u0000 of Us  '), 'The Last of Us');
  assert.equal(sanitizeTitle('\u200B'), null);
  assert.equal(codePointLength(sanitizeTitle('ж'.repeat(300)) ?? ''), 200);
});
