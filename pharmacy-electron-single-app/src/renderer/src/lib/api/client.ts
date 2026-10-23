export class ApiError extends Error {
  status: number;
  errors: Record<string, string[]>;
  code: string | null;

  constructor(message: string, status = 0, errors: Record<string, string[]> = {}, code: string | null = null) {
    super(message);
    this.status = status;
    this.errors = errors;
    this.code = code;
  }

  /** First validation message for a field, if any. */
  field(name: string): string | undefined {
    return this.errors[name]?.[0];
  }
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Something went wrong.';
}

let onSignedOut: () => void = () => {};

/** Called when the main process no longer has anyone signed in (after a restore, say). */
export function setSignedOutHandler(handler: () => void) {
  onSignedOut = handler;
}

/**
 * Asks the pharmacy's database (src/main/handlers.ts) by name. Its errors
 * come back as ApiError, with the fields a form shows them against.
 */
export async function call<T>(name: string, ...args: unknown[]): Promise<T> {
  const result = await window.desktop.call(name, ...args);
  if (result.ok) return result.value as T;
  const { message, status, errors, code } = result.error;
  if (status === 401 && code === 'session_required') onSignedOut();
  throw new ApiError(message, status, errors, code);
}
