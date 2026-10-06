/**
 * The gateway: HTTP entry point of the rooms service.
 *
 * - GET /healthz (loopback only): liveness and counters.
 * - GET /ws/rooms: WebSocket upgrade after Origin, connection and per-IP checks.
 * - Development only: every other request is proxied to the PHP dev server, so the browser sees
 *   one origin for pages, API and WebSocket.
 *
 * It owns sockets and timers (lifecycle sweep, snapshots); rooms live in the registry.
 */

import type { Clock } from './clock.ts';
import type { Config } from './config.ts';
import { Connection, type Hub } from './connection.ts';
import { RoomRegistry } from './domain/registry.ts';
import type { Room, RoomEvent } from './domain/room.ts';
import { type Logger, roomTag } from './log.ts';
import { mediaInUse, writeMediaInUse } from './persistence/media-in-use.ts';
import { readSnapshot, writeSnapshot } from './persistence/snapshot.ts';
import { CLOSE } from './protocol.ts';
import { ipHasher } from './security/ids.ts';
import { KeyedWindowLimiter } from './security/rate-limit.ts';
import { TicketVerifier } from './security/ticket.ts';

export const WS_PATH = '/ws/rooms';
const SWEEP_INTERVAL_MS = 15_000;
const SNAPSHOT_INTERVAL_MS = 10_000;
const IDLE_TIMEOUT_SEC = 30;
const STOP_DRAIN_MS = 2000;
const IN_USE_INTERVAL_MS = 30_000;
/** The list is rewritten at least this often even unchanged, as a sign of life for the PHP cleaner. */
const IN_USE_HEARTBEAT_MS = 60_000;

export class Gateway implements Hub {
  readonly registry: RoomRegistry;
  readonly tickets: TicketVerifier;
  readonly createLimiter: KeyedWindowLimiter;
  readonly joinLimiter: KeyedWindowLimiter;
  private readonly hashIp: (ip: string) => string;
  private readonly connections = new Set<Connection>();
  private readonly perIp = new Map<string, number>();
  /** roomId → participantId → connection */
  private readonly members = new Map<string, Map<string, Connection>>();
  private readonly startedAt: number;
  private timers: ReturnType<typeof setInterval>[] = [];
  private stopping = false;
  private saving: Promise<void> = Promise.resolve();
  private inUseKey: string | null = null;
  private inUseWrittenAt = 0;

  constructor(
    readonly config: Config,
    readonly log: Logger,
    readonly clock: Clock,
  ) {
    this.registry = new RoomRegistry(
      {
        maxRooms: config.maxRooms,
        maxParticipants: config.maxParticipants,
        emptyTtlMs: config.emptyTtlMs,
        maxAgeMs: config.maxAgeMs,
        reconnectGraceMs: config.reconnectGraceMs,
      },
      clock,
    );
    this.tickets = new TicketVerifier(config.secretHex);
    this.createLimiter = new KeyedWindowLimiter(config.rateCreate.limit, config.rateCreate.windowMs, clock);
    this.joinLimiter = new KeyedWindowLimiter(config.rateJoin.limit, config.rateJoin.windowMs, clock);
    this.hashIp = ipHasher(config.secretHex);
    this.startedAt = clock.now();
  }

  get connectionCount(): number {
    return this.connections.size;
  }

  /** Loads the last snapshot. Call once before serving. */
  async restore(): Promise<number> {
    const restored = this.registry.restore(await readSnapshot(this.config.snapshotDir, this.log));
    this.registry.dirty = false;
    this.log.info('snapshot.restored', { rooms: restored });

    return restored;
  }

  /** Starts the lifecycle sweep and the snapshot writer. */
  start(): void {
    this.timers.push(
      setInterval(() => this.sweep(), SWEEP_INTERVAL_MS),
      setInterval(() => void this.saveSnapshot(), SNAPSHOT_INTERVAL_MS),
      setInterval(() => void this.publishMediaInUse(), IN_USE_INTERVAL_MS),
    );
    void this.publishMediaInUse(true);
  }

  /** Snapshot, then close every socket with 1012 so clients reconnect to the next process. */
  async stop(): Promise<void> {
    if (this.stopping) {
      return;
    }
    this.stopping = true;
    for (const timer of this.timers) {
      clearInterval(timer);
    }
    this.timers = [];
    await this.saving;
    this.registry.dirty = true;
    await this.saveSnapshot(true);
    // Rooms survive the restart, and so must their files: refresh the list one last time.
    await this.publishMediaInUse(true);
    for (const connection of this.connections) {
      connection.close(CLOSE.restart, 'service restarting');
    }
    // Let the close handshakes finish: shutting the server down at once would cut the sockets,
    // and clients would see 1001 instead of 1012 (and back off instead of reconnecting at once).
    const deadline = Date.now() + STOP_DRAIN_MS;
    while (this.connections.size > 0 && Date.now() < deadline) {
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
  }

  handler = (req: Request, info: Deno.ServeHandlerInfo<Deno.NetAddr>): Response | Promise<Response> => {
    const url = new URL(req.url);
    const remote = info.remoteAddr.hostname;

    if (url.pathname === '/healthz') {
      return isLoopback(remote) && !req.headers.has('x-real-ip')
        ? this.health()
        : new Response(null, { status: 404 });
    }
    if (url.pathname === WS_PATH) {
      return this.upgrade(req, remote);
    }
    if (this.config.devUpstream !== null) {
      return this.proxy(req, url);
    }

    return new Response(null, { status: 404 });
  };

  attach(connection: Connection, room: Room, participantId: string): Connection | undefined {
    let members = this.members.get(room.id);
    if (members === undefined) {
      members = new Map();
      this.members.set(room.id, members);
    }
    const previous = members.get(participantId);
    members.set(participantId, connection);

    return previous;
  }

  detach(connection: Connection): void {
    const { room, participantId } = connection;
    if (room !== null && participantId !== null) {
      const members = this.members.get(room.id);
      if (members?.get(participantId) === connection) {
        members.delete(participantId);
        if (members.size === 0) {
          this.members.delete(room.id);
        }
      }
    }
    connection.room = null;
    connection.participantId = null;
  }

  connectionOf(roomId: string, participantId: string): Connection | undefined {
    return this.members.get(roomId)?.get(participantId);
  }

  broadcast(room: Room, events: RoomEvent[], except?: Connection): void {
    if (events.length === 0) {
      return;
    }
    this.registry.dirty = true;
    const members = this.members.get(room.id);
    if (members === undefined) {
      return;
    }
    for (const event of events) {
      const json = JSON.stringify(event);
      for (const connection of members.values()) {
        if (connection !== except) {
          connection.sendRaw(json);
        }
      }
    }
  }

  closed(connection: Connection, code: number): void {
    if (!this.connections.delete(connection)) {
      return;
    }
    const count = (this.perIp.get(connection.ipHash) ?? 1) - 1;
    if (count <= 0) {
      this.perIp.delete(connection.ipHash);
    } else {
      this.perIp.set(connection.ipHash, count);
    }

    const { room, participantId } = connection;
    if (room !== null && participantId !== null && this.connectionOf(room.id, participantId) === connection) {
      this.detach(connection);
      if (!this.stopping) {
        this.broadcast(room, room.disconnect(participantId, this.clock.now()));
      }
    }
    this.log.info('ws.close', {
      code,
      duration_ms: this.clock.now() - connection.openedAt,
      room: connection.lastRoomId === null ? null : roomTag(connection.lastRoomId),
    });
  }

  /** Expires grace periods and rooms. Runs every 15 s. */
  sweep(): void {
    const { updates, closed } = this.registry.sweep();
    for (const { room, events } of updates) {
      this.broadcast(room, events);
      this.log.info('room.left', { room: roomTag(room.id), reason: 'timeout' });
    }
    for (const { room, reason } of closed) {
      const members = this.members.get(room.id);
      for (const connection of members === undefined ? [] : [...members.values()]) {
        this.detach(connection);
        connection.send({ type: 'room.closed', reason });
        connection.close(CLOSE.roomClosed, 'room closed');
      }
      this.members.delete(room.id);
      this.log.info('room.expired', { room: roomTag(room.id), reason });
    }
    this.createLimiter.sweep();
    this.joinLimiter.sweep();
  }

  /**
   * Tells the PHP cleaner which prepared files are in use (see persistence/media-in-use.ts).
   * Written when the set changes, and at least once a minute.
   */
  async publishMediaInUse(force = false): Promise<void> {
    const refs = mediaInUse(this.registry.all());
    const key = refs.join(',');
    const now = this.clock.now();
    if (!force && key === this.inUseKey && now - this.inUseWrittenAt < IN_USE_HEARTBEAT_MS) {
      return;
    }
    try {
      await writeMediaInUse(this.config.snapshotDir, refs, now);
      this.inUseKey = key;
      this.inUseWrittenAt = now;
    } catch (e) {
      this.log.error('media_in_use.failed', { error: e instanceof Error ? e.name : 'unknown' });
    }
  }

  async saveSnapshot(force = false): Promise<void> {
    if (!this.registry.dirty || (this.stopping && !force)) {
      return;
    }
    this.registry.dirty = false;
    const started = performance.now();
    const rooms = this.registry.toStates();
    this.saving = writeSnapshot(this.config.snapshotDir, rooms, this.clock.now())
      .then(() => {
        this.log.debug('snapshot.saved', {
          rooms: rooms.length,
          duration_ms: Math.round(performance.now() - started),
        });
      })
      .catch((e: unknown) => {
        this.registry.dirty = true;
        this.log.error('snapshot.failed', { error: e instanceof Error ? e.name : 'unknown' });
      });
    await this.saving;
  }

  private health(): Response {
    return Response.json({
      status: 'ok',
      rooms: this.registry.size,
      connections: this.connections.size,
      uptimeSec: Math.floor((this.clock.now() - this.startedAt) / 1000),
    }, { headers: { 'cache-control': 'no-store' } });
  }

  private upgrade(req: Request, remote: string): Response {
    const ip = clientIp(req, remote);
    const ipHash = this.hashIp(ip);
    const reject = (status: number, reason: string): Response => {
      this.log.info('ws.rejected', { reason, ip_hash: ipHash });

      return new Response(null, { status });
    };

    if (this.stopping) {
      return reject(503, 'stopping');
    }
    if (req.method !== 'GET' || req.headers.get('upgrade')?.toLowerCase() !== 'websocket') {
      return reject(426, 'not_websocket');
    }
    // Like the PHP OriginGuard: browsers always send Origin, so a foreign one is cross-site
    // WebSocket hijacking. Non-browser clients (no Origin) are bounded by the limits below.
    const origin = req.headers.get('origin');
    if (origin !== null && origin !== this.config.appOrigin) {
      return reject(403, 'origin');
    }
    if (this.connections.size >= this.config.maxConnections) {
      this.log.warning('limit.hit', { limit: 'max_connections', ip_hash: ipHash });
      return reject(503, 'max_connections');
    }
    const fromIp = this.perIp.get(ipHash) ?? 0;
    if (fromIp >= this.config.maxConnectionsPerIp) {
      this.log.info('limit.hit', { limit: 'connections_per_ip', ip_hash: ipHash });
      return reject(429, 'connections_per_ip');
    }

    const { socket, response } = Deno.upgradeWebSocket(req, { idleTimeout: IDLE_TIMEOUT_SEC });
    const connection = new Connection(socket, ipHash, this);
    this.connections.add(connection);
    this.perIp.set(ipHash, fromIp + 1);
    socket.addEventListener('open', () => this.log.debug('ws.open', { ip_hash: ipHash }));

    return response;
  }

  /** Development only (config refuses it in production; the production unit has no outbound net). */
  private async proxy(req: Request, url: URL): Promise<Response> {
    const headers = new Headers(req.headers);
    headers.delete('host');
    try {
      const upstream = await fetch(`${this.config.devUpstream}${url.pathname}${url.search}`, {
        method: req.method,
        headers,
        body: req.body,
        redirect: 'manual',
      });

      return new Response(upstream.body, { status: upstream.status, headers: upstream.headers });
    } catch {
      return new Response('PHP dev server is not reachable at ROOMS_DEV_UPSTREAM.', { status: 502 });
    }
  }
}

function isLoopback(ip: string): boolean {
  return ip === '127.0.0.1' || ip === '::1' || ip.startsWith('127.') || ip === '::ffff:127.0.0.1';
}

/** X-Real-IP is trusted only from Nginx on the same host; otherwise anyone could pick their limits bucket. */
function clientIp(req: Request, remote: string): string {
  const real = req.headers.get('x-real-ip')?.trim();
  if (isLoopback(remote) && real !== undefined && /^[0-9a-fA-F:.]{2,45}$/.test(real)) {
    return real;
  }

  return remote;
}
