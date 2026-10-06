import assert from 'node:assert/strict';
import {
  avatarLetter,
  formatTime,
  participantsLabel,
  plural,
  roomLabel,
  spokenDuration,
} from '../../public/assets/js/watch/text.js';

Deno.test('russian plurals', () => {
  const forms = ['участник', 'участника', 'участников'];
  const cases = [
    [0, 2],
    [1, 0],
    [2, 1],
    [4, 1],
    [5, 2],
    [11, 2],
    [12, 2],
    [14, 2],
    [21, 0],
    [22, 1],
    [25, 2],
    [101, 0],
    [111, 2],
  ];
  for (const [n, index] of cases) {
    assert.equal(plural(n, forms), forms[index], String(n));
  }
  assert.equal(participantsLabel(4), '4 участника');
  assert.equal(participantsLabel(5), '5 участников');
});

Deno.test('media time formats', () => {
  assert.equal(formatTime(0), '0:00');
  assert.equal(formatTime(9.9), '0:09');
  assert.equal(formatTime(768), '12:48');
  assert.equal(formatTime(3136), '52:16');
  assert.equal(formatTime(4000), '1:06:40');
  assert.equal(formatTime(Number.NaN), '0:00');
  assert.equal(formatTime(-5), '0:00');
});

Deno.test('spoken durations for screen readers', () => {
  assert.equal(spokenDuration(768), '12 минут 48 секунд');
  assert.equal(spokenDuration(61), '1 минута 1 секунда');
  assert.equal(spokenDuration(3723), '1 час 2 минуты 3 секунды');
  assert.equal(spokenDuration(0), '0 секунд');
});

Deno.test('avatar letters keep emoji and combined characters whole', () => {
  assert.equal(avatarLetter('маша'), 'М');
  assert.equal(avatarLetter('  дима'), 'Д');
  assert.equal(
    avatarLetter('\u{1F468}\u200D\u{1F469}\u200D\u{1F467} семья'),
    '\u{1F468}\u200D\u{1F469}\u200D\u{1F467}',
  );
  assert.equal(avatarLetter('\u{1F1F7}\u{1F1FA} флаг'), '\u{1F1F7}\u{1F1FA}');
  assert.equal(avatarLetter('e\u0301mile'), 'E\u0301');
  assert.equal(avatarLetter(''), '?');
});

Deno.test('room label shows five characters', () => {
  assert.equal(roomLabel('7F4K2QX9MD3P'), 'Комната #7F4K2');
});
