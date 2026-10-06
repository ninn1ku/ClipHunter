import assert from 'node:assert/strict';
import { ConfigError, INSECURE_DEV_SECRET, loadConfig } from '../src/config.ts';

const SECRET = 'ab'.repeat(32);

function env(vars: Record<string, string>): (name: string) => string | undefined {
  return (name) => vars[name];
}

Deno.test('production defaults match the plan', () => {
  const config = loadConfig(
    env({ ROOMS_SECRET: SECRET, APP_URL: 'https://cliphunterapp.duckdns.org/' }),
    '/srv/app',
  );

  assert.equal(config.env, 'production');
  assert.equal(config.appOrigin, 'https://cliphunterapp.duckdns.org');
  assert.equal(config.hostname, '127.0.0.1');
  assert.equal(config.port, 8790);
  assert.equal(config.maxParticipants, 5);
  assert.equal(config.maxRooms, 50);
  assert.equal(config.maxConnections, 250);
  assert.equal(config.maxConnectionsPerIp, 6);
  assert.deepEqual(config.rateCreate, { limit: 10, windowMs: 3_600_000 });
  assert.deepEqual(config.rateJoin, { limit: 60, windowMs: 600_000 });
  assert.equal(config.emptyTtlMs, 30 * 60_000);
  assert.equal(config.maxAgeMs, 12 * 3_600_000);
  assert.equal(config.reconnectGraceMs, 45_000);
  assert.equal(config.snapshotDir, '/srv/app/storage/rooms');
  assert.equal(config.devUpstream, null);
});

Deno.test('an absolute STORAGE_PATH is used as is', () => {
  const config = loadConfig(
    env({ ROOMS_SECRET: SECRET, STORAGE_PATH: '/var/www/cliphunter/shared/storage/' }),
    '/srv/app',
  );

  assert.equal(config.snapshotDir, '/var/www/cliphunter/shared/storage/rooms');
});

Deno.test('development falls back to the shared insecure secret and may proxy to PHP', () => {
  const config = loadConfig(
    env({ APP_ENV: 'development', ROOMS_DEV_UPSTREAM: 'http://127.0.0.1:8080' }),
    'C:/dev/app',
  );

  assert.equal(config.secretHex, INSECURE_DEV_SECRET);
  assert.equal(config.devUpstream, 'http://127.0.0.1:8080');
  assert.equal(config.snapshotDir, 'C:/dev/app/storage/rooms');
});

Deno.test('the dev proxy is never enabled in production', () => {
  const config = loadConfig(
    env({ ROOMS_SECRET: SECRET, ROOMS_DEV_UPSTREAM: 'http://127.0.0.1:8080' }),
    '/srv/app',
  );

  assert.equal(config.devUpstream, null);
});

Deno.test('invalid configuration fails fast with the variable name', () => {
  const cases: Array<[Record<string, string>, string]> = [
    [{}, 'ROOMS_SECRET'],
    [{ ROOMS_SECRET: 'abc' }, 'ROOMS_SECRET'],
    [{ ROOMS_SECRET: 'AB'.repeat(32) }, 'ROOMS_SECRET'],
    [{ ROOMS_SECRET: SECRET, APP_ENV: 'staging' }, 'APP_ENV'],
    [{ ROOMS_SECRET: SECRET, ROOMS_MAX_PARTICIPANTS: '6' }, 'ROOMS_MAX_PARTICIPANTS'],
    [{ ROOMS_SECRET: SECRET, ROOMS_MAX_PARTICIPANTS: '1' }, 'ROOMS_MAX_PARTICIPANTS'],
    [{ ROOMS_SECRET: SECRET, ROOMS_MAX_ROOMS: 'many' }, 'ROOMS_MAX_ROOMS'],
    [{ ROOMS_SECRET: SECRET, ROOMS_RATE_JOIN: '60 per 600' }, 'ROOMS_RATE_JOIN'],
    [{ ROOMS_SECRET: SECRET, ROOMS_LISTEN: '0.0.0.0:8790' }, 'ROOMS_LISTEN'],
    [{ ROOMS_SECRET: SECRET, ROOMS_LISTEN: '10.0.0.5:8790' }, 'ROOMS_LISTEN'],
    [{ ROOMS_SECRET: SECRET, ROOMS_LISTEN: '127.0.0.1:99999' }, 'ROOMS_LISTEN'],
    [{ ROOMS_SECRET: SECRET, LOG_LEVEL: 'verbose' }, 'LOG_LEVEL'],
    [{ ROOMS_SECRET: SECRET, APP_URL: 'not a url' }, 'APP_URL'],
    [{ APP_ENV: 'development', ROOMS_DEV_UPSTREAM: 'http://evil.example' }, 'ROOMS_DEV_UPSTREAM'],
  ];
  for (const [vars, name] of cases) {
    assert.throws(
      () => loadConfig(env(vars), '/srv/app'),
      (e: unknown) => e instanceof ConfigError && e.message.includes(name),
      name,
    );
  }
});
