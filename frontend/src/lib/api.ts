/**
 * The API client.
 *
 * Every response uses the envelope described in docs/api.md section 2, so
 * unwrapping and error translation happen here once rather than at each call
 * site. Callers receive the payload or an ApiError carrying a stable code.
 */

export interface ApiMeta {
  server_time: string;
}

export class ApiError extends Error {
  constructor(
    /** Stable machine-readable code. Branch on this, never on the message. */
    readonly code: string,
    readonly status: number,
    readonly details: Record<string, unknown>,
    developerMessage: string,
  ) {
    super(developerMessage);
    this.name = 'ApiError';
  }

  get isUnauthenticated(): boolean {
    return this.code === 'AUTHENTICATION_REQUIRED' || this.status === 401;
  }
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  body?: unknown;
  /** Sent on requests that grant or consume resources. See docs/api.md §4. */
  idempotencyKey?: string;
}

/** Latest server clock reading, used to interpolate accruing values. */
let lastServerTime: string | null = null;

export function serverTime(): string | null {
  return lastServerTime;
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const headers: Record<string, string> = {};

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (options.idempotencyKey) {
    headers['Idempotency-Key'] = options.idempotencyKey;
  }

  const response = await fetch(`/api/v1${path}`, {
    method: options.method ?? 'GET',
    headers,
    // Session cookies. The API is same-origin via the Vite proxy.
    credentials: 'same-origin',
    ...(options.body !== undefined ? { body: JSON.stringify(options.body) } : {}),
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const text = await response.text();
  const payload: unknown = text === '' ? {} : JSON.parse(text);

  if (!response.ok) {
    const error = (payload as { error?: { code?: string; message?: string; details?: Record<string, unknown> } })
      .error;

    throw new ApiError(
      error?.code ?? 'INTERNAL_ERROR',
      response.status,
      error?.details ?? {},
      error?.message ?? 'Request failed.',
    );
  }

  const envelope = payload as { data: T; meta?: ApiMeta };

  if (envelope.meta?.server_time) {
    lastServerTime = envelope.meta.server_time;
  }

  return envelope.data;
}

/**
 * A key unique to one user intent.
 *
 * Generated when the action is initiated, not when the request is sent, so a
 * retry of the same intent reuses it and the server recognises the replay.
 */
export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}
