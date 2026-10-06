// Room state and the reducer of server events. Pure: no DOM, no network.

export const CHAT_LIMIT = 100;

/**
 * @typedef {{id: string, name: string, color: number, isHost: boolean, connected: boolean,
 *   status: 'watching'|'buffering'|'idle', joinedAt: number}} Participant
 * @typedef {{kind: 'youtube'|'file', ref: string, platform: string, title: string|null,
 *   durationSec: number|null, thumbnailUrl: string|null, startSec: number, setAt: number}} Media
 * @typedef {{status: 'playing'|'paused', positionSec: number, updatedAt: number, rate: number,
 *   seq: number, by: string|null}} Playback
 * @typedef {{id: string, kind: 'user'|'system', participantId: string, name: string, color: number,
 *   text?: string|null, event?: string, ts: number}} ChatMessage
 * @typedef {{roomId: string|null, capacity: number, me: string|null, hostId: string|null,
 *   participants: Participant[], media: Media|null, playback: Playback|null, chat: ChatMessage[],
 *   connection: 'idle'|'connecting'|'open'|'reconnecting'|'closed'}} RoomState
 */

/** @returns {RoomState} */
export function initialState() {
  return {
    roomId: null,
    capacity: 5,
    me: null,
    hostId: null,
    participants: [],
    media: null,
    playback: null,
    chat: [],
    connection: 'idle',
  };
}

/**
 * Applies one server message (or a local "connection" event) and returns the next state.
 * Unknown messages leave the state untouched.
 *
 * @param {RoomState} state
 * @param {any} event
 * @returns {RoomState}
 */
export function reduce(state, event) {
  switch (event?.type) {
    case 'welcome': {
      const room = event.room;
      return {
        ...state,
        roomId: room.roomId,
        capacity: room.capacity,
        me: event.you.participantId,
        hostId: room.hostId,
        participants: room.participants,
        media: room.media,
        playback: room.playback,
        // A resume replaces the history: the server's copy is authoritative.
        chat: room.chat.slice(-CHAT_LIMIT),
        connection: 'open',
      };
    }
    case 'participant.joined':
    case 'participant.updated': {
      const incoming = event.participant;
      const exists = state.participants.some((p) => p.id === incoming.id);
      const participants = exists
        ? state.participants.map((p) => (p.id === incoming.id ? incoming : p))
        : [...state.participants, incoming];
      // isHost is carried per participant; keep hostId consistent with the latest claim.
      let hostId = state.hostId;
      if (incoming.isHost) {
        hostId = incoming.id;
      } else if (hostId === incoming.id) {
        hostId = null;
      }

      return {
        ...state,
        hostId,
        participants: participants.map((
          p,
        ) => (p.isHost === (p.id === hostId) ? p : { ...p, isHost: p.id === hostId })),
      };
    }
    case 'participant.left': {
      return {
        ...state,
        participants: state.participants.filter((p) => p.id !== event.participantId),
        hostId: state.hostId === event.participantId ? null : state.hostId,
      };
    }
    case 'media.changed':
      return { ...state, media: event.media, playback: event.playback };
    case 'playback.state':
      // Out-of-order or duplicate states are ignored: seq is monotonic per room.
      if (state.playback !== null && event.playback.seq <= state.playback.seq) {
        return state;
      }
      return { ...state, playback: event.playback };
    case 'chat.message': {
      if (state.chat.some((m) => m.id === event.message.id)) {
        return state;
      }
      const chat = [...state.chat, event.message];
      return { ...state, chat: chat.length > CHAT_LIMIT ? chat.slice(-CHAT_LIMIT) : chat };
    }
    case 'connection':
      return { ...state, connection: event.state };
    case 'reset':
      return initialState();
    default:
      return state;
  }
}

/** @param {RoomState} state */
export function isHost(state) {
  return state.me !== null && state.hostId === state.me;
}

/** @param {RoomState} state */
export function self(state) {
  return state.participants.find((p) => p.id === state.me) ?? null;
}

/** A tiny observable store around {@link reduce}. */
export class Store {
  /** @param {RoomState} [state] */
  constructor(state = initialState()) {
    this.state = state;
    /** @type {Set<(state: RoomState, previous: RoomState) => void>} */
    this.listeners = new Set();
  }

  dispatch(event) {
    const previous = this.state;
    this.state = reduce(previous, event);
    if (this.state !== previous) {
      for (const listener of this.listeners) {
        listener(this.state, previous);
      }
    }
  }

  /** @returns {() => void} unsubscribe */
  subscribe(listener) {
    this.listeners.add(listener);
    return () => this.listeners.delete(listener);
  }
}
