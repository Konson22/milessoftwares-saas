import { call } from './client';
import type { LocalApi } from './types';

/** Offline: every method is a call of the same name to the main process (src/main/handlers.ts). */
export const localApi = new Proxy({} as LocalApi, {
  get: (_target, name: string) => (...args: unknown[]) => call(name, ...args),
});
