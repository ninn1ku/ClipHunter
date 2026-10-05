// Thin fetch wrapper: JSON in/out, timeouts, and one error shape for everything.

export class ApiError extends Error {
  /**
   * @param {string} code machine code (server error code or a client-side one)
   * @param {string|null} serverMessage user-facing message from the server, if any
   * @param {number} status HTTP status (0 for network errors)
   * @param {number|null} retryAfter seconds, from the Retry-After header
   */
  constructor(code, serverMessage, status, retryAfter = null) {
    super(code);
    this.code = code;
    this.serverMessage = serverMessage;
    this.status = status;
    this.retryAfter = retryAfter;
  }
}

/**
 * @param {'GET'|'POST'|'DELETE'} method
 * @param {string} path same-origin API path
 * @param {object|null} body JSON body
 * @param {{timeoutMs?: number}} options
 * @returns {Promise<any>} parsed JSON (null for 204)
 */
export async function api(method, path, body = null, { timeoutMs = 20000 } = {}) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  const headers = { Accept: 'application/json' };
  if (body !== null) {
    headers['Content-Type'] = 'application/json';
  }

  let response;
  try {
    response = await fetch(path, {
      method,
      headers,
      body: body === null ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal,
    });
  } catch {
    throw new ApiError(controller.signal.aborted ? 'CLIENT_TIMEOUT' : 'NETWORK_ERROR', null, 0);
  } finally {
    clearTimeout(timer);
  }

  if (response.status === 204) {
    return null;
  }

  let data = null;
  try {
    data = await response.json();
  } catch {
    // Non-JSON (e.g. a proxy error page): handled below by status.
  }

  if (!response.ok) {
    const error = data && typeof data === 'object' ? data.error : null;
    const code = error && typeof error.code === 'string' ? error.code : `HTTP_${response.status}`;
    const message = error && typeof error.message === 'string' ? error.message : null;
    const retryAfter = Number.parseInt(response.headers.get('Retry-After') ?? '', 10);
    throw new ApiError(code, message, response.status, Number.isFinite(retryAfter) ? retryAfter : null);
  }

  return data;
}
