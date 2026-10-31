import { PRODUCT_PATH, reach, workspaceUrl } from '../config';

export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;
  offline: boolean;
  code: string | null;

  constructor(message: string, status = 0, errors: Record<string, string[]> = {}, offline = false, code: string | null = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.offline = offline;
    this.code = code;
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

export const isOffline = (error: unknown) => error instanceof ApiError && error.offline;

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    return error.offline ? 'No connection to the restaurant. Check the network, then try again.' : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

interface RequestContext {
  workspace: string | null;
  branchId: string | null;
  /** A valid access token, refreshed when it is about to expire. */
  token: (forceRefresh?: boolean) => Promise<string | null>;
  /** The platform no longer accepts this sign-in. */
  onSignedOut: () => void;
  /** Whether the restaurant answered: every call says. */
  onReachable: (reachable: boolean) => void;
}

let context: RequestContext = {
  workspace: null,
  branchId: null,
  token: async () => null,
  onSignedOut: () => {},
  onReachable: () => {},
};

export function setRequestContext(next: Partial<RequestContext>) {
  context = { ...context, ...next };
}

/**
 * Online mode: a call to the workspace's restaurant,
 * {workspace}/restaurant/api/<path>, with the app's access token and the
 * branch worked in. A rejected token is refreshed once and the call retried.
 */
export async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  extraHeaders: Record<string, string> = {},
  retried = false,
): Promise<T> {
  if (!context.workspace) throw new ApiError('Sign in again.', 401);

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...extraHeaders,
  };
  const token = await context.token(retried);
  if (token) headers.Authorization = `Bearer ${token}`;
  if (context.branchId) headers['X-Branch-Id'] = context.branchId;

  const target = reach(`${workspaceUrl(context.workspace)}/${PRODUCT_PATH}/api/${path.replace(/^\//, '')}`);

  let response: Response;
  try {
    response = await fetch(target.url, {
      method,
      headers: { ...headers, ...target.headers },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
  } catch {
    context.onReachable(false);
    throw new ApiError('Network request failed', 0, {}, true);
  }
  context.onReachable(true);

  const text = await response.text();
  const json = text ? safeJson(text) : null;

  if (response.status === 401 && !retried && json?.code === 'invalid_token') {
    return request<T>(method, path, body, extraHeaders, true);
  }

  if (!response.ok) {
    if (response.status === 401) context.onSignedOut();
    const errors = (json?.errors ?? {}) as Record<string, string[]>;
    const firstError = Object.values(errors)[0]?.[0];
    const message =
      firstError ??
      json?.message ??
      (response.status === 403 ? 'You may not do that here.' : response.status === 404 ? 'Not found.' : `Request failed (${response.status})`);
    throw new ApiError(message, response.status, errors, false, json?.code ?? null);
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
