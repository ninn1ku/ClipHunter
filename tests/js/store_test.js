import assert from 'node:assert/strict';
import { CHAT_LIMIT, initialState, isHost, reduce, self, Store } from '../../public/assets/js/watch/store.js';

const P = (id, extra = {}) => ({
  id,
  name: id,
  color: 0,
  isHost: false,
  connected: true,
  status: 'idle',
  joinedAt: 1,
  ...extra,
});
const playback = (seq, extra = {}) => ({
  status: 'paused',
  positionSec: 0,
  updatedAt: 1,
  rate: 1,
  seq,
  by: null,
  ...extra,
});
const message = (id, extra = {}) => ({
  id,
  kind: 'user',
  participantId: 'A',
  name: 'A',
  color: 0,
  text: id,
  ts: 1,
  ...extra,
});

function welcomed() {
  return reduce(initialState(), {
    type: 'welcome',
    you: { participantId: 'B', isHost: false },
    room: {
      roomId: '7F4K2QX9MD3P',
      capacity: 5,
      hostId: 'A',
      participants: [P('A', { isHost: true }), P('B')],
      media: null,
      playback: playback(3),
      chat: [message('1')],
    },
    serverTime: 1,
    protocol: 1,
  });
}

Deno.test('welcome replaces the room state', () => {
  const state = welcomed();

  assert.equal(state.roomId, '7F4K2QX9MD3P');
  assert.equal(state.me, 'B');
  assert.equal(state.connection, 'open');
  assert.equal(self(state)?.id, 'B');
  assert.ok(!isHost(state));
});

Deno.test('participants join, update and leave', () => {
  let state = reduce(welcomed(), { type: 'participant.joined', participant: P('C') });
  assert.deepEqual(state.participants.map((p) => p.id), ['A', 'B', 'C']);

  state = reduce(state, { type: 'participant.updated', participant: P('C', { connected: false }) });
  assert.equal(state.participants.find((p) => p.id === 'C')?.connected, false);

  state = reduce(state, { type: 'participant.left', participantId: 'C', reason: 'timeout' });
  assert.deepEqual(state.participants.map((p) => p.id), ['A', 'B']);
});

Deno.test('host transfer keeps exactly one host', () => {
  let state = reduce(welcomed(), { type: 'participant.updated', participant: P('A') });
  state = reduce(state, { type: 'participant.updated', participant: P('B', { isHost: true }) });

  assert.equal(state.hostId, 'B');
  assert.ok(isHost(state));
  assert.deepEqual(state.participants.map((p) => p.isHost), [false, true]);
});

Deno.test('the host leaving clears hostId until the successor arrives', () => {
  let state = reduce(welcomed(), { type: 'participant.left', participantId: 'A', reason: 'left' });
  assert.equal(state.hostId, null);
  state = reduce(state, { type: 'participant.updated', participant: P('B', { isHost: true }) });
  assert.equal(state.hostId, 'B');
});

Deno.test('playback states with an old seq are ignored', () => {
  const base = welcomed();
  const newer = reduce(base, { type: 'playback.state', playback: playback(4, { status: 'playing' }) });
  const stale = reduce(newer, { type: 'playback.state', playback: playback(4, { status: 'paused' }) });
  const older = reduce(newer, { type: 'playback.state', playback: playback(2) });

  assert.equal(newer.playback.status, 'playing');
  assert.equal(stale, newer);
  assert.equal(older, newer);
});

Deno.test('media changes bring their playback', () => {
  const media = {
    kind: 'youtube',
    ref: 'dQw4w9WgXcQ',
    platform: 'YouTube',
    title: null,
    durationSec: 212,
    thumbnailUrl: null,
    startSec: 0,
    setAt: 5,
  };
  const state = reduce(welcomed(), { type: 'media.changed', media, playback: playback(9) });

  assert.equal(state.media, media);
  assert.equal(state.playback.seq, 9);
});

Deno.test('chat is deduplicated and capped', () => {
  let state = welcomed();
  state = reduce(state, { type: 'chat.message', message: message('1') });
  assert.equal(state.chat.length, 1);
  for (let i = 2; i <= CHAT_LIMIT + 20; i++) {
    state = reduce(state, { type: 'chat.message', message: message(String(i)) });
  }

  assert.equal(state.chat.length, CHAT_LIMIT);
  assert.equal(state.chat.at(-1)?.id, String(CHAT_LIMIT + 20));
});

Deno.test('connection events, reset and unknown messages', () => {
  const state = welcomed();

  assert.equal(reduce(state, { type: 'connection', state: 'reconnecting' }).connection, 'reconnecting');
  assert.deepEqual(reduce(state, { type: 'reset' }), initialState());
  assert.equal(reduce(state, { type: 'pong', t: 1 }), state);
  assert.equal(reduce(state, null), state);
});

Deno.test('the store notifies only on change', () => {
  const store = new Store(welcomed());
  const seen = [];
  const unsubscribe = store.subscribe((next) => seen.push(next.connection));

  store.dispatch({ type: 'pong' });
  store.dispatch({ type: 'connection', state: 'reconnecting' });
  unsubscribe();
  store.dispatch({ type: 'connection', state: 'open' });

  assert.deepEqual(seen, ['reconnecting']);
});
