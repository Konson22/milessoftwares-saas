/// <reference types="jest" />
/// <reference types="node" />
import { decodeItems } from './decode';
import { uuidv7 } from './uuid';

describe('reading stored items', () => {
  it('quarantines a corrupted row without losing the others', () => {
    const good = { id: 's1', scope: 'acme|u1', answers: { members: 2 }, startedAt: '2026-11-17T09:00:00Z' };
    const { items, bad } = decodeItems([
      { key: 'a', body: JSON.stringify(good) },
      { key: 'b', body: '{"id": "s2", "answers": {"memb' },
      { key: 'c', body: JSON.stringify({ hello: 'world' }) },
    ]);
    expect(items.map((i) => i.id)).toEqual(['s1']);
    expect(bad.map((r) => r.key)).toEqual(['b', 'c']);
  });
});

describe('submission ids', () => {
  it('makes RFC 9562 version 7 ids that sort by time', () => {
    const random = (n: number) => new Uint8Array(n).fill(0xff);
    const a = uuidv7(Date.parse('2026-11-17T09:00:00Z'), random);
    const b = uuidv7(Date.parse('2026-11-17T09:00:01Z'), random);
    expect(a).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    expect(a < b).toBe(true);
    expect(parseInt(a.replace(/-/g, '').slice(0, 12), 16)).toBe(Date.parse('2026-11-17T09:00:00Z'));
  });
});
