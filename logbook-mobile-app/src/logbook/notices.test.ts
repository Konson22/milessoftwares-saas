/// <reference types="jest" />
import { belongsHere, parseRoute, routeFromLink, shouldRegister } from './notices';

describe('notice routes', () => {
  it('takes the routes the server sends', () => {
    expect(parseRoute({ screen: 'task', id: 't1' })).toEqual({ screen: 'task', id: 't1' });
    expect(parseRoute({ screen: 'review', id: 'e1' })).toEqual({ screen: 'review', id: 'e1' });
    expect(parseRoute({ screen: 'record', sheet: 's1', occurrence: 'o1' })).toEqual({ screen: 'record', sheet: 's1', occurrence: 'o1' });
  });

  it('opens nothing for a malformed or unknown route', () => {
    expect(parseRoute(null)).toBeNull();
    expect(parseRoute('tasks/1')).toBeNull();
    expect(parseRoute({ screen: 'settings' })).toBeNull();
    expect(parseRoute({ screen: 'task' })).toBeNull();
    expect(parseRoute({ screen: 'record', sheet: 's1' })).toBeNull();
    expect(parseRoute({ screen: 'task', id: 'x'.repeat(500) })).toBeNull();
  });

  it('reads older notices from their web link', () => {
    expect(routeFromLink('tasks/t1', 'task.assigned')).toEqual({ screen: 'task', id: 't1' });
    expect(routeFromLink('entries/e1', 'entry.submitted')).toEqual({ screen: 'review', id: 'e1' });
    expect(routeFromLink('entries/e1', 'entry.returned')).toEqual({ screen: 'entry', id: 'e1' });
    expect(routeFromLink('entries/new?sheet=s1&occurrence=o1', 'schedule.reminder.due')).toEqual({ screen: 'record', sheet: 's1', occurrence: 'o1' });
    expect(routeFromLink('entries/new?sheet=s1', 'schedule.reminder.due')).toBeNull();
    expect(routeFromLink('exceptions/x1', 'exception.assigned')).toBeNull();
  });
});

describe('push on a shared phone', () => {
  const session = { workspace: 'acme', userId: 'u1' };

  it('opens a push only for the person and workspace signed in', () => {
    expect(belongsHere({ userId: 'u1', workspace: 'acme' }, session)).toBe(true);
    expect(belongsHere({ userId: 'u2', workspace: 'acme' }, session)).toBe(false);
    expect(belongsHere({ userId: 'u1', workspace: 'other' }, session)).toBe(false);
    expect(belongsHere({}, session)).toBe(false);
    expect(belongsHere({ userId: 'u1', workspace: 'acme' }, { workspace: null, userId: null })).toBe(false);
  });
});

describe('token registration', () => {
  const at = new Date('2026-11-20T08:00:00Z');

  it('registers first, on a new token or person, and again each day', () => {
    expect(shouldRegister(null, { key: 'acme|u1|T1', at })).toBe(true);
    expect(shouldRegister({ key: 'acme|u1|T1', at: '2026-11-20T07:00:00Z' }, { key: 'acme|u1|T1', at })).toBe(false);
    expect(shouldRegister({ key: 'acme|u1|T1', at: '2026-11-20T07:00:00Z' }, { key: 'acme|u1|T2', at })).toBe(true);
    expect(shouldRegister({ key: 'acme|u1|T1', at: '2026-11-20T07:00:00Z' }, { key: 'acme|u2|T1', at })).toBe(true);
    expect(shouldRegister({ key: 'acme|u1|T1', at: '2026-11-19T07:00:00Z' }, { key: 'acme|u1|T1', at })).toBe(true);
  });
});
