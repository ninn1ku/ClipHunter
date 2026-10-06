/**
 * Structured logging: one JSON object per line on stdout (collected by journald).
 *
 * Never log chat text, names, tokens, tickets, full room ids or raw IPs: callers pass
 * {@link roomTag} and an IP hash instead.
 */

export type LogLevel = 'debug' | 'info' | 'warning' | 'error';
export type LogFields = Record<string, string | number | boolean | null | undefined>;

const ORDER: Record<LogLevel, number> = { debug: 0, info: 1, warning: 2, error: 3 };

export interface Logger {
  debug(event: string, fields?: LogFields): void;
  info(event: string, fields?: LogFields): void;
  warning(event: string, fields?: LogFields): void;
  error(event: string, fields?: LogFields): void;
}

export function createLogger(
  minLevel: LogLevel,
  write: (line: string) => void = (line) => {
    // deno-lint-ignore no-console
    console.log(line);
  },
): Logger {
  const emit = (level: LogLevel, event: string, fields: LogFields = {}): void => {
    if (ORDER[level] < ORDER[minLevel]) {
      return;
    }
    write(JSON.stringify({ ts: new Date().toISOString(), level, event, ...fields }));
  };

  return {
    debug: (event, fields) => emit('debug', event, fields),
    info: (event, fields) => emit('info', event, fields),
    warning: (event, fields) => emit('warning', event, fields),
    error: (event, fields) => emit('error', event, fields),
  };
}

/** A logger that keeps parsed entries in memory, for tests. */
export function memoryLogger(): Logger & { entries: Array<Record<string, unknown>> } {
  const entries: Array<Record<string, unknown>> = [];
  const logger = createLogger('debug', (line) => entries.push(JSON.parse(line)));

  return Object.assign(logger, { entries });
}

/** The only form in which a room id may appear in logs: its first 4 characters. */
export function roomTag(roomId: string): string {
  return roomId.slice(0, 4);
}
