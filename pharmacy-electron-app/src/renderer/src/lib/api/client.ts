import { currentProduct, place } from '../config';

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
    return error.offline ? `No connection to the ${place()}. Working offline.` : error.message;
  }
  return error instanceof Error ? error.message : 'Something went wrong.';
}

interface RequestContext {
  workspace: string | null;
  branchId: string | null;
  /** The platform (or, offline, this PC) no longer has this person signed in. */
  onSignedOut: () => void;
  /** Every call tells whether the pharmacy (or shop) could be reached. */
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

export function reportReachable(reachable: boolean) {
  context.onReachable(reachable);
}

/**
 * Offline: asks the pharmacy's database on this PC (src/main/handlers.ts) by
 * name. Its errors come back as ApiError, with the fields a form shows them
 * against.
 */
export async function call<T>(name: string, ...args: unknown[]): Promise<T> {
  const result = await window.desktop.call(name, ...args);
  if (result.ok) return result.value as T;
  const { message, status, errors, code } = result.error;
  // No one signed in any more (after a restore, say).
  if (status === 401 && code === 'session_required') context.onSignedOut();
  throw new ApiError(message, status, errors, false, code);
}

/**
 * Hybrid: a call to the workspace's pharmacy or shop: {workspace}/<its path>/api/<path>.
 * The main process adds the token and makes the call (src/main/http.ts).
 */
export async function request<T>(method: string, path: string, body?: unknown, headers?: Record<string, string>): Promise<T> {
  if (!context.workspace) throw new ApiError('Sign in again.', 401);

  const response = await window.desktop.api({
    method,
    path,
    body,
    headers,
    product: currentProduct(),
    workspace: context.workspace,
    branchId: context.branchId,
  });
  if (response.offline) {
    context.onReachable(false);
    throw new ApiError('Network request failed', 0, {}, true);
  }
  context.onReachable(true);

  const { status, json } = response;
  if (status < 200 || status >= 300) {
    if (status === 401) context.onSignedOut();
    const errors = (json?.errors ?? {}) as Record<string, string[]>;
    const firstError = Object.values(errors)[0]?.[0];
    throw new ApiError(firstError ?? json?.message ?? `Request failed (${status})`, status, errors, false, json?.code ?? null);
  }
  return json as T;
}
