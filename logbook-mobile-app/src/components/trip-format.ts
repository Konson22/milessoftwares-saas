import type { LocalTrack } from '@/logbook/tracks';

/** How trips read on the screens (ADR-0028). */
export const distance = (m: number) => (m >= 1000 ? `${(m / 1000).toFixed(1)} km` : `${m} m`);

export function duration(seconds: number) {
  const h = Math.floor(seconds / 3600);
  const m = Math.floor((seconds % 3600) / 60);
  return h > 0 ? `${h} h ${m} min` : `${m} min`;
}

export const syncLabel: Record<LocalTrack['sync'], string> = {
  pending: 'Waiting to send',
  syncing: 'Sending…',
  synced: 'Sent',
  failed: 'Not sent yet: retried by itself',
  action_required: 'Refused by the server',
};
