/**
 * Typed, validated configuration from environment variables. Fails fast on startup.
 *
 * Shares APP_ENV, APP_URL, LOG_LEVEL, STORAGE_PATH and ROOMS_SECRET with the PHP app
 * (src/Config/AppConfig.php); everything else is ROOMS_*.
 */

import type { LogLevel } from './log.ts';

export type AppEnv = 'production' | 'development' | 'testing';

export interface RateRule {
  limit: number;
  windowMs: number;
}

export interface Config {
  env: AppEnv;
  /** Origin of APP_URL, e.g. "https://cliphunterapp.duckdns.org". Browsers must connect from it. */
  appOrigin: string;
  hostname: string;
  port: number;
  /** Lowercase hex, at least 64 characters. */
  secretHex: string;
  logLevel: LogLevel;
  /** Directory for state snapshots: ${STORAGE_PATH}/rooms. */
  snapshotDir: string;
  maxParticipants: number;
  maxRooms: number;
  maxConnections: number;
  maxConnectionsPerIp: number;
  rateCreate: RateRule;
  rateJoin: RateRule;
  emptyTtlMs: number;
  maxAgeMs: number;
  reconnectGraceMs: number;
  /** Development only: where non-WebSocket requests are proxied (the PHP dev server). */
  devUpstream: string | null;
}

export class ConfigError extends Error {}

/**
 * Must equal AppConfig::INSECURE_DEV_ROOMS_SECRET, so PHP and the rooms service agree on tickets
 * in local development without any configuration. Refused in production.
 */
export const INSECURE_DEV_SECRET = 'adbe838fa134f78ad76a2cc074629ecf810aaf14f68a716aee0a8bdc77aa9c27';

type Env = (name: string) => string | undefined;

/** Reads the variables the service needs, one by one (works with a narrow --allow-env). */
export const denoEnv: Env = (name) => Deno.env.get(name);

export function loadConfig(env: Env, projectRoot: string): Config {
  const str = (name: string, fallback: string): string => {
    const value = env(name)?.trim();

    return value === undefined || value === '' ? fallback : value;
  };
  const int = (name: string, fallback: number, min: number, max: number): number => {
    const raw = str(name, String(fallback));
    if (!/^-?\d{1,15}$/.test(raw)) {
      throw new ConfigError(`${name} must be an integer, got "${raw}".`);
    }
    const value = Number(raw);
    if (value < min || value > max) {
      throw new ConfigError(`${name} must be between ${min} and ${max}, got ${value}.`);
    }

    return value;
  };
  const rate = (name: string, fallback: string): RateRule => {
    const m = /^\s*(\d{1,6})\s*\/\s*(\d{1,7})\s*$/.exec(str(name, fallback));
    if (m === null || Number(m[1]) < 1 || Number(m[2]) < 1) {
      throw new ConfigError(`Invalid rate limit ${name}, expected "<limit>/<seconds>".`);
    }

    return { limit: Number(m[1]), windowMs: Number(m[2]) * 1000 };
  };

  const appEnv = str('APP_ENV', 'production');
  if (appEnv !== 'production' && appEnv !== 'development' && appEnv !== 'testing') {
    throw new ConfigError('APP_ENV must be one of: production, development, testing.');
  }
  const isProduction = appEnv === 'production';

  let secretHex = str('ROOMS_SECRET', '');
  if (secretHex === '') {
    if (isProduction) {
      throw new ConfigError('ROOMS_SECRET is required in production.');
    }
    secretHex = INSECURE_DEV_SECRET;
  } else if (!/^[a-f0-9]{64,}$/.test(secretHex) || secretHex.length % 2 !== 0) {
    throw new ConfigError('ROOMS_SECRET must be at least 64 lowercase hex characters.');
  }

  let appOrigin: string;
  try {
    appOrigin = new URL(str('APP_URL', 'http://localhost:8080')).origin;
  } catch {
    throw new ConfigError('APP_URL must be an absolute URL.');
  }
  if (appOrigin === 'null') {
    throw new ConfigError('APP_URL must be an http(s) URL.');
  }

  const listen = /^(127\.0\.0\.1|localhost|\[::1\]|0\.0\.0\.0):(\d{1,5})$/.exec(
    str('ROOMS_LISTEN', '127.0.0.1:8790'),
  );
  const port = listen === null ? NaN : Number(listen[2]);
  if (listen === null || !(port >= 1 && port <= 65535)) {
    throw new ConfigError('ROOMS_LISTEN must be <host>:<port> with a loopback host.');
  }
  if (isProduction && listen[1] !== '127.0.0.1') {
    throw new ConfigError(
      'ROOMS_LISTEN must be 127.0.0.1:<port> in production: Nginx is the only entry point.',
    );
  }

  const logLevel = str('LOG_LEVEL', 'info');
  const levels: Record<string, LogLevel> = {
    debug: 'debug',
    info: 'info',
    notice: 'info',
    warning: 'warning',
    error: 'error',
  };
  if (!(logLevel in levels)) {
    throw new ConfigError('LOG_LEVEL must be one of: debug, info, notice, warning, error.');
  }

  const devUpstreamRaw = str('ROOMS_DEV_UPSTREAM', '');
  let devUpstream: string | null = null;
  if (appEnv === 'development' && devUpstreamRaw !== '') {
    let upstream: URL;
    try {
      upstream = new URL(devUpstreamRaw);
    } catch {
      throw new ConfigError('ROOMS_DEV_UPSTREAM must be an absolute URL.');
    }
    if (upstream.protocol !== 'http:' || !['127.0.0.1', 'localhost'].includes(upstream.hostname)) {
      throw new ConfigError('ROOMS_DEV_UPSTREAM must be an http:// URL on 127.0.0.1.');
    }
    devUpstream = upstream.origin;
  }

  const storage = str('STORAGE_PATH', 'storage').replace(/\\/g, '/').replace(/\/+$/, '');
  const isAbsolute = storage.startsWith('/') || /^[A-Za-z]:\//.test(storage);

  return {
    env: appEnv,
    appOrigin,
    hostname: listen[1] === '[::1]' ? '::1' : listen[1] as string,
    port,
    secretHex,
    logLevel: levels[logLevel] as LogLevel,
    snapshotDir: `${isAbsolute ? storage : `${projectRoot.replace(/\/+$/, '')}/${storage}`}/rooms`,
    maxParticipants: int('ROOMS_MAX_PARTICIPANTS', 5, 2, 5),
    maxRooms: int('ROOMS_MAX_ROOMS', 50, 1, 10_000),
    maxConnections: int('ROOMS_MAX_CONNECTIONS', 250, 1, 100_000),
    maxConnectionsPerIp: int('ROOMS_MAX_CONNECTIONS_PER_IP', 6, 1, 1_000),
    rateCreate: rate('ROOMS_RATE_CREATE', '10/3600'),
    rateJoin: rate('ROOMS_RATE_JOIN', '60/600'),
    emptyTtlMs: int('ROOMS_EMPTY_TTL_MIN', 30, 1, 1_440) * 60_000,
    maxAgeMs: int('ROOMS_MAX_AGE_HOURS', 12, 1, 72) * 3_600_000,
    reconnectGraceMs: int('ROOMS_RECONNECT_GRACE_SEC', 45, 5, 600) * 1000,
    devUpstream,
  };
}

/** The repository root, derived from this module's location (rooms/src/config.ts). */
export function projectRootFromModule(): string {
  const path = decodeURIComponent(new URL('../../', import.meta.url).pathname);

  // file:///C:/x/ → /C:/x/ on Windows.
  return /^\/[A-Za-z]:\//.test(path) ? path.slice(1) : path;
}
