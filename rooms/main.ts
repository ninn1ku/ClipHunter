/**
 * ClipHunter rooms service: watch-room state and WebSocket fan-out (docs/WATCH_PARTY_PLAN.md §7).
 *
 * config → logger → gateway → restore snapshot → Deno.serve → signals.
 * `--self-check` only validates the configuration and the snapshot directory, then exits 0.
 */

import { systemClock } from './src/clock.ts';
import { ConfigError, denoEnv, loadConfig, projectRootFromModule } from './src/config.ts';
import { createLogger } from './src/log.ts';
import { Gateway } from './src/server.ts';

async function main(): Promise<number> {
  let config;
  try {
    config = loadConfig(denoEnv, projectRootFromModule());
  } catch (e) {
    const message = e instanceof ConfigError
      ? e.message
      : `${e instanceof Error ? e.name : 'Error'}: configuration failed`;
    createLogger('error').error('config.invalid', { message });

    return 1;
  }
  const log = createLogger(config.logLevel);

  try {
    const stat = await Deno.stat(config.snapshotDir);
    if (!stat.isDirectory) {
      throw new Error('not a directory');
    }
  } catch {
    log.error('config.invalid', {
      message: 'The snapshot directory (STORAGE_PATH/rooms) is missing or unreadable.',
    });

    return 1;
  }

  if (Deno.args.includes('--self-check')) {
    log.info('self_check.ok', { env: config.env, port: config.port });

    return 0;
  }

  const gateway = new Gateway(config, log, systemClock);
  await gateway.restore();

  const server = Deno.serve({
    hostname: config.hostname,
    port: config.port,
    onListen: ({ hostname, port }) =>
      log.info('server.start', { hostname, port, env: config.env, pid: Deno.pid }),
  }, gateway.handler);
  gateway.start();

  const { promise: stopped, resolve } = Promise.withResolvers<string>();
  const signals: Deno.Signal[] = Deno.build.os === 'windows' ? ['SIGINT'] : ['SIGINT', 'SIGTERM'];
  for (const signal of signals) {
    Deno.addSignalListener(signal, () => resolve(signal));
  }

  const signal = await stopped;
  await gateway.stop();
  await server.shutdown();
  log.info('server.stop', { signal });

  return 0;
}

Deno.exit(await main());
