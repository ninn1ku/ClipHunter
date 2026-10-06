// The participant's session: joining, resuming after reloads and reconnects, leaving.
//
// Identity (participantId + token) lives in sessionStorage under ch:room:<roomId>: a reload keeps
// it, a new tab starts a new participant. The last name typed lives in localStorage (ch:name).

import { CLOSE_CODES, RoomClient, RoomError, roomsUrl } from './room-client.js';

const NAME_KEY = 'ch:name';

/** Storage access that never throws (private mode, blocked storage). */
export const identityStore = {
  load(roomId) {
    try {
      const data = JSON.parse(sessionStorage.getItem(`ch:room:${roomId}`) ?? 'null');
      return data && typeof data.participantId === 'string' && typeof data.token === 'string' ? data : null;
    } catch {
      return null;
    }
  },
  save(roomId, identity) {
    try {
      sessionStorage.setItem(`ch:room:${roomId}`, JSON.stringify(identity));
    } catch {
      // Without storage a reload simply asks for the name again.
    }
  },
  clear(roomId) {
    try {
      sessionStorage.removeItem(`ch:room:${roomId}`);
    } catch {
      // Ignore.
    }
  },
  lastName() {
    try {
      return localStorage.getItem(NAME_KEY) ?? '';
    } catch {
      return '';
    }
  },
  saveName(name) {
    try {
      localStorage.setItem(NAME_KEY, name);
    } catch {
      // Ignore.
    }
  },
};

const TERMINATION = {
  [CLOSE_CODES.kicked]: 'kicked',
  [CLOSE_CODES.replaced]: 'replaced',
  [CLOSE_CODES.roomClosed]: 'closed',
};

export class RoomSession extends EventTarget {
  /**
   * @param {import('./store.js').Store} store
   * @param {{client?: RoomClient, storage?: typeof identityStore}} [options]
   */
  constructor(store, { client = new RoomClient(roomsUrl()), storage = identityStore } = {}) {
    super();
    this.store = store;
    this.client = client;
    this.storage = storage;
    /** @type {'idle'|'lobby'|'in-room'|'terminated'} */
    this.phase = 'idle';
    this.roomId = null;
    this.name = storage.lastName();
    this.opened = null;

    client.addEventListener('message', (event) => this.store.dispatch(event.detail));
    client.addEventListener('reconnecting', () => {
      if (this.phase === 'in-room') {
        this.store.dispatch({ type: 'connection', state: 'reconnecting' });
      }
    });
    client.addEventListener('open', (event) => {
      if (event.detail.reconnect && this.phase === 'in-room') {
        void this.rejoin();
      }
    });
    client.addEventListener('terminated', (event) => {
      this.terminate(TERMINATION[event.detail.code] ?? 'error');
    });
  }

  /** Connects once; resolves when the socket is open. */
  open() {
    if (this.opened === null) {
      this.opened = new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new RoomError('TIMEOUT')), 15000);
        this.client.addEventListener('open', () => {
          clearTimeout(timer);
          resolve();
        }, { once: true });
        this.client.connect();
      });
    }

    return this.opened;
  }

  /**
   * Decides how to enter an existing room: resume a stored identity, or ask for a name.
   * @returns {Promise<'resumed'|'needs_name'|'not_found'|'full'>}
   */
  async enter(roomId) {
    this.roomId = roomId;
    await this.open();

    const identity = this.storage.load(roomId);
    if (identity !== null) {
      try {
        this.welcomed(await this.client.request({ type: 'room.resume', roomId, ...identity }));
        return 'resumed';
      } catch (e) {
        if (!(e instanceof RoomError) || (e.code !== 'RESUME_FAILED' && e.code !== 'ROOM_NOT_FOUND')) {
          throw e;
        }
        this.storage.clear(roomId);
        if (e.code === 'ROOM_NOT_FOUND') {
          return 'not_found';
        }
      }
    }

    try {
      const info = await this.client.request({ type: 'room.peek', roomId });
      this.phase = 'lobby';
      return info.participants >= info.capacity ? 'full' : 'needs_name';
    } catch (e) {
      if (e instanceof RoomError && e.code === 'ROOM_NOT_FOUND') {
        return 'not_found';
      }
      throw e;
    }
  }

  /** @throws {RoomError} NAME_TAKEN, NAME_INVALID, ROOM_FULL, KICKED, ROOM_NOT_FOUND, … */
  async join(name) {
    const welcome = await this.client.request({ type: 'room.join', roomId: this.roomId, name });
    this.remember(welcome, name);
  }

  /**
   * Creates a room (optionally with media from a ticket) and enters it.
   * @returns {Promise<string>} room id
   */
  async create(name, ticket = null) {
    await this.open();
    const welcome = await this.client.request({ type: 'room.create', name, ticket });
    this.roomId = welcome.room.roomId;
    this.remember(welcome, name);

    return this.roomId;
  }

  async leave() {
    if (this.phase === 'in-room') {
      try {
        await this.client.request({ type: 'room.leave' }, { timeoutMs: 3000 });
      } catch {
        // Leaving anyway: the grace period removes us if the server did not hear it.
      }
    }
    this.close();
  }

  /** Stops the session without leaving the room (navigation away, terminal screens). */
  close() {
    if (this.roomId !== null) {
      this.storage.clear(this.roomId);
    }
    this.phase = 'idle';
    this.client.close();
    this.store.dispatch({ type: 'reset' });
  }

  send(message) {
    return this.client.send(message);
  }

  request(message, options) {
    return this.client.request(message, options);
  }

  remember(welcome, name) {
    if (typeof welcome.you.token === 'string') {
      this.storage.save(welcome.room.roomId, {
        participantId: welcome.you.participantId,
        token: welcome.you.token,
      });
    }
    this.name = name;
    this.storage.saveName(name);
    this.welcomed(welcome);
  }

  welcomed(welcome) {
    this.phase = 'in-room';
    this.store.dispatch(welcome);
    this.dispatchEvent(new CustomEvent('joined', { detail: welcome }));
  }

  /** After a reconnect: resume; if our seat expired meanwhile, join again under the same name. */
  async rejoin() {
    const identity = this.storage.load(this.roomId);
    try {
      if (identity !== null) {
        this.welcomed(await this.client.request({ type: 'room.resume', roomId: this.roomId, ...identity }));
        return;
      }
    } catch (e) {
      if (!(e instanceof RoomError)) {
        return;
      }
      if (e.code === 'ROOM_NOT_FOUND') {
        this.terminate('not_found');
        return;
      }
      if (e.code !== 'RESUME_FAILED') {
        return; // Transient (TIMEOUT, DISCONNECTED): the next reconnect retries.
      }
      this.storage.clear(this.roomId);
    }

    try {
      await this.join(this.name);
    } catch (e) {
      const code = e instanceof RoomError ? e.code : '';
      this.terminate(
        code === 'ROOM_FULL'
          ? 'full'
          : code === 'KICKED'
          ? 'kicked'
          : code === 'ROOM_NOT_FOUND'
          ? 'not_found'
          : 'error',
      );
    }
  }

  terminate(screen) {
    if (this.phase === 'terminated') {
      return;
    }
    if (this.roomId !== null && screen !== 'replaced') {
      this.storage.clear(this.roomId);
    }
    this.phase = 'terminated';
    this.client.close();
    this.store.dispatch({ type: 'connection', state: 'closed' });
    this.dispatchEvent(new CustomEvent('terminated', { detail: { screen } }));
  }
}
