import type { Item } from './types';

/**
 * Reads stored items. A row that no longer parses (a storage fault, a
 * half-written record from an old build) is never dropped silently and
 * never blocks the others: it is returned apart, for quarantine.
 */
export function decodeItems(rows: { key: string; body: string }[]): { items: Item[]; bad: { key: string; body: string }[] } {
  const items: Item[] = [];
  const bad: { key: string; body: string }[] = [];
  for (const row of rows) {
    try {
      const item = JSON.parse(row.body) as Item;
      if (!item || typeof item.id !== 'string' || typeof item.scope !== 'string' || typeof item.answers !== 'object' || item.answers === null || typeof item.startedAt !== 'string') {
        throw new Error('Not an item');
      }
      items.push(item);
    } catch {
      bad.push(row);
    }
  }
  return { items: items.sort((a, b) => a.startedAt.localeCompare(b.startedAt)), bad };
}
