import assert from 'node:assert/strict';
import { fallbackTitle, needsPreparation, serverFallbackUrl } from '../../public/assets/js/watch/kinds.js';

Deno.test('only files wait for the server', () => {
  assert.ok(needsPreparation({ kind: 'file' }));
  for (const kind of ['youtube', 'vk', 'twitch', 'aniliberty']) {
    assert.ok(!needsPreparation({ kind }), kind);
  }
  assert.ok(!needsPreparation(null));
});

Deno.test('server fallback exists for embeds that yt-dlp can prepare', () => {
  assert.equal(
    serverFallbackUrl({ kind: 'youtube', ref: 'dQw4w9WgXcQ' }),
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
  );
  assert.equal(
    serverFallbackUrl({ kind: 'vk', ref: '-22822305_456241864' }),
    'https://vkvideo.ru/video-22822305_456241864',
  );
  assert.equal(
    serverFallbackUrl({ kind: 'twitch', ref: 'video:2345678901' }),
    'https://www.twitch.tv/videos/2345678901',
  );
  assert.equal(serverFallbackUrl({ kind: 'twitch', ref: 'channel:shroud' }), null, 'live streams');
  assert.equal(serverFallbackUrl({ kind: 'aniliberty', ref: '1:x' }), null);
  assert.equal(serverFallbackUrl({ kind: 'file', ref: 'a'.repeat(32) }), null);
  assert.equal(serverFallbackUrl(null), null);
});

Deno.test('fallback titles name the platform', () => {
  assert.equal(fallbackTitle({ kind: 'twitch', ref: 'channel:shroud' }), 'Эфир shroud на Twitch');
  assert.equal(fallbackTitle({ kind: 'twitch', ref: 'video:1' }), 'Запись с Twitch');
  assert.equal(fallbackTitle({ kind: 'vk', ref: '1_1' }), 'Видео ВКонтакте');
});
