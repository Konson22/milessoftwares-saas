import { ApiError, request } from '@/lib/api/client';

import type { ServerEntry, Transport } from './sync';
import { TransportError } from './sync';
import type { ServerTrack, TrackTransport } from './tracks';
import type { Kit, LocalFile, StoredVersion } from './types';
import type { Notice, Occurrence, Task, WorkTransport } from './work';

async function call<T>(work: () => Promise<T>): Promise<T> {
  try {
    return await work();
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.timeout) throw new TransportError('timeout', error.message);
      if (error.offline) throw new TransportError('offline', error.message);
      throw new TransportError('http', error.message, error.status, error.code, error.errors);
    }
    throw new TransportError('offline', error instanceof Error ? error.message : 'Network request failed');
  }
}

/** Logbook's phone API (logbook-app routes/api.php). */
export const api: Transport = {
  kit: () => call(() => request<Kit>('GET', 'v1/kit')),
  version: (id) =>
    call(async () => {
      const v = await request<{ id: string; sheetId: string; version: number; fields: StoredVersion['fields'] }>('GET', `v1/sheet-versions/${id}`);
      return { id: v.id, sheetId: v.sheetId, version: v.version, fields: v.fields };
    }),
  create: (body) => call(() => request<ServerEntry>('POST', 'v1/entries', body)),
  update: (id, body) => call(() => request<ServerEntry>('PUT', `v1/entries/${id}`, body)),
  upload: (id: string, file: LocalFile) =>
    call(() => {
      const form = new FormData();
      form.append('field', file.field);
      // React Native sends the file from its uri.
      form.append('file', { uri: file.uri, name: file.name, type: file.mime } as unknown as Blob);
      return request<{ id: string }>('POST', `v1/entries/${id}/attachments`, undefined, { form, timeoutMs: 120_000 });
    }),
  removeFile: (id) => call(() => request<void>('DELETE', `v1/attachments/${id}`)),
  submit: (id, revision) => call(() => request<ServerEntry>('POST', `v1/entries/${id}/submit`, { revision })),
  changed: (since) => call(() => request<{ serverTime: string; complete: boolean; entries: ServerEntry[] }>('GET', `v1/entries${since ? `?since=${encodeURIComponent(since)}` : ''}`)),
};

/** Routes (ADR-0028): state and point batches, both safe to repeat. */
export const trackApi: TrackTransport = {
  upsert: (body) => call(() => request<ServerTrack>('POST', 'v1/tracks', body)),
  points: (id, body) => call(() => request<{ acknowledged: number; refused: number[]; track: ServerTrack }>('POST', `v1/tracks/${id}/points`, body, { timeoutMs: 60_000 })),
};

/** Reviews, online only: a decision is made on the entry as the server has it now. */
export type ReviewRow = {
  id: string;
  reference: string | null;
  status: string;
  revision: number;
  occurredAt: string | null;
  submittedAt: string | null;
  logbook: string;
  sheet: { id: string; name: string; code: string };
  creator: string;
  summary: string;
};

export type EntryDetail = ServerEntry & {
  creator: string;
  canReview: boolean;
  answers: { name: string; label: string; type: string; text: string | null; unit?: string | null; outOfRange?: { direction: 'low' | 'high'; range: string } | null; files: { id: string; name: string; size: number; mime: string | null; removed: boolean }[] }[];
  reviews: { revision: number; decision: 'reviewed' | 'returned'; comment: string | null; reviewer: string; decidedAt: string | null }[];
};

export const reviews = {
  list: () => request<{ entries: ReviewRow[] }>('GET', 'v1/reviews'),
  entry: (id: string) => request<EntryDetail>('GET', `v1/entries/${id}`),
  decide: (id: string, body: { decision: 'reviewed' | 'returned'; comment: string | null; revision: number }) =>
    request<{ id: string; status: string; revision: number }>('POST', `v1/entries/${id}/review`, body),
};

/** Tasks (ADR-0026): listed and worked through the outbox in logbook/work.ts. */
export const work: WorkTransport = {
  tasks: () => call(() => request<{ serverTime: string; tasks: Task[] }>('GET', 'v1/tasks')),
  start: (id) => call(() => request<Task>('POST', `v1/tasks/${id}/start`)),
  complete: (id, body) => call(() => request<Task>('POST', `v1/tasks/${id}/complete`, body)),
};

/** Online only: due inspections, notifications, evidence. */
export const operations = {
  due: () => request<{ serverTime: string; occurrences: Occurrence[]; missed?: Occurrence[] }>('GET', 'v1/due'),
  notifications: () => request<{ unread: number; notifications: Notice[] }>('GET', 'v1/notifications'),
  read: (body: { ids?: string[]; all?: boolean }) => request<{ marked: number }>('POST', 'v1/notifications/read', body),
  evidence: (taskId: string, file: { uri: string; name: string; mime: string }) => {
    const form = new FormData();
    form.append('file', { uri: file.uri, name: file.name, type: file.mime } as unknown as Blob);
    return request<{ id: string }>('POST', `v1/tasks/${taskId}/evidence`, undefined, { form, timeoutMs: 120_000 });
  },
};
