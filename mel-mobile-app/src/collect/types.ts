import type { Answers, Definition } from './definition';

/*
 * What the field app keeps on the phone (ADR-0019 §6, ADR-0012 §8–9). Every
 * record carries its scope, "workspace|user": another person signing in on
 * the same phone sees none of it.
 */

/**
 * The phone's own states, independent of the server's review states:
 * draft → ready → syncing → synced, plus failed (retried by itself) and
 * action_required (a person must act). quarantined: unreadable, kept for
 * support, never deleted by the app.
 */
export type LocalStatus = 'draft' | 'ready' | 'syncing' | 'synced' | 'failed' | 'action_required' | 'quarantined';

export type PhotoFile = {
  /** A file in the app's private directory. */
  uri: string;
  sha256: string;
  size: number;
  mime: 'image/jpeg' | 'image/png' | 'image/webp';
  uploaded: boolean;
};

export type CorrectionAsked = {
  comment: string | null;
  notes: Record<string, string>;
  /** The revision the collector corrects from. */
  baseRevision: number;
};

export type Item = {
  /** The submission's id: made here (UUIDv7), the server's idempotency key. */
  id: string;
  scope: string;
  formId: string;
  formName: string;
  formVersionId: string;
  unitId: string | null;
  answers: Answers;
  consentGiven: boolean | null;
  startedAt: string;
  completedAt: string | null;
  status: LocalStatus;
  attempts: number;
  /** When failed: the earliest next attempt. */
  nextAttemptAt: string | null;
  error: { code: string | null; message: string } | null;
  /** Photos by question name. */
  photos: Record<string, PhotoFile>;
  /** What the server last said: receiving, submitted, under_review… */
  serverStatus: string | null;
  serverRevision: number;
  /** A reviewer asked for a correction. */
  correction: CorrectionAsked | null;
  /** A correction made here, waiting to be sent. */
  pendingCorrection: { answers: Answers; reason: string; baseRevision: number } | null;
  syncedAt: string | null;
  updatedAt: string;
};

export type StoredVersion = {
  id: string;
  formId: string;
  number: number;
  sha256: string;
  definition: Definition;
  accepting: boolean;
  acceptUntil: string | null;
};

export type Assignment = {
  id: string;
  status: 'active' | 'closing';
  canStart: boolean;
  startsOn: string | null;
  endsOn: string | null;
  closesAt: string | null;
  form: { id: string; name: string; description: string | null; purpose: string; status: string; programme: string | null; containsPersonalData: boolean };
  unit: { id: string; label: string } | null;
  version: { id: string; number: number; sha256: string; schemaVersion: number } | null;
};

export type Kit = {
  serverTime: string;
  settings: { photoMaxKb: number; keepOnDeviceDays: number };
  assignments: Assignment[];
};

/** Everything the phone keeps, behind one interface (SQLCipher on the phone, memory in tests). */
export interface CollectStore {
  version(scope: string, id: string): Promise<StoredVersion | null>;
  saveVersion(scope: string, version: StoredVersion): Promise<void>;
  kit(scope: string): Promise<Kit | null>;
  saveKit(scope: string, kit: Kit): Promise<void>;
  item(scope: string, id: string): Promise<Item | null>;
  items(scope: string): Promise<Item[]>;
  /** Saved in one transaction: a crash leaves the previous or the new state. */
  saveItem(item: Item): Promise<void>;
  /** Only drafts never finished, or synced items past their keep date. */
  removeItem(scope: string, id: string): Promise<void>;
  meta(scope: string, key: string): Promise<string | null>;
  setMeta(scope: string, key: string, value: string | null): Promise<void>;
  /** Everything of one scope (an explicit, confirmed wipe). */
  wipe(scope: string): Promise<void>;
}
