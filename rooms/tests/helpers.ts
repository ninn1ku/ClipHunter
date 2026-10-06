/** Shared helpers for the gateway tests and the smoke/load tools. */

import { base64url, hexToBytes } from '../src/security/ids.ts';

export type Message = Record<string, unknown> & { type: string };

/** Signs a ticket exactly like src/Watch/MediaTicket.php does. */
export async function signTicket(payload: Record<string, unknown>, secretHex: string): Promise<string> {
  const body = base64url(new TextEncoder().encode(JSON.stringify(payload)));
  const key = await crypto.subtle.importKey(
    'raw',
    hexToBytes(secretHex),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    [
      'sign',
    ],
  );
  const signature = new Uint8Array(await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(body)));

  return `${body}.${base64url(signature)}`;
}

export function youtubeTicketPayload(
  nowSec: number,
  overrides: Record<string, unknown> = {},
): Record<string, unknown> {
  return {
    v: 1,
    kind: 'youtube',
    ref: 'dQw4w9WgXcQ',
    platform: 'YouTube',
    title: null,
    durationSec: 212,
    thumbnailUrl: 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
    startSec: 0,
    exp: nowSec + 600,
    ...overrides,
  };
}

/** A WebSocket client that buffers messages and lets a test await the next matching one. */
export class TestClient {
  readonly received: Message[] = [];
  readonly closed: Promise<CloseEvent>;
  private readonly waiters: Array<{ match: (m: Message) => boolean; resolve: (m: Message) => void }> = [];
  private readonly consumed = new Set<number>();

  private constructor(readonly ws: WebSocket) {
    this.closed = new Promise((resolve) => ws.addEventListener('close', resolve));
    ws.addEventListener('message', (event) => {
      const message = JSON.parse(String(event.data)) as Message;
      this.received.push(message);
      const index = this.waiters.findIndex((w) => w.match(message));
      if (index >= 0) {
        const [waiter] = this.waiters.splice(index, 1);
        this.consumed.add(this.received.length - 1);
        waiter?.resolve(message);
      }
    });
  }

  static open(url: string, headers: Record<string, string> = {}): Promise<TestClient> {
    // Deno's WebSocket accepts request headers (non-standard), which lets tests set Origin/X-Real-IP.
    const ws = new (WebSocket as unknown as new (
      url: string,
      options: { headers: Record<string, string> },
    ) => WebSocket)(
      url,
      { headers },
    );
    const client = new TestClient(ws);

    return new Promise((resolve, reject) => {
      ws.addEventListener('open', () => resolve(client), { once: true });
      ws.addEventListener('error', () => reject(new Error('WebSocket failed to open')), { once: true });
    });
  }

  send(message: Record<string, unknown>): void {
    this.ws.send(JSON.stringify(message));
  }

  sendRaw(data: string): void {
    this.ws.send(data);
  }

  /** Resolves with the first not-yet-consumed message matching `match` (type name or predicate). */
  next(match: string | ((m: Message) => boolean), timeoutMs = 3000): Promise<Message> {
    const predicate = typeof match === 'string' ? (m: Message) => m.type === match : match;
    for (let i = 0; i < this.received.length; i++) {
      const message = this.received[i] as Message;
      if (!this.consumed.has(i) && predicate(message)) {
        this.consumed.add(i);
        return Promise.resolve(message);
      }
    }

    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        const index = this.waiters.indexOf(waiter);
        if (index >= 0) {
          this.waiters.splice(index, 1);
        }
        reject(new Error(`Timed out waiting for ${typeof match === 'string' ? match : 'a message'}`));
      }, timeoutMs);
      const waiter = {
        match: predicate,
        resolve: (m: Message) => {
          clearTimeout(timer);
          resolve(m);
        },
      };
      this.waiters.push(waiter);
    });
  }

  /** Sends and awaits the reply carrying the same reqId. */
  async request(message: Record<string, unknown>): Promise<Message> {
    const reqId = `r${Math.random().toString(36).slice(2, 10)}`;
    this.send({ ...message, reqId });

    return await this.next((m) => m.reqId === reqId);
  }

  async close(): Promise<void> {
    if (this.ws.readyState === WebSocket.OPEN || this.ws.readyState === WebSocket.CONNECTING) {
      this.ws.close(1000);
    }
    await this.closed;
  }
}
