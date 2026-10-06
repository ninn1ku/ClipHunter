// WebSocket client for the rooms service: request/response by reqId, events, automatic reconnect.
//
// Reconnect: 500 ms × 2^n up to 8 s plus 0–30 % jitter; right away (with a little jitter) after
// 1012 "service restarting". Close codes that end the session (kicked, replaced, room closed,
// protocol violation) are reported as "terminated" and never retried.

export class RoomError extends Error {
  /** @param {string} code server error code, or TIMEOUT / DISCONNECTED */
  constructor(code) {
    super(code);
    this.code = code;
  }
}

export const CLOSE_CODES = {
  normal: 1000,
  policy: 1008,
  tooBig: 1009,
  restart: 1012,
  kicked: 4001,
  replaced: 4002,
  roomClosed: 4004,
};

const TERMINAL = new Set([
  CLOSE_CODES.policy,
  CLOSE_CODES.tooBig,
  CLOSE_CODES.kicked,
  CLOSE_CODES.replaced,
  CLOSE_CODES.roomClosed,
]);
const BASE_DELAY_MS = 500;
const MAX_DELAY_MS = 8000;

/** The rooms endpoint on the current origin (ws: or wss:). */
export function roomsUrl(location = window.location) {
  const url = new URL('/ws/rooms', location.href);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';

  return url.toString();
}

export class RoomClient extends EventTarget {
  /**
   * @param {string} url
   * @param {{WebSocketImpl?: typeof WebSocket, random?: () => number}} [options]
   */
  constructor(url, { WebSocketImpl = WebSocket, random = Math.random } = {}) {
    super();
    this.url = url;
    this.WebSocketImpl = WebSocketImpl;
    this.random = random;
    /** @type {WebSocket|null} */
    this.socket = null;
    this.attempt = 0;
    this.everOpened = false;
    this.stopped = false;
    this.timer = null;
    this.seq = 0;
    /** @type {Map<string, {resolve: Function, reject: Function, timer: any}>} */
    this.pending = new Map();
  }

  get isOpen() {
    return this.socket !== null && this.socket.readyState === this.WebSocketImpl.OPEN;
  }

  connect() {
    this.stopped = false;
    clearTimeout(this.timer);
    const socket = new this.WebSocketImpl(this.url);
    this.socket = socket;

    socket.addEventListener('open', () => {
      if (this.socket !== socket) {
        return;
      }
      const reconnect = this.everOpened;
      this.everOpened = true;
      this.attempt = 0;
      this.emit('open', { reconnect });
    });
    socket.addEventListener('message', (event) => {
      if (this.socket !== socket) {
        return;
      }
      let message;
      try {
        message = JSON.parse(String(event.data));
      } catch {
        return;
      }
      const waiter = typeof message.reqId === 'string' ? this.pending.get(message.reqId) : undefined;
      if (waiter !== undefined) {
        this.pending.delete(message.reqId);
        clearTimeout(waiter.timer);
        if (message.type === 'error') {
          waiter.reject(new RoomError(message.code));
        } else {
          waiter.resolve(message);
        }
        return;
      }
      this.emit('message', message);
    });
    socket.addEventListener('close', (event) => {
      if (this.socket !== socket) {
        return;
      }
      this.socket = null;
      this.failPending('DISCONNECTED');
      if (this.stopped) {
        return;
      }
      if (TERMINAL.has(event.code)) {
        this.stopped = true;
        this.emit('terminated', { code: event.code });
        return;
      }
      this.scheduleReconnect(event.code === CLOSE_CODES.restart);
    });
  }

  /**
   * Sends a message with a fresh reqId and resolves with the reply (rejects with RoomError).
   * @param {Record<string, unknown>} message
   * @param {{timeoutMs?: number}} [options]
   * @returns {Promise<any>}
   */
  request(message, { timeoutMs = 8000 } = {}) {
    if (!this.isOpen) {
      return Promise.reject(new RoomError('DISCONNECTED'));
    }
    const reqId = `q${(++this.seq).toString(36)}`;

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.pending.delete(reqId);
        reject(new RoomError('TIMEOUT'));
      }, timeoutMs);
      this.pending.set(reqId, { resolve, reject, timer });
      this.socket?.send(JSON.stringify({ ...message, reqId }));
    });
  }

  /** Fire-and-forget. Returns false when not connected. */
  send(message) {
    if (!this.isOpen) {
      return false;
    }
    this.socket?.send(JSON.stringify(message));

    return true;
  }

  /** Closes for good (leaving, navigating away). */
  close() {
    this.stopped = true;
    clearTimeout(this.timer);
    this.failPending('DISCONNECTED');
    const socket = this.socket;
    this.socket = null;
    if (socket !== null && socket.readyState <= this.WebSocketImpl.OPEN) {
      socket.close(CLOSE_CODES.normal);
    }
  }

  /** Delay before reconnect attempt n (exported for tests through the instance). */
  delayFor(attempt, immediate) {
    const base = immediate ? 100 : Math.min(MAX_DELAY_MS, BASE_DELAY_MS * 2 ** attempt);

    return Math.round(base * (1 + this.random() * 0.3));
  }

  scheduleReconnect(immediate) {
    const delayMs = this.delayFor(this.attempt, immediate);
    this.attempt++;
    this.emit('reconnecting', { attempt: this.attempt, delayMs });
    this.timer = setTimeout(() => {
      if (!this.stopped) {
        this.connect();
      }
    }, delayMs);
  }

  failPending(code) {
    for (const [reqId, waiter] of this.pending) {
      clearTimeout(waiter.timer);
      waiter.reject(new RoomError(code));
      this.pending.delete(reqId);
    }
  }

  emit(type, detail) {
    this.dispatchEvent(new CustomEvent(type, { detail }));
  }
}
