import { ApiError, request } from '@/lib/api/client';

import type { Answers } from './definition';
import type { Changed, SubmissionState, Transport } from './sync';
import { TransportError } from './sync';
import type { Kit, PhotoFile, StoredVersion } from './types';

/** The newest definition schema this app renders (the server refuses newer forms at download). */
export const SUPPORTED_SCHEMA = 1;

async function call<T>(work: () => Promise<T>): Promise<T> {
  try {
    return await work();
  } catch (error) {
    if (error instanceof ApiError) {
      if (error.timeout) throw new TransportError('timeout', error.message);
      if (error.offline) throw new TransportError('offline', error.message);
      throw new TransportError('http', error.message, error.status, error.code, error.body);
    }
    throw new TransportError('offline', error instanceof Error ? error.message : 'Network request failed');
  }
}

/** M&E's field API (mel-app routes/api.php). */
export const api: Transport = {
  kit: () => call(() => request<Kit>('GET', 'v1/collect/assignments')),
  version: (id) =>
    call(async () => {
      const v = await request<{ id: string; formId: string; number: number; sha256: string; definition: StoredVersion['definition']; accepting: boolean; acceptUntil: string | null }>(
        'GET',
        `v1/collect/form-versions/${id}?supports=${SUPPORTED_SCHEMA}`,
      );
      return { id: v.id, formId: v.formId, number: v.number, sha256: v.sha256, definition: v.definition, accepting: v.accepting, acceptUntil: v.acceptUntil };
    }),
  submit: (payload) => call(() => request<SubmissionState>('POST', 'v1/collect/submissions', payload)),
  upload: (id: string, question: string, file: PhotoFile) =>
    call(() => {
      const form = new FormData();
      // React Native sends the file from its uri.
      form.append('file', { uri: file.uri, name: `${question}.jpg`, type: file.mime } as unknown as Blob);
      // PUT with multipart: sent as POST with Laravel's method override.
      form.append('_method', 'PUT');
      return request<SubmissionState>('POST', `v1/collect/submissions/${id}/attachments/${question}`, undefined, { form, timeoutMs: 120_000 });
    }),
  status: (id) => call(() => request<SubmissionState>('GET', `v1/collect/submissions/${id}`)),
  correct: (id: string, body: { base_revision: number; answers: Answers; reason: string }) =>
    call(() => request<SubmissionState>('POST', `v1/collect/submissions/${id}/corrections`, body)),
  changed: (since, after) =>
    call(() => {
      const query = new URLSearchParams({ limit: '100' });
      if (since) query.set('since', since);
      if (after) query.set('after', after);
      return request<Changed>('GET', `v1/collect/submissions?${query.toString()}`);
    }),
};
