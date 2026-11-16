import type { Item, LocalStatus } from './types';

/** The phone's states as the collector reads them. */
export const STATUS_LABEL: Record<LocalStatus, string> = {
  draft: 'Draft',
  ready: 'Ready to send',
  syncing: 'Sending',
  synced: 'Sent',
  failed: 'Waiting to retry',
  action_required: 'Action required',
  quarantined: 'Unreadable',
};

export const STATUS_TONE: Record<LocalStatus, 'neutral' | 'blue' | 'green' | 'amber' | 'red'> = {
  draft: 'neutral',
  ready: 'blue',
  syncing: 'blue',
  synced: 'green',
  failed: 'amber',
  action_required: 'red',
  quarantined: 'red',
};

/** What the server's review says, once sent. */
export const SERVER_LABEL: Record<string, string> = {
  receiving: 'Photos still arriving',
  submitted: 'Received',
  under_review: 'Being reviewed',
  correction_requested: 'Correction asked',
  accepted: 'Accepted',
  rejected: 'Rejected',
};

/** What to tell the collector about an item that needs them. */
export function actionText(item: Item): string {
  const code = item.error?.code;
  switch (code) {
    case 'correction_requested':
      return item.correction?.comment ? `A reviewer asked for a correction: ${item.correction.comment}` : 'A reviewer asked for a correction.';
    case 'assignment_revoked':
      return 'You are no longer assigned to this form. Ask your coordinator; this record stays on the phone.';
    case 'assignment_closed':
    case 'version_closed':
    case 'form_archived':
      return 'This form no longer accepts records. Ask your coordinator; this record stays on the phone.';
    case 'form_not_found':
      return 'The form is no longer available to you. Ask your coordinator; this record stays on the phone.';
    case 'revision_conflict':
      return 'Someone else changed this record since. Open it again to see the latest.';
    case 'app_too_old':
      return 'Update the app to send this.';
    case 'missing_photo':
      return item.error?.message ?? 'A photo is missing on this phone.';
    default:
      return item.error?.message ?? 'The server refused this record.';
  }
}
