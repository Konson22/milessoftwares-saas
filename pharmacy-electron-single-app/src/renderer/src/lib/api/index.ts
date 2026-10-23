import { call } from './client';
import type { PosApi } from './types';

/** Every PosApi method is a call of the same name to the main process. */
export const api = new Proxy({} as PosApi, {
  get: (_target, name: string) => (...args: unknown[]) => call(name, ...args),
});

export { ApiError, call, errorMessage, setSignedOutHandler } from './client';
