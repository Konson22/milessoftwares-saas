import { PRODUCT_PATH, reach, workspaceUrl } from '../config';

/*
 * Calls to Logbook in the chosen workspace, {workspace}/logbook/api/<path>, with
 * the app's access token (Central, PKCE). A rejected token is refreshed
 * once and the call retried; a workspace's old address follows it to the
 * new one. Calls time out, so a dead network never leaves a request hanging.
 */

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;
  offline: boolean;
  timeout: boolean;
  code: string | null;
  body: Record<string, unknown> | null;

  constructor(message: string, status = 0, errors: Record<string, string[]> = {}, offline = false, code: string | null = null, body: Record<string, unknown> | null = null, timeout = false) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.offline = offline;
    this.code = code;
    this.body = body;
    this.timeout = timeout;
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

export const isOffline = (error: unknown) => error instanceof ApiError && error.offline;

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.offline ? 'No connection to Logbook. Check the network, then try again.' : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

interface RequestContext {
  workspace: string | null;
  /** A valid access token, refreshed when it is about to expire. */
  token: (forceRefresh?: boolean) => Promise<string | null>;
  /** The platform no longer accepts this sign-in. */
  onSignedOut: () => void;
  /** Whether Logbook answered: every call says. */
  onReachable: (reachable: boolean) => void;
  /** The workspace changed its address; calls go to the new one from now on. */
  onMoved: (from: string, to: string) => Promise<void>;
}

let context: RequestContext = {
  workspace: null,
  token: async () => null,
  onSignedOut: () => {},
  onReachable: () => {},
  onMoved: async () => {},
};

export function setRequestContext(next: Partial<RequestContext>) {
  context = { ...context, ...next };
}

export const currentWorkspace = () => context.workspace;

const MOVED = 'workspace_moved';
const SLUG = /^[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/;

/** A JSON call; 30 s unless said otherwise (photos take longer). */
export async function request<T>(method: string, path: string, body?: unknown, options: { timeoutMs?: number; form?: FormData } = {}, retried = false, moved = false): Promise<T> {
  if (!context.workspace) throw new ApiError('Sign in again.', 401);

  const headers: Record<string, string> = { Accept: 'application/json' };
  if (!options.form) headers['Content-Type'] = 'application/json';
  const token = await context.token(retried);
  if (token) headers.Authorization = `Bearer ${token}`;

  const target = reach(`${workspaceUrl(context.workspace)}/${PRODUCT_PATH}/api/${path.replace(/^\//, '')}`);
  const abort = new AbortController();
  const timer = setTimeout(() => abort.abort(), options.timeoutMs ?? 30_000);

  let response: Response;
  try {
    response = await fetch(target.url, {
      method,
      headers: { ...headers, ...target.headers },
      body: options.form ?? (body === undefined ? undefined : JSON.stringify(body)),
      signal: abort.signal,
    });
  } catch {
    context.onReachable(false);
    const timedOut = abort.signal.aborted;
    throw new ApiError(timedOut ? 'Logbook took too long to answer.' : 'Network request failed', 0, {}, !timedOut, null, null, timedOut);
  } finally {
    clearTimeout(timer);
  }
  context.onReachable(true);

  const text = await response.text();
  const json = text ? safeJson(text) : null;

  if (response.status === 401 && !retried && json?.code === 'invalid_token') {
    return request<T>(method, path, body, options, true, moved);
  }

  if (response.status === 421 && !moved && json?.code === MOVED && typeof json.slug === 'string' && SLUG.test(json.slug)) {
    const from = context.workspace;
    if (from && from !== json.slug) {
      await context.onMoved(from, json.slug);
      return request<T>(method, path, body, options, retried, true);
    }
  }

  if (!response.ok) {
    if (response.status === 401) context.onSignedOut();
    const errors = (json?.errors ?? {}) as Record<string, string[] | string>;
    const normalized = Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v : [String(v)]]));
    const message =
      json?.message ?? (response.status === 403 ? 'You may not do that here.' : response.status === 404 ? 'Not found.' : `Request failed (${response.status})`);
    throw new ApiError(message, response.status, normalized, false, json?.code ?? null, json);
  }

  return json as T;
}

function safeJson(text: string): any {
  try {
    return JSON.parse(text);
  } catch {
    return null;
  }
}

/**
 * A file of the API as an image source or download: its address and the
 * headers that carry the sign-in (and the host, in local development).
 */
export async function fileSource(path: string): Promise<{ uri: string; headers: Record<string, string> } | null> {
  if (!context.workspace) return null;
  const token = await context.token();
  const target = reach(`${workspaceUrl(context.workspace)}/${PRODUCT_PATH}/api/${path.replace(/^\//, '')}`);
  return { uri: target.url, headers: { ...target.headers, ...(token ? { Authorization: `Bearer ${token}` } : {}) } };
}
