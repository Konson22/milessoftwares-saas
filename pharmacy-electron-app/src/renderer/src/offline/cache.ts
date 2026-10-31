import { isOffline } from '@/lib/api';
import { isLocal } from '@/lib/mode';
import { disk } from '@/lib/storage';

/**
 * What the pharmacy last said, kept on this PC so the counter still shows it
 * offline: the branch's rules, the shift, the day's sales, a receipt. Kept
 * per person and branch (the owner), as the pharmacy answers per person.
 * Offline mode needs none of it: the pharmacy is on this PC.
 */

export interface Cached<T> {
  data: T;
  /** False: the pharmacy could not be reached, this is what it said at savedAt. */
  fresh: boolean;
  savedAt: string;
}

const memory = new Map<string, { data: unknown; savedAt: string }>();
const keyOf = (owner: string, key: string) => `cache.${owner}.${key}`.replace(/[^a-zA-Z0-9._-]/g, '_');

/** Asks the pharmacy and saves the answer; offline, the saved answer (if any). */
export async function cached<T>(owner: string, key: string, fetch: () => Promise<T>): Promise<Cached<T>> {
  if (isLocal()) return { data: await fetch(), fresh: true, savedAt: new Date().toISOString() };
  const k = keyOf(owner, key);
  try {
    const data = await fetch();
    const savedAt = new Date().toISOString();
    memory.set(k, { data, savedAt });
    void disk.set(k, { data, savedAt });
    return { data, fresh: true, savedAt };
  } catch (error) {
    if (!isOffline(error)) throw error;
    const saved = await peek<T>(owner, key);
    if (!saved) throw error;
    return { ...saved, fresh: false };
  }
}

/** The saved answer only, without asking. */
export async function peek<T>(owner: string, key: string): Promise<{ data: T; savedAt: string } | null> {
  const k = keyOf(owner, key);
  const hit = memory.get(k) ?? (await disk.get<{ data: T; savedAt: string }>(k));
  if (hit) memory.set(k, hit);
  return (hit as { data: T; savedAt: string } | null) ?? null;
}

/** Replaces the saved answer (after a change made here, say). */
export function remember<T>(owner: string, key: string, data: T) {
  if (isLocal()) return;
  const k = keyOf(owner, key);
  const entry = { data, savedAt: new Date().toISOString() };
  memory.set(k, entry);
  void disk.set(k, entry);
}
