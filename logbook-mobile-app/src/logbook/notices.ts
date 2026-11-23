/**
 * Where a notice opens, and whether a push on this phone is for the
 * person signed in now (ADR-0027). Pure: the screens and the push runner
 * call these; the tests cover them without a device.
 */

/** The server's `route` on a notice and in a push's data. */
export type NoticeRoute =
  | { screen: 'task'; id: string }
  | { screen: 'review'; id: string }
  | { screen: 'entry'; id: string }
  | { screen: 'record'; sheet: string; occurrence: string };

/** What the server puts in a push's data. Nothing else is trusted from it. */
export type PushData = {
  notificationId?: unknown;
  userId?: unknown;
  workspace?: unknown;
  route?: unknown;
};

const text = (v: unknown): v is string => typeof v === 'string' && v.length > 0 && v.length < 100;

/** A route the app knows, or null: anything malformed opens nothing. */
export function parseRoute(value: unknown): NoticeRoute | null {
  if (!value || typeof value !== 'object') return null;
  const r = value as Record<string, unknown>;
  if ((r.screen === 'task' || r.screen === 'review' || r.screen === 'entry') && text(r.id)) {
    return { screen: r.screen, id: r.id };
  }
  if (r.screen === 'record' && text(r.sheet) && text(r.occurrence)) {
    return { screen: 'record', sheet: r.sheet, occurrence: r.occurrence };
  }
  return null;
}

/** For notices made before the server sent routes: read the web link. */
export function routeFromLink(link: string, event: string): NoticeRoute | null {
  const [path, query = ''] = link.split('?');
  const [kind, id] = path.split('/');
  if (kind === 'tasks' && id) return { screen: 'task', id };
  if (kind === 'entries' && id === 'new') {
    const params = new URLSearchParams(query);
    const sheet = params.get('sheet');
    const occurrence = params.get('occurrence');
    return sheet && occurrence ? { screen: 'record', sheet, occurrence } : null;
  }
  if (kind === 'entries' && id) return { screen: event === 'entry.submitted' ? 'review' : 'entry', id };
  return null;
}

/**
 * A push is opened only for the workspace and person it was sent to.
 * After a sign-out or a switch, an old push on the phone opens nothing:
 * the server checks access again when the record is fetched.
 */
export function belongsHere(data: PushData | null | undefined, session: { workspace: string | null; userId: string | null }): boolean {
  if (!data || !session.workspace || !session.userId) return false;
  return data.userId === session.userId && data.workspace === session.workspace;
}

/** When the phone should tell the server its push token again. */
export function shouldRegister(
  last: { key: string; at: string } | null,
  now: { key: string; at: Date },
  everyMs = 24 * 60 * 60 * 1000,
): boolean {
  if (!last || last.key !== now.key) return true;
  return now.at.getTime() - new Date(last.at).getTime() >= everyMs;
}
