/*
 * What the phone keeps (ADR-0025 §8). Every record carries its scope,
 * "workspace|user": another person signing in on the same phone sees none
 * of it.
 */

export type FieldType =
  | 'text'
  | 'textarea'
  | 'integer'
  | 'decimal'
  | 'date'
  | 'time'
  | 'datetime'
  | 'select_one'
  | 'select_multiple'
  | 'yes_no'
  | 'file'
  | 'user'
  | 'resource';

/** A field of a published template version (logbook-app App\Logbook\Fields). */
export type FieldDef = {
  name: string;
  label: string;
  type: FieldType;
  required: boolean;
  help?: string;
  placeholder?: string;
  max_length?: number;
  min?: string | number;
  max?: string | number;
  /** The normal range: a reading outside it is saved and flagged, never refused. */
  expected_min?: string | number;
  expected_max?: string | number;
  unit?: string;
  choices?: { value: string; label: string }[];
  default?: unknown;
  /** Shown only when an earlier yes/no or choice field has one of these answers (ADR-0026 §2.9). */
  show_if?: { field: string; in: string[] };
  /** Resource fields: only resources of this type. */
  resource_type?: string;
};

/** A resource from the kit (ADR-0026 §2.10). */
export type KitResource = {
  id: string;
  code: string;
  name: string;
  type: string;
  location: string | null;
  /** ADR-0030: its fuel rates, oldest first, for the offline estimate. */
  fuel?: FuelRate[];
  /** ADR-0030: the last recorded end reading per meter. */
  meters?: Partial<Record<'odometer' | 'hour_meter', number>>;
};

export type FuelRate = { id: string; value: number; unit: string; method: 'distance' | 'hours'; fuel_type: string; effective_from: string };

/** ADR-0030 §4.5: an operational shift; logbookId null means every logbook. */
export type KitShift = { id: string; name: string; starts: string; ends: string; logbookId: string | null; members: string[] };

/** ADR-0030 §4.7: what an entry's activity measures, for the fuel estimate. */
export type ActivitySettings = {
  resource_field: string;
  measure: 'odometer' | 'hour_meter' | 'duration';
  start_field: string | null;
  end_field: string | null;
  actual_field: string | null;
  refill_field: string | null;
};

export type RecordingSettings = { mode: 'on_demand' | 'scheduled' | 'event'; shifts: 'off' | 'optional' | 'required'; activity?: ActivitySettings | null };

export type Values = Record<string, unknown>;

/** A published template version: immutable, so downloaded once by id. */
export type StoredVersion = {
  id: string;
  sheetId: string;
  version: number;
  fields: FieldDef[];
};

export type KitSheet = {
  id: string;
  name: string;
  code: string;
  description: string | null;
  instructions: string | null;
  reviewRequired: boolean;
  logbook: { id: string; name: string; icon: string | null };
  version: { id: string | null; version: number | null };
  /** GPS for the published version (ADR-0028); null or absent: no location. */
  location?: LocationSettings | null;
  /** How it is recorded (ADR-0030); absent from servers before it. */
  recording?: RecordingSettings;
};

export type LocationSettings = { mode: 'entry' | 'route'; required: boolean; resource_required: boolean; accuracy_m: number | null };

/** A location kept on an entry, read by the phone when the person asked for it. */
export type EntryLocation = { lat: number; lng: number; accuracy_m: number | null; captured_at: string };

export type Kit = {
  serverTime: string;
  can: { write: boolean; review: boolean; manageTasks?: boolean; viewRoutes?: boolean };
  limits: { fileMaxKb: number; fileTypes: string[]; filesPerField: number };
  sheets: KitSheet[];
  members: { id: string; name: string }[];
  /** Absent from servers before ADR-0026. */
  resources?: KitResource[];
  /** ADR-0030; absent from servers before it. */
  shifts?: KitShift[];
};

/** A file kept on the phone until the server has it. */
export type LocalFile = {
  /** A file in the app's private directory. */
  uri: string;
  field: string;
  name: string;
  mime: string;
  size: number;
  sha256: string;
  /** The server's attachment id, once uploaded. */
  serverId: string | null;
};

/** A file the server already holds (an entry corrected on the phone). */
export type ServerFile = { id: string; field: string; name: string; size: number; mime: string | null };

export type Review = { revision: number; decision: 'reviewed' | 'returned'; comment: string | null; reviewer: string; decidedAt: string | null };

/**
 * The phone's own states, kept apart from the server's:
 * draft → ready → syncing → synced, plus failed (retried by itself) and
 * action_required (a person must act: a return for correction, or a
 * refusal). Reaching the server is never shown as reviewed.
 */
export type LocalStatus = 'draft' | 'ready' | 'syncing' | 'synced' | 'failed' | 'action_required';

export type ServerStatus = 'draft' | 'submitted' | 'returned' | 'reviewed' | 'finalized';

export type Item = {
  /** Made here (UUIDv7): the server's idempotency key for the entry. */
  id: string;
  scope: string;
  sheet: { id: string; name: string; code: string };
  logbook: string;
  versionId: string;
  occurredAt: string;
  values: Values;
  /** New files, by field, waiting on the phone. */
  files: LocalFile[];
  /** Files already on the server, on an entry being corrected. */
  serverFiles: ServerFile[];
  /** Server files taken off in a correction, to remove when it is sent. */
  removedFiles: string[];
  status: LocalStatus;
  attempts: number;
  nextAttemptAt: string | null;
  error: { code: string | null; message: string; fields?: Record<string, string> } | null;
  /** The server's entry id, once it exists there. */
  serverId: string | null;
  /** The server's revision: 0 until first submitted, then one per submission. */
  revision: number;
  serverStatus: ServerStatus | null;
  reference: string | null;
  reviewRequired: boolean | null;
  lastReview: Review | null;
  submittedAt: string | null;
  syncedAt: string | null;
  createdAt: string;
  updatedAt: string;
  /**
   * The expected entry this answers, when opened from a reminder
   * (ADR-0027). Absent on items saved before it existed.
   */
  occurrenceId?: string | null;
  /** GPS (ADR-0028): the location taken for it, and the route it was written on. */
  location?: EntryLocation | null;
  trackId?: string | null;
  /** ADR-0030 §4.3: for the reading named (occurrenceId), or an extra one. Absent on older items. */
  scheduleLink?: 'named' | 'extra' | null;
  /** ADR-0030 §4.5: the shift it was recorded in. */
  shiftId?: string | null;
  /** ADR-0030 §4.4: an activity's start and end (ISO); no end yet means still going on. */
  activityStartedAt?: string | null;
  activityEndedAt?: string | null;
  /** The activity this entry was recorded during. */
  activityEntryId?: string | null;
  /** The phone's own estimate, sent as evidence only. */
  calculation?: { estimated_l: number; rate_id: string | null; rate: number | null; quantity: number | null } | null;
};

/** Everything the phone keeps, behind one interface (SQLCipher on the phone, memory in tests). */
export interface LogbookStore {
  version(scope: string, id: string): Promise<StoredVersion | null>;
  saveVersion(scope: string, version: StoredVersion): Promise<void>;
  kit(scope: string): Promise<Kit | null>;
  saveKit(scope: string, kit: Kit): Promise<void>;
  item(scope: string, id: string): Promise<Item | null>;
  items(scope: string): Promise<Item[]>;
  /** One statement: a crash leaves the previous or the new state. */
  saveItem(item: Item): Promise<void>;
  removeItem(scope: string, id: string): Promise<void>;
  meta(scope: string, key: string): Promise<string | null>;
  setMeta(scope: string, key: string, value: string | null): Promise<void>;
}
