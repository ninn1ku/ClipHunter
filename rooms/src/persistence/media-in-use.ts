/**
 * storage/rooms/media-in-use.json: which server-prepared files are needed right now.
 *
 * The rooms service writes the media ids of file-mode videos in rooms that still have
 * participants (including those within the reconnect grace period). The PHP cleaner reads it and
 * keeps those files, and every other variant of the same video, while the list is fresh. When
 * everyone has left, the id drops out and the file is removed after WATCH_IDLE_TTL_MIN.
 */

import type { Room } from '../domain/room.ts';

export const MEDIA_IN_USE_FILE = 'media-in-use.json';

/** Media ids (sorted, unique) of file-mode videos in non-empty rooms. */
export function mediaInUse(rooms: Iterable<Room>): string[] {
  const refs = new Set<string>();
  for (const room of rooms) {
    if (room.size > 0 && room.media?.kind === 'file') {
      refs.add(room.media.ref);
    }
  }

  return [...refs].sort();
}

/** Atomic write (temp file + rename, mode 0640). `updatedAt` lets readers detect a dead writer. */
export async function writeMediaInUse(dir: string, refs: string[], now: number): Promise<void> {
  const target = `${dir}/${MEDIA_IN_USE_FILE}`;
  const tmp = `${target}.tmp`;
  await Deno.writeTextFile(tmp, JSON.stringify({ version: 1, updatedAt: now, refs }), { mode: 0o640 });
  if (Deno.build.os !== 'windows') {
    await Deno.chmod(tmp, 0o640);
  }
  await Deno.rename(tmp, target);
}
