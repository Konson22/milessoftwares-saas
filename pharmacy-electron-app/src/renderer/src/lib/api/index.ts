import { API_MODE } from '../config';
import { ApiError, reportReachable } from './client';
import { liveApi } from './live';
import { mockApi } from './mock';
import type { PosApi } from './types';

let simulatedOffline = false;

/** Demo only: the mock pharmacy answers as if the network were down. */
export function setSimulatedOffline(offline: boolean) {
  simulatedOffline = offline;
}

/** The demo pharmacy, cut off while "Simulate offline" is on (signing in and out still work). */
const demoApi = new Proxy(mockApi, {
  get(target, key: keyof PosApi) {
    const method = target[key] as (...args: unknown[]) => Promise<unknown>;
    if (key === 'signIn' || key === 'signOut') return method;
    return async (...args: unknown[]) => {
      if (simulatedOffline) {
        reportReachable(false);
        throw new ApiError('Network request failed', 0, {}, true);
      }
      const result = await method.apply(target, args);
      reportReachable(true);
      return result;
    };
  },
});

export const api: PosApi = API_MODE === 'live' ? liveApi : demoApi;

export { API_MODE };
export { ApiError, errorMessage, isOffline, setRequestContext } from './client';
