import { handlers, LocalError } from '@/local/handlers';

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

/** Called when no one is signed in any more (after a restore, say). */
export function setSignedOutHandler(handler: () => void) {
  onSignedOut = handler;
}

/**
 * Asks the shop's database on this phone (src/local/handlers.ts) by
 * name. It answers synchronously; the call still resolves on a later tick so
 * a screen can show that it is busy first. Its errors come back as ApiError,
 * with the fields a form shows them against.
 */
export async function call<T>(name: string, ...args: unknown[]): Promise<T> {
  const handler = Object.hasOwn(handlers, name) ? handlers[name] : null;
  if (!handler) throw new ApiError(`Unknown call ${name}.`, 404);
  await new Promise((resolve) => setTimeout(resolve, 0));
  try {
    return (await handler(...args)) as T;
  } catch (error) {
    if (error instanceof LocalError) {
      if (error.status === 401 && error.code === 'session_required') onSignedOut();
      throw new ApiError(error.message, error.status, error.errors, error.code);
    }
    console.error(`Call ${name} failed`, error);
    throw new ApiError(error instanceof Error ? error.message : 'Something went wrong.', 500);
  }
}
