import { mkdir, readFile, rename, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import { app } from 'electron';

/**
 * The app's data on this PC: one JSON file a key under userData/data. A
 * write goes to a temporary file first and is renamed over the old one, so
 * a crash or power cut mid-write never leaves half a cart or half a queue.
 */

const dir = () => join(app.getPath('userData'), 'data');
const file = (key: string) => join(dir(), `${key.replace(/[^a-zA-Z0-9._-]/g, '_')}.json`);

/** Writes to one key run one after another. */
const pending = new Map<string, Promise<void>>();

function serial(key: string, task: () => Promise<void>): Promise<void> {
  const next = (pending.get(key) ?? Promise.resolve()).then(task, task);
  pending.set(key, next);
  void next.finally(() => pending.get(key) === next && pending.delete(key));
  return next;
}

export async function storeGet(key: string): Promise<string | null> {
  await pending.get(key)?.catch(() => {});
  try {
    return await readFile(file(key), 'utf8');
  } catch {
    return null;
  }
}

export function storeSet(key: string, value: string): Promise<void> {
  return serial(key, async () => {
    await mkdir(dir(), { recursive: true });
    const tmp = `${file(key)}.tmp`;
    await writeFile(tmp, value, 'utf8');
    await rename(tmp, file(key));
  });
}

export function storeRemove(key: string): Promise<void> {
  return serial(key, () => rm(file(key), { force: true }));
}
