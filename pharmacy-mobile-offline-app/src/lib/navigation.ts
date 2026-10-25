import type { IconName } from '@/components/ui/button';

import type { SharedProps } from './pages';

/**
 * The pharmacy's sections, as the desktop offline app has them
 * (pharmacy-electron-single-app lib/navigation.ts): the counter's own
 * screens, and the back office read from the database on this phone
 * (/p/<path>).
 */
export interface NavItem {
  title: string;
  icon: IconName;
  /** An app screen (/sell), or a page's path for /p/<path>. */
  screen?: '/home' | '/sell' | '/sales' | '/shift' | '/rx';
  page?: string;
  /** Shown to members holding it; null for everyone. */
  permission: string | null;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

export const navigation: NavGroup[] = [
  {
    title: 'Overview',
    items: [{ title: 'Home', icon: 'home-outline', screen: '/home', permission: null }],
  },
  {
    title: 'Counter',
    items: [
      { title: 'Sell', icon: 'cart-outline', screen: '/sell', permission: 'pharmacy.sales.create' },
      { title: 'Sales', icon: 'receipt-outline', screen: '/sales', permission: null },
      { title: 'My shift', icon: 'cash-outline', screen: '/shift', permission: 'pharmacy.sales.create' },
      { title: 'Prescriptions', icon: 'document-text-outline', screen: '/rx', permission: 'pharmacy.prescriptions.view' },
    ],
  },
  {
    title: 'Inventory',
    items: [
      { title: 'Inventory', icon: 'cube-outline', page: 'stock', permission: 'pharmacy.stock.view' },
      { title: 'Batches', icon: 'layers-outline', page: 'batches', permission: 'pharmacy.stock.view' },
      { title: 'Receive stock', icon: 'archive-outline', page: 'receipts/create', permission: 'pharmacy.purchasing.receive' },
      { title: 'Medicines', icon: 'medkit-outline', page: 'medicines', permission: 'pharmacy.medicines.view' },
      { title: 'Goods received', icon: 'checkbox-outline', page: 'receipts', permission: 'pharmacy.purchasing.view' },
      { title: 'Suppliers', icon: 'bus-outline', page: 'suppliers', permission: 'pharmacy.purchasing.view' },
    ],
  },
  {
    title: 'Finance',
    items: [
      { title: 'Expenses', icon: 'wallet-outline', page: 'expenses', permission: 'pharmacy.expenses.manage' },
      { title: 'Reports', icon: 'bar-chart-outline', page: 'reports', permission: 'pharmacy.reports.view' },
    ],
  },
  {
    title: 'Admin',
    items: [
      { title: 'Users', icon: 'people-outline', page: 'users', permission: 'pharmacy.users.manage' },
      { title: 'Settings', icon: 'settings-outline', page: 'settings', permission: 'pharmacy.settings.manage' },
    ],
  },
];

/** A page path as one route segment (/p/receipts~create), and back. */
export const sectionOf = (page: string) => page.replace(/\//g, '~');
export const pageOf = (section: string) => section.replace(/~/g, '/');

/** A section's title, for its header. */
export function sectionTitle(page: string): string | null {
  for (const group of navigation) {
    for (const item of group.items) if (item.page === page) return item.title;
  }
  return null;
}

/** The sections this member may use, without empty groups. Until the permissions load: the counter only. */
export function visibleNavigation(shared: SharedProps | null): NavGroup[] {
  const permissions = shared?.access?.permissions ?? null;
  return navigation
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        if (permissions === null) return !!item.screen && item.permission === null;
        return item.permission === null || permissions.includes(item.permission);
      }),
    }))
    .filter((group) => group.items.length > 0);
}
