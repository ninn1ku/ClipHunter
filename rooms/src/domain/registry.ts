/**
 * All live rooms of this process: creation under a global limit, lookup, and the lifecycle sweep
 * (reconnect grace, empty rooms, maximum age).
 */

import type { Clock } from '../clock.ts';
import { newRoomId, ROOM_ID_PATTERN } from '../security/ids.ts';
import { Room, type RoomEvent, type RoomState } from './room.ts';

export interface RegistryLimits {
  maxRooms: number;
  maxParticipants: number;
  emptyTtlMs: number;
  maxAgeMs: number;
  reconnectGraceMs: number;
}

export type CloseReason = 'expired' | 'empty';

export interface SweepResult {
  /** Events from participants whose grace ran out, per room still alive. */
  updates: Array<{ room: Room; events: RoomEvent[] }>;
  /** Rooms removed from the registry. Sockets of expired rooms must be closed (4004). */
  closed: Array<{ room: Room; reason: CloseReason }>;
}

export class RoomRegistry {
  private readonly rooms = new Map<string, Room>();
  /** Set on every change worth persisting; cleared by the snapshot writer. */
  dirty = false;

  constructor(
    private readonly limits: RegistryLimits,
    private readonly clock: Clock,
  ) {}

  get size(): number {
    return this.rooms.size;
  }

  /** A new empty room, or null when ROOMS_MAX_ROOMS is reached. */
  create(): Room | null {
    if (this.rooms.size >= this.limits.maxRooms) {
      return null;
    }
    let id = newRoomId();
    while (this.rooms.has(id)) {
      id = newRoomId();
    }
    const room = new Room(id, this.clock.now(), this.limits.maxParticipants);
    this.rooms.set(id, room);
    this.dirty = true;

    return room;
  }

  get(id: unknown): Room | undefined {
    return typeof id === 'string' && ROOM_ID_PATTERN.test(id) ? this.rooms.get(id) : undefined;
  }

  delete(id: string): void {
    if (this.rooms.delete(id)) {
      this.dirty = true;
    }
  }

  all(): IterableIterator<Room> {
    return this.rooms.values();
  }

  sweep(): SweepResult {
    const now = this.clock.now();
    const result: SweepResult = { updates: [], closed: [] };

    for (const room of [...this.rooms.values()]) {
      if (room.createdAt + this.limits.maxAgeMs <= now) {
        this.rooms.delete(room.id);
        result.closed.push({ room, reason: 'expired' });
        continue;
      }

      const { events } = room.expireGrace(now, this.limits.reconnectGraceMs);
      if (events.length > 0) {
        result.updates.push({ room, events });
      }

      if (room.emptySince !== null && room.emptySince + this.limits.emptyTtlMs <= now) {
        this.rooms.delete(room.id);
        result.closed.push({ room, reason: 'empty' });
      }
    }

    if (result.updates.length > 0 || result.closed.length > 0) {
      this.dirty = true;
    }

    return result;
  }

  toStates(): RoomState[] {
    return [...this.rooms.values()].map((room) => room.toState());
  }

  /** Restores rooms from a snapshot, dropping those that expired meanwhile. Returns how many survived. */
  restore(states: RoomState[]): number {
    const now = this.clock.now();
    for (const state of states) {
      if (this.rooms.size >= this.limits.maxRooms || this.rooms.has(state.id)) {
        continue;
      }
      if (state.createdAt + this.limits.maxAgeMs <= now) {
        continue;
      }
      if (state.participants.length === 0 && (state.emptySince ?? now) + this.limits.emptyTtlMs <= now) {
        continue;
      }
      this.rooms.set(state.id, Room.fromState(state, now));
    }

    return this.rooms.size;
  }
}
