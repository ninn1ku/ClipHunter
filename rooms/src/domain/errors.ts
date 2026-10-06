/** Error codes sent to clients (protocol v1, docs/WATCH_PARTY_PLAN.md §5.2). The client owns the wording. */
export type ErrorCode =
  | 'BAD_MESSAGE'
  | 'UNKNOWN_TYPE'
  | 'NOT_IN_ROOM'
  | 'ALREADY_IN_ROOM'
  | 'ROOM_NOT_FOUND'
  | 'ROOM_FULL'
  | 'ROOM_LIMIT'
  | 'NAME_INVALID'
  | 'NAME_TAKEN'
  | 'RESUME_FAILED'
  | 'FORBIDDEN'
  | 'INVALID_TICKET'
  | 'TICKET_EXPIRED'
  | 'INVALID_POSITION'
  | 'NO_MEDIA'
  | 'CHAT_INVALID'
  | 'RATE_LIMITED'
  | 'KICKED'
  | 'SERVER_BUSY'
  | 'INTERNAL_ERROR';

export interface Failure {
  ok: false;
  code: ErrorCode;
}

export function fail(code: ErrorCode): Failure {
  return { ok: false, code };
}
