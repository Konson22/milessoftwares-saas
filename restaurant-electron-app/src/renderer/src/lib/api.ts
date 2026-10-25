import type { Me } from './types';

/**
 * Calls to the workspace's restaurant ({workspace}/restaurant/api/...) and to
 * the platform, made by the main process (src/main/http.ts), which adds the
 * token.
 */

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
    return error.offline ? 'No connection to the restaurant. Check the network.' : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

interface RequestContext {
  workspace: string | null;
  branchId: string | null;
  /** The platform no longer accepts this sign-in. */
  onSignedOut: () => void;
  /** Every call tells whether the restaurant could be reached. */
  onReachable: (reachable: boolean) => void;
}

let context: RequestContext = {
  workspace: null,
  branchId: null,
  onSignedOut: () => {},
  onReachable: () => {},
};

export function setRequestContext(next: Partial<RequestContext>) {
  context = { ...context, ...next };
}

function failure(status: number, json: any): ApiError {
  const errors = (json?.errors ?? {}) as Record<string, string[]>;
  const first = Object.values(errors)[0]?.[0];
  return new ApiError(first ?? json?.message ?? `Request failed (${status})`, status, errors, false, json?.code ?? json?.error ?? null);
}

/** A call to the workspace's restaurant: {workspace}/restaurant/api/<path>. */
export async function request<T>(method: string, path: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
  if (!context.workspace) throw new ApiError('Sign in again.', 401);

  const response = await window.desktop.api({ method, path, body, headers, workspace: context.workspace, branchId: context.branchId });
  if (response.offline) {
    context.onReachable(false);
    throw new ApiError('Network request failed', 0, {}, true);
  }
  context.onReachable(true);

  const { status, json } = response;
  if (status < 200 || status >= 300) {
    if (status === 401) context.onSignedOut();
    throw failure(status, json);
  }
  return json as T;
}

/** After signing in on the platform: the ID token, so the restaurant knows who this is. */
export async function openSession(workspace: string, idToken: string): Promise<void> {
  setRequestContext({ workspace, branchId: null });
  try {
    await request('POST', 'session', { id_token: idToken });
  } catch (error) {
    await window.desktop.signOut();
    if (error instanceof ApiError && error.status === 404) {
      throw new ApiError(`No restaurant at “${workspace}”. Check your workspace address.`, 404);
    }
    if (error instanceof ApiError && error.status === 403) {
      throw new ApiError('Your account has no access to this restaurant. Ask the owner to add you.', 403);
    }
    throw error;
  }
}

export const me = () => request<Me>('GET', 'v1/me');

/** The platform as the person signed in (a new business). */
export async function platform<T>(method: string, path: string, body?: unknown): Promise<T> {
  const response = await window.desktop.platform({ method, path, body });
  if (response.offline) throw new ApiError('Cannot reach Miles Softwares. Check the connection.', 0, {}, true);
  const { status, json } = response;
  if (status < 200 || status >= 300) {
    const error = failure(status, json);
    if (status === 429) error.message = 'Too many attempts. Wait a while, then try again.';
    throw error;
  }
  return json as T;
}
