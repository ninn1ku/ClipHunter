import assert from 'node:assert/strict';
import { FakeClock } from '../src/clock.ts';
import { RoomRegistry } from '../src/domain/registry.ts';
import { ROOM_ID_PATTERN } from '../src/security/ids.ts';

const LIMITS = {
  maxRooms: 3,
  maxParticipants: 5,
  emptyTtlMs: 30 * 60_000,
  maxAgeMs: 12 * 3_600_000,
  reconnectGraceMs: 45_000,
};

Deno.test('rooms are created up to the global limit', () => {
  const registry = new RoomRegistry(LIMITS, new FakeClock());
  const rooms = [registry.create(), registry.create(), registry.create()];

  assert.ok(rooms.every((r) => r !== null && ROOM_ID_PATTERN.test(r.id)));
  assert.equal(registry.create(), null);
  assert.equal(registry.size, 3);
  assert.equal(registry.get(rooms[0]?.id), rooms[0]);
  assert.equal(registry.get('../etc/passwd'), undefined);
  assert.equal(registry.get(42), undefined);
});

Deno.test('empty rooms expire after the empty TTL', () => {
  const clock = new FakeClock();
  const registry = new RoomRegistry(LIMITS, clock);
  const room = registry.create();
  assert.ok(room);
  const p = room.join('A', 'aaaaaaaaaaaaaaaa', clock.now());
  assert.ok(p.ok);
  clock.advance(10_000);
  room.leave(p.participant.id, clock.now());

  clock.advance(LIMITS.emptyTtlMs - 1);
  assert.deepEqual(registry.sweep().closed, []);
  clock.advance(1);
  assert.deepEqual(registry.sweep().closed.map((c) => c.reason), ['empty']);
  assert.equal(registry.get(room.id), undefined);
});

Deno.test('grace expiry is reported and starts the empty TTL', () => {
  const clock = new FakeClock();
  const registry = new RoomRegistry(LIMITS, clock);
  const room = registry.create();
  assert.ok(room);
  const p = room.join('A', 'aaaaaaaaaaaaaaaa', clock.now());
  assert.ok(p.ok);
  room.disconnect(p.participant.id, clock.now());

  clock.advance(LIMITS.reconnectGraceMs);
  const sweep = registry.sweep();

  assert.equal(sweep.updates.length, 1);
  assert.equal(sweep.updates[0]?.room, room);
  assert.equal(room.size, 0);
  assert.equal(room.emptySince, clock.now());
  assert.ok(registry.dirty);
});

Deno.test('rooms close at the maximum age even when busy', () => {
  const clock = new FakeClock();
  const registry = new RoomRegistry(LIMITS, clock);
  const room = registry.create();
  assert.ok(room?.join('A', 'aaaaaaaaaaaaaaaa', clock.now()).ok);

  clock.advance(LIMITS.maxAgeMs);

  assert.deepEqual(registry.sweep().closed.map((c) => c.reason), ['expired']);
  assert.equal(registry.size, 0);
});

Deno.test('restore drops rooms that expired while the service was down', () => {
  const clock = new FakeClock();
  const source = new RoomRegistry({ ...LIMITS, maxRooms: 10 }, clock);
  const busy = source.create();
  const empty = source.create();
  const old = source.create();
  assert.ok(busy?.join('A', 'aaaaaaaaaaaaaaaa', clock.now()).ok);
  assert.ok(empty && old);
  const states = source.toStates();
  const oldState = states.find((s) => s.id === old.id);
  assert.ok(oldState);
  oldState.createdAt -= LIMITS.maxAgeMs;
  oldState.participants = busy.toState().participants;

  clock.advance(LIMITS.emptyTtlMs);
  const target = new RoomRegistry(LIMITS, clock);

  assert.equal(target.restore(states), 1);
  assert.ok(target.get(busy.id));
});
