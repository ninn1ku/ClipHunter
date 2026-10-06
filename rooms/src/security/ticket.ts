/**
 * Verification of media tickets signed by PHP (src/Watch/MediaTicket.php, docs/WATCH_PARTY_PLAN.md §2.3).
 *
 *   ticket = base64url(payloadJson) "." base64url(HMAC-SHA256(hex2bin(ROOMS_SECRET), base64url(payloadJson)))
 *
 * A room accepts media only through a valid ticket, so the rooms service never trusts a URL or a
 * title from a browser. Every payload field is validated again here (defense in depth).
 */

import { sanitizeTitle } from '../domain/text.ts';
import { base64urlDecode, hexToBytes } from './ids.ts';

export type MediaKind = 'youtube' | 'file';

export interface TicketMedia {
  kind: MediaKind;
  ref: string;
  platform: string;
  title: string | null;
  durationSec: number | null;
  thumbnailUrl: string | null;
  startSec: number;
}

export type TicketResult =
  | { ok: true; media: TicketMedia }
  | { ok: false; code: 'INVALID_TICKET' | 'TICKET_EXPIRED'; reason: string };

export const TICKET_VERSION = 1;
export const MAX_TICKET_LENGTH = 4096;
/** PHP issues tickets valid for 600 s; anything further out was not issued by it. */
export const MAX_TICKET_LIFETIME_SEC = 900;
const MAX_DURATION_SEC = 172_800;
const MAX_START_SEC = 86_400;

const REF_PATTERN: Record<MediaKind, RegExp> = {
  youtube: /^[A-Za-z0-9_-]{11}$/,
  file: /^[a-f0-9]{32}$/,
};

export class TicketVerifier {
  private readonly key: Promise<CryptoKey>;

  constructor(secretHex: string) {
    this.key = crypto.subtle.importKey(
      'raw',
      hexToBytes(secretHex),
      { name: 'HMAC', hash: 'SHA-256' },
      false,
      [
        'verify',
      ],
    );
  }

  /** @param nowSec current unix time in seconds */
  async verify(ticket: unknown, nowSec: number): Promise<TicketResult> {
    const invalid = (reason: string): TicketResult => ({ ok: false, code: 'INVALID_TICKET', reason });

    if (typeof ticket !== 'string' || ticket.length > MAX_TICKET_LENGTH) {
      return invalid('shape');
    }
    const match = /^([A-Za-z0-9_-]+)\.([A-Za-z0-9_-]{43})$/.exec(ticket);
    const body = match?.[1];
    const signature = match?.[2] === undefined ? null : base64urlDecode(match[2]);
    if (body === undefined || signature === null || signature.length !== 32) {
      return invalid('shape');
    }

    // crypto.subtle.verify compares in constant time.
    const valid = await crypto.subtle.verify(
      'HMAC',
      await this.key,
      signature,
      new TextEncoder().encode(body),
    );
    if (!valid) {
      return invalid('signature');
    }

    const bytes = base64urlDecode(body);
    let payload: unknown;
    try {
      payload = bytes === null ? null : JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
    } catch {
      return invalid('json');
    }
    if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
      return invalid('json');
    }
    const p = payload as Record<string, unknown>;

    if (p.v !== TICKET_VERSION) {
      return invalid('version');
    }
    if (!isInt(p.exp)) {
      return invalid('exp');
    }
    if (p.exp <= nowSec) {
      return { ok: false, code: 'TICKET_EXPIRED', reason: 'expired' };
    }
    if (p.exp > nowSec + MAX_TICKET_LIFETIME_SEC) {
      return invalid('exp_too_far');
    }

    const media = parseMedia(p);

    return media === null ? invalid('payload') : { ok: true, media };
  }
}

function parseMedia(p: Record<string, unknown>): TicketMedia | null {
  const kind = p.kind;
  if (
    (kind !== 'youtube' && kind !== 'file') || typeof p.ref !== 'string' || !REF_PATTERN[kind].test(p.ref)
  ) {
    return null;
  }
  if (typeof p.platform !== 'string' || p.platform.length < 1 || p.platform.length > 64) {
    return null;
  }
  const platform = sanitizeTitle(p.platform);
  if (platform === null) {
    return null;
  }

  let title: string | null = null;
  if (p.title !== null) {
    if (typeof p.title !== 'string' || p.title.length > 1000) {
      return null;
    }
    title = sanitizeTitle(p.title);
  }

  if (
    p.durationSec !== null &&
    !(isInt(p.durationSec) && p.durationSec >= 0 && p.durationSec <= MAX_DURATION_SEC)
  ) {
    return null;
  }
  if (!isInt(p.startSec) || p.startSec < 0 || p.startSec > MAX_START_SEC) {
    return null;
  }

  let thumbnailUrl: string | null = null;
  if (p.thumbnailUrl !== null) {
    if (typeof p.thumbnailUrl !== 'string' || p.thumbnailUrl.length > 2048 || !isHttpsUrl(p.thumbnailUrl)) {
      return null;
    }
    thumbnailUrl = p.thumbnailUrl;
  }

  return {
    kind,
    ref: p.ref,
    platform,
    title,
    durationSec: p.durationSec as number | null,
    thumbnailUrl,
    startSec: p.startSec,
  };
}

function isInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value);
}

function isHttpsUrl(value: string): boolean {
  if (!value.startsWith('https://')) {
    return false;
  }
  try {
    const url = new URL(value);

    return url.protocol === 'https:' && url.username === '' && url.password === '';
  } catch {
    return false;
  }
}
