import assert from 'node:assert/strict';
import { nextEpisode } from '../../public/assets/js/watch/views/episodes.js';

Deno.test('the next playable episode follows the current one', () => {
  const episodes = [
    { id: 'a', playable: true },
    { id: 'b', playable: false },
    { id: 'c', playable: true },
  ];
  assert.equal(nextEpisode(episodes, 'a')?.id, 'c', 'skips episodes without video');
  assert.equal(nextEpisode(episodes, 'c'), null, 'the last one');
  assert.equal(nextEpisode(episodes, 'zzz'), null, 'unknown current');
});
