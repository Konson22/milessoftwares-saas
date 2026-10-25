import type { SaleStatus } from '@/lib/types';

export const STATUS_BADGE: Record<SaleStatus, { label: string; tone: 'green' | 'amber' | 'red' | 'blue' }> = {
  completed: { label: 'Paid', tone: 'green' },
  voided: { label: 'Voided', tone: 'red' },
};

export const RX_BADGE: Record<string, 'green' | 'amber' | 'red' | 'blue' | 'neutral'> = {
  recorded: 'amber',
  verified: 'blue',
  partly_dispensed: 'blue',
  dispensed: 'green',
  rejected: 'red',
  cancelled: 'neutral',
};
