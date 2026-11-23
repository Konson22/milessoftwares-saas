import { flatten, isAnswerable, type Definition, type Item as Question } from './definition';
import type { Evaluation } from './rules';
import type { Assignment, Item, Kit } from './types';

/*
 * What the four tabs show, worked out from the phone's own records and the
 * downloaded kit. No React Native here, so it runs in Jest.
 *
 * Two separate questions about every record:
 * - transport: is it on the server yet? (the phone's LocalStatus)
 * - verification: what did the reviewers decide? (the server's status)
 * Reaching the server is never shown as approval.
 */

export type Verification = 'none' | 'pending' | 'approved' | 'correction' | 'rejected';

/** The review state of a record, from what the server last said. */
export function verificationOf(item: Item): Verification {
  if (item.correction !== null || item.serverStatus === 'correction_requested') return 'correction';
  switch (item.serverStatus) {
    case 'submitted':
    case 'under_review':
      return 'pending';
    case 'accepted':
      return 'approved';
    case 'rejected':
      return 'rejected';
    default:
      return 'none';
  }
}

export const VERIFICATION_LABEL: Record<Verification, string> = {
  none: 'Not reviewed',
  pending: 'Verification pending',
  approved: 'Approved',
  correction: 'Correction required',
  rejected: 'Rejected',
};

export const VERIFICATION_TONE: Record<Verification, 'neutral' | 'blue' | 'green' | 'amber' | 'red'> = {
  none: 'neutral',
  pending: 'blue',
  approved: 'green',
  correction: 'amber',
  rejected: 'red',
};

/** Records that still need the collector or the network. */
export function buckets(items: Item[]) {
  return {
    drafts: items.filter((i) => i.status === 'draft'),
    waiting: items.filter((i) => i.status === 'ready' || i.status === 'syncing' || i.status === 'failed'),
    sending: items.filter((i) => i.status === 'syncing'),
    failed: items.filter((i) => i.status === 'failed'),
    corrections: items.filter((i) => i.status === 'action_required' && i.correction !== null),
    refused: items.filter((i) => i.status === 'action_required' && i.correction === null),
    uploaded: items.filter((i) => i.status === 'synced'),
  };
}

/** Counts for the Sync tab: the network side and the review side kept apart. */
export function syncSummary(items: Item[], kit: Kit | null, downloaded: Set<string>) {
  const b = buckets(items);
  const uploaded = b.uploaded;
  return {
    formsOnPhone: kit ? kit.assignments.filter((a) => a.version && downloaded.has(a.version.id)).length : 0,
    formsAssigned: kit?.assignments.length ?? 0,
    pending: items.filter((i) => i.status === 'ready').length,
    sending: b.sending.length,
    failed: b.failed.length,
    refused: b.refused.length,
    uploaded: uploaded.length,
    review: {
      pending: uploaded.filter((i) => verificationOf(i) === 'pending').length,
      approved: uploaded.filter((i) => verificationOf(i) === 'approved').length,
      rejected: uploaded.filter((i) => verificationOf(i) === 'rejected').length,
      correction: b.corrections.length,
    },
  };
}

/** What a form is for, as a field task. */
export const PURPOSE_LABEL: Record<string, string> = {
  survey: 'Survey',
  assessment: 'Assessment',
  monitoring_visit: 'Monitoring visit',
  inspection: 'Site inspection',
  research: 'Research',
  other: 'Field activity',
};

export const purposeLabel = (purpose: string) => PURPOSE_LABEL[purpose] ?? PURPOSE_LABEL.other;

export type TaskState = 'open' | 'not_started' | 'closing' | 'ended' | 'not_downloaded';

export type TaskRow = {
  assignment: Assignment;
  state: TaskState;
  /** Days left until endsOn (negative: past), null without an end. */
  daysLeft: number | null;
  drafts: number;
  /** Records sent from this phone, kept here (older ones are removed after the keep period). */
  sent: number;
  approved: number;
  corrections: number;
};

function dayDiff(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000);
}

/** One row per assignment, with the phone's own work on its form. */
export function taskRows(kit: Kit | null, items: Item[], downloaded: Set<string>, today: string): TaskRow[] {
  return (kit?.assignments ?? []).map((assignment) => {
    const mine = items.filter((i) => i.formId === assignment.form.id);
    const state: TaskState =
      assignment.status === 'closing'
        ? 'closing'
        : assignment.startsOn && assignment.startsOn > today
          ? 'not_started'
          : assignment.endsOn && assignment.endsOn < today
            ? 'ended'
            : !assignment.version || !downloaded.has(assignment.version.id)
              ? 'not_downloaded'
              : assignment.canStart
                ? 'open'
                : 'ended';
    // The server's counts when it sends them (they include records this
    // phone has removed after its keep days); the phone's own otherwise.
    const server = assignment.progress;
    const sentHere = mine.filter((i) => i.status === 'synced' || (i.status === 'action_required' && i.correction !== null)).length;
    const approvedHere = mine.filter((i) => verificationOf(i) === 'approved').length;
    return {
      assignment,
      state,
      daysLeft: assignment.endsOn ? dayDiff(today, assignment.endsOn) : null,
      drafts: mine.filter((i) => i.status === 'draft').length,
      sent: server ? Math.max(server.received, sentHere) : sentHere,
      approved: server ? Math.max(server.accepted, approvedHere) : approvedHere,
      corrections: mine.filter((i) => i.status === 'action_required' && i.correction !== null).length,
    };
  });
}

export const TASK_STATE_LABEL: Record<TaskState, string> = {
  open: 'Open',
  not_started: 'Not started yet',
  closing: 'Closing',
  ended: 'Closed',
  not_downloaded: 'Not downloaded',
};

export type TaskFilter = 'all' | 'open' | 'due_soon' | 'attention';

export function filterTasks(rows: TaskRow[], filter: TaskFilter, query: string): TaskRow[] {
  const q = query.trim().toLowerCase();
  return rows
    .filter((row) => {
      if (filter === 'open') return row.state === 'open' || row.state === 'closing';
      if (filter === 'due_soon') return row.daysLeft !== null && row.daysLeft >= 0 && row.daysLeft <= 7;
      if (filter === 'attention') return row.corrections > 0 || row.drafts > 0 || row.state === 'closing' || row.state === 'not_downloaded';
      return true;
    })
    .filter((row) => {
      if (!q) return true;
      const a = row.assignment;
      return [a.form.name, a.form.description, a.form.programme, a.unit?.label, purposeLabel(a.form.purpose)].some((s) => s?.toLowerCase().includes(q));
    })
    .sort((a, b) => {
      // Corrections first, then the nearest due date, then by name.
      if ((b.corrections > 0 ? 1 : 0) !== (a.corrections > 0 ? 1 : 0)) return (b.corrections > 0 ? 1 : 0) - (a.corrections > 0 ? 1 : 0);
      const da = a.daysLeft ?? Number.MAX_SAFE_INTEGER;
      const db = b.daysLeft ?? Number.MAX_SAFE_INTEGER;
      if (da !== db) return da - db;
      return a.assignment.form.name.localeCompare(b.assignment.form.name);
    });
}

/**
 * A newer version of the form than the one this record was started on:
 * the record stays on its own version (never migrated silently).
 */
export function newerVersion(item: Item, kit: Kit | null): Assignment | null {
  const assignment = kit?.assignments.find((a) => a.form.id === item.formId);
  if (!assignment?.version || assignment.version.id === item.formVersionId) return null;
  return assignment;
}

/*
 * Pages of a long form. Each top-level section is a page; questions
 * outside any section are grouped in order between them. A form without
 * sections is one page.
 */
export type Page = { key: string; title: string | null; names: string[] };

export function pagesOf(definition: Definition): Page[] {
  const pages: Page[] = [];
  let loose: Page | null = null;
  for (const item of definition.items ?? []) {
    if (item.type === 'group') {
      loose = null;
      pages.push({ key: item.name, title: item.label, names: [item.name, ...flatten(item.items ?? []).map((q) => q.name)] });
    } else {
      if (!loose) {
        loose = { key: `loose-${pages.length}`, title: null, names: [] };
        pages.push(loose);
      }
      loose.names.push(item.name);
    }
  }
  return pages.length ? pages : [{ key: 'all', title: null, names: [] }];
}

/** Pages with something visible on them now (a hidden section disappears). */
export function visiblePages(pages: Page[], evaluation: Evaluation): Page[] {
  const visible = new Set(evaluation.visible);
  return pages.filter((p) => p.names.some((n) => visible.has(n)));
}

/** Answered visible questions out of all visible questions, and how many are wrong or missing. */
export function progressOf(definition: Definition, answers: Record<string, unknown>, evaluation: Evaluation, names?: string[]) {
  const visible = new Set(evaluation.visible);
  const within = names ? new Set(names) : null;
  const questions = flatten(definition.items ?? []).filter((q: Question) => isAnswerable(q.type) && visible.has(q.name) && (!within || within.has(q.name)));
  const answered = questions.filter((q) => {
    const a = answers[q.name];
    return a !== undefined && a !== null && a !== '' && !(Array.isArray(a) && a.length === 0);
  }).length;
  const problems = questions.filter((q) => evaluation.errors[q.name] !== undefined).length;
  return { answered, total: questions.length, problems };
}
