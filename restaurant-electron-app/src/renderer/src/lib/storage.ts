import { createJSONStorage } from 'zustand/middleware';

/** Saved stores live on this PC through the main process (src/main/store.ts). */
export const diskStorage = createJSONStorage(() => ({
  getItem: (key: string) => window.desktop.storeGet(key),
  setItem: (key: string, value: string) => window.desktop.storeSet(key, value),
  removeItem: (key: string) => window.desktop.storeRemove(key),
}));

export const disk = {
  async get<T>(key: string): Promise<T | null> {
    const raw = await window.desktop.storeGet(key);
    if (!raw) return null;
    try {
      return JSON.parse(raw) as T;
    } catch {
      return null;
    }
  },
  set: (key: string, value: unknown) => window.desktop.storeSet(key, JSON.stringify(value)),
  remove: (key: string) => window.desktop.storeRemove(key),
};
