import assert from 'node:assert/strict';
import { FakeClock } from '../src/clock.ts';
import { RoomRegistry } from '../src/domain/registry.ts';
import { memoryLogger } from '../src/log.ts';
import { readSnapshot, SNAPSHOT_FILE, writeSnapshot } from '../src/persistence/snapshot.ts';

const LIMITS = {
  maxRooms: 10,
  maxParticipants: 5,
  emptyTtlMs: 30 * 60_000,
  maxAgeMs: 12 * 3_600_000,
  reconnectGraceMs: 45_000,
};

async function withDir(fn: (dir: string) => Promise<void>): Promise<void> {
  const dir = await Deno.makeTempDir({ prefix: 'rooms-snapshot-' });
  try {
    await fn(dir);
  } finally {
    await Deno.remove(dir, { recursive: true });
  }
}

Deno.test('a snapshot round-trips rooms without raw tokens', () =>
  withDir(async (dir) => {
    const clock = new FakeClock();
    const registry = new RoomRegistry(LIMITS, clock);
    const room = registry.create();
    assert.ok(room);
    const joined = room.join('Маша', 'aaaaaaaaaaaaaaaa', clock.now());
    assert.ok(joined.ok);
    assert.ok(room.chatMessage(joined.participant.id, 'привет', clock.now()).ok);

    await writeSnapshot(dir, registry.toStates(), clock.now());
    const raw = await Deno.readTextFile(`${dir}/${SNAPSHOT_FILE}`);
    assert.ok(!raw.includes(joined.token));
    if (Deno.build.os !== 'windows') {
      assert.equal((await Deno.stat(`${dir}/${SNAPSHOT_FILE}`)).mode! & 0o777, 0o640);
    }

    const restored = new RoomRegistry(LIMITS, clock);
    assert.equal(restored.restore(await readSnapshot(dir, memoryLogger())), 1);
    const back = restored.get(room.id);
    assert.ok(back);
    assert.equal(back.chat.at(-1)?.text, 'привет');
    assert.ok(back.resume(joined.participant.id, joined.token).ok);
    await assert.rejects(Deno.stat(`${dir}/${SNAPSHOT_FILE}.tmp`), Deno.errors.NotFound);
  }));

Deno.test('missing, corrupt and unknown-version snapshots yield no rooms', () =>
  withDir(async (dir) => {
    const log = memoryLogger();
    assert.deepEqual(await readSnapshot(dir, log), []);
    assert.equal(log.entries.length, 0, 'a missing file is normal on first start');

    await Deno.writeTextFile(`${dir}/${SNAPSHOT_FILE}`, '{not json');
    assert.deepEqual(await readSnapshot(dir, log), []);

    await Deno.writeTextFile(`${dir}/${SNAPSHOT_FILE}`, JSON.stringify({ version: 99, rooms: [] }));
    assert.deepEqual(await readSnapshot(dir, log), []);
    assert.deepEqual(log.entries.map((e) => e.event), ['snapshot.invalid', 'snapshot.invalid']);
  }));

Deno.test('malformed rooms are skipped, valid ones kept', () =>
  withDir(async (dir) => {
    const clock = new FakeClock();
    const registry = new RoomRegistry(LIMITS, clock);
    registry.create();
    const states: unknown[] = registry.toStates();
    states.push({ id: '../../etc', createdAt: 1 }, 'garbage', null);
    await Deno.writeTextFile(
      `${dir}/${SNAPSHOT_FILE}`,
      JSON.stringify({ version: 1, savedAt: 0, rooms: states }),
    );
    const log = memoryLogger();

    const rooms = await readSnapshot(dir, log);

    assert.equal(rooms.length, 1);
    assert.equal(log.entries[0]?.event, 'snapshot.rooms_skipped');
    assert.equal(log.entries[0]?.count, 3);
  }));

Deno.test('media of every kind round-trips; a ref that does not fit its kind is dropped', () =>
  withDir(async (dir) => {
    const clock = new FakeClock();
    const registry = new RoomRegistry(LIMITS, clock);
    const media = [
      { kind: 'vk', ref: '-22822305_456241864' },
      { kind: 'twitch', ref: 'video:2345678901' },
      { kind: 'twitch', ref: 'channel:shroud' },
      { kind: 'aniliberty', ref: '10335:a2eaa868-41e2-486d-81f0-c2f124f82803' },
    ] as const;
    const ids: string[] = [];
    for (const { kind, ref } of media) {
      const room = registry.create();
      assert.ok(room);
      const joined = room.join('Маша', 'aaaaaaaaaaaaaaaa', clock.now());
      assert.ok(joined.ok);
      const set = room.setMedia(joined.participant.id, {
        kind,
        ref,
        platform: 'Test',
        title: null,
        durationSec: null,
        thumbnailUrl: null,
        startSec: 0,
      }, clock.now());
      assert.ok(set.ok);
      ids.push(room.id);
    }
    const states = registry.toStates() as unknown as Array<{ media: { ref: string } | null }>;
    // A tampered snapshot: a VK ref in a Twitch room.
    states.push({ ...states[1]!, id: 'ZZZZZZZZZZZZ', media: { ...states[1]!.media!, ref: '-1_1' } } as never);
    await Deno.writeTextFile(
      `${dir}/${SNAPSHOT_FILE}`,
      JSON.stringify({ version: 1, savedAt: 0, rooms: states }),
    );

    const restored = new RoomRegistry(LIMITS, clock);
    assert.equal(restored.restore(await readSnapshot(dir, memoryLogger())), media.length);
    media.forEach(({ kind, ref }, i) => {
      const back = restored.get(ids[i]!);
      assert.equal(back?.media?.kind, kind);
      assert.equal(back?.media?.ref, ref);
    });
  }));
