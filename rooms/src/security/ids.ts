/**
 * Identifiers and secrets (docs/WATCH_PARTY_PLAN.md §2.5).
 *
 * - roomId: 12 Crockford Base32 characters (60 bits) — the room link, a capability.
 * - participantId: 10 characters, public, used in events.
 * - token: 32 random bytes, base64url — the reconnect secret. Only its SHA-256 is stored.
 */

import { createHash, createHmac, timingSafeEqual } from 'node:crypto';

const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

export const ROOM_ID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{12}$/;
export const PARTICIPANT_ID_PATTERN = /^[0-9A-HJKMNP-TV-Z]{10}$/;
export const TOKEN_PATTERN = /^[A-Za-z0-9_-]{43}$/;

function randomBase32(length: number): string {
  const bytes = crypto.getRandomValues(new Uint8Array(length));
  let out = '';
  for (const byte of bytes) {
    // 256 is a multiple of 32, so the low 5 bits are uniformly distributed.
    out += CROCKFORD[byte & 31];
  }

  return out;
}

export function newRoomId(): string {
  return randomBase32(12);
}

export function newParticipantId(): string {
  return randomBase32(10);
}

export function newToken(): string {
  return base64url(crypto.getRandomValues(new Uint8Array(32)));
}

export function sha256Hex(value: string): string {
  return createHash('sha256').update(value).digest('hex');
}

/** Constant-time check of a presented token against a stored SHA-256 hex digest. */
export function tokenMatches(token: unknown, storedHash: string): boolean {
  if (typeof token !== 'string' || !TOKEN_PATTERN.test(token)) {
    return false;
  }
  const presented = new TextEncoder().encode(sha256Hex(token));
  const stored = new TextEncoder().encode(storedHash);

  return presented.length === stored.length && timingSafeEqual(presented, stored);
}

/** Pseudonymizes an IP for logs and per-IP limits: HMAC-SHA256 with the secret, 16 hex characters. */
export function ipHasher(secretHex: string): (ip: string) => string {
  const key = hexToBytes(secretHex);

  return (ip) => createHmac('sha256', key).update(ip).digest('hex').slice(0, 16);
}

export function base64url(bytes: Uint8Array): string {
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Decodes unpadded base64url; null if the input is not valid base64url. */
export function base64urlDecode(text: string): Uint8Array<ArrayBuffer> | null {
  if (!/^[A-Za-z0-9_-]*$/.test(text) || text.length % 4 === 1) {
    return null;
  }
  try {
    const binary = atob(text.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - text.length % 4) % 4));

    return Uint8Array.from(binary, (ch) => ch.charCodeAt(0));
  } catch {
    return null;
  }
}

export function hexToBytes(hex: string): Uint8Array<ArrayBuffer> {
  if (!/^(?:[a-f0-9]{2})+$/.test(hex)) {
    throw new Error('Invalid hex string.');
  }

  return Uint8Array.from(hex.match(/../g) ?? [], (pair) => parseInt(pair, 16));
}
