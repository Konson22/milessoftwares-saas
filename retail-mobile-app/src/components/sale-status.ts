import type { SaleStatus } from '@/lib/types';

export const STATUS_BADGE: Record<SaleStatus, { label: string; tone: 'green' | 'amber' | 'red' | 'blue' }> = {
  completed: { label: 'Paid', tone: 'green' },
  voided: { label: 'Voided', tone: 'red' },
};
