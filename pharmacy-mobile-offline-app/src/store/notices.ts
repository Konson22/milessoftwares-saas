import type { Href } from 'expo-router';
import { create } from 'zustand';

import type { IconName } from '@/components/ui/button';
import { sectionOf } from '@/lib/navigation';
import { getPage } from '@/lib/pages';

/** The dashboard's figures (src/local/db/pages.ts home), each null where this member may not see it. */
interface Dashboard {
  stock: { low: number; expiring: number; expired: number } | null;
  awaitingVerification: number | null;
  toDispense: number | null;
  billsDue: number | null;
  expiringDays: number;
}

export interface Notice {
  key: string;
  icon: IconName;
  tone: 'red' | 'amber' | 'brand';
  title: string;
  message: string;
  href: Href;
}

const section = (page: string): Href => ({ pathname: '/p/[section]', params: { section: sectionOf(page) } });
const plural = (n: number, one: string, many: string) => `${n} ${n === 1 ? one : many}`;

/** What needs someone's attention now, from the pharmacy's own figures. */
function noticesOf(d: Dashboard): Notice[] {
  const list: Notice[] = [];
  if (d.stock?.expired) {
    list.push({
      key: 'expired',
      icon: 'warning',
      tone: 'red',
      title: `${plural(d.stock.expired, 'medicine has', 'medicines have')} expired stock`,
      message: 'Still on the shelf, and never sold. Dispose of it under Inventory.',
      href: section('stock?filter=expired'),
    });
  }
  if (d.stock?.expiring) {
    list.push({
      key: 'expiring',
      icon: 'time',
      tone: 'amber',
      title: `${plural(d.stock.expiring, 'medicine expires', 'medicines expire')} soon`,
      message: `Within ${d.expiringDays} days. Sell these first, or return them.`,
      href: section('stock?filter=expiring'),
    });
  }
  if (d.stock?.low) {
    list.push({
      key: 'low',
      icon: 'trending-down',
      tone: 'amber',
      title: `${plural(d.stock.low, 'medicine is', 'medicines are')} low on stock`,
      message: 'At or below the reorder level. Time to order more.',
      href: section('stock?filter=low'),
    });
  }
  if (d.awaitingVerification) {
    list.push({
      key: 'verify',
      icon: 'document-text',
      tone: 'brand',
      title: `${plural(d.awaitingVerification, 'prescription waits', 'prescriptions wait')} to be verified`,
      message: 'Recorded at the counter, waiting for a pharmacist.',
      href: '/rx',
    });
  }
  if (d.toDispense) {
    list.push({
      key: 'dispense',
      icon: 'medkit',
      tone: 'brand',
      title: `${plural(d.toDispense, 'prescription is', 'prescriptions are')} ready to dispense`,
      message: 'Verified, and not all given yet.',
      href: '/rx',
    });
  }
  if (d.billsDue) {
    list.push({
      key: 'bills',
      icon: 'calendar',
      tone: 'amber',
      title: `${plural(d.billsDue, 'bill is', 'bills are')} due`,
      message: 'Recurring expenses due this week, or overdue.',
      href: section('expenses'),
    });
  }
  return list;
}

/** The app bar's bell and the notifications screen. */
export const useNotices = create<{ notices: Notice[]; loaded: boolean; refresh(): Promise<void>; clear(): void }>((set) => ({
  notices: [],
  loaded: false,
  async refresh() {
    try {
      const page = await getPage<Dashboard>('');
      set({ notices: noticesOf(page.props), loaded: true });
    } catch {
      set({ loaded: true });
    }
  },
  clear: () => set({ notices: [], loaded: false }),
}));
