import assert from 'node:assert/strict';
import { looksLikeUrl } from '../../public/assets/js/watch/views/anime-search.js';

Deno.test('links are resolved, everything else is a title to search', () => {
  for (
    const link of [
      'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
      'youtu.be/dQw4w9WgXcQ',
      'vkvideo.ru/video-1_2',
      'twitch.tv/shroud',
      'aniliberty.top/anime/releases/release/one-piece',
      'http://vk.com',
    ]
  ) {
    assert.ok(looksLikeUrl(link), link);
  }
  for (const title of ['Ван-Пис', 'one piece', 'Тетрадь смерти', 'Re:Zero', 'Dr. Stone', 'k-on']) {
    assert.ok(!looksLikeUrl(title), title);
  }
});
