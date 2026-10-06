import assert from 'node:assert/strict';
import { pickStream } from '../../public/assets/js/watch/players/aniliberty.js';

const streams = [{ height: 480 }, { height: 1080 }, { height: 720 }];

Deno.test('a member starts with the saved quality or the nearest lower one', () => {
  assert.equal(pickStream(streams, 720)?.height, 720);
  assert.equal(pickStream(streams, 1080)?.height, 1080);
  assert.equal(pickStream(streams, 900)?.height, 720);
  assert.equal(pickStream(streams, 360)?.height, 480, 'nothing lower: the smallest');
  assert.equal(pickStream([], 720), null);
});
