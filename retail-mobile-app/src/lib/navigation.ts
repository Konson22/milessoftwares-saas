import type { IconName } from '@/components/ui/button';

import type { SharedProps } from './pages';

/**
 * The shop's sections: the counter's own screens, and the back office
 * read from the database on this phone (/p/<path>).
 */
export interface NavItem {
  title: string;
  icon: IconName;
  /** An app screen (/sell), or a page's path for /p/<path>. */
  screen?: '/home' | '/sell' | '/sales' | '/shift';
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
      { title: 'Sell', icon: 'cart-outline', screen: '/sell', permission: 'retail.sales.create' },
      { title: 'Sales', icon: 'receipt-outline', screen: '/sales', permission: null },
      { title: 'My shift', icon: 'cash-outline', screen: '/shift', permission: 'retail.sales.create' },
    ],
  },
  {
    title: 'Customers',
    items: [
      { title: 'Customers', icon: 'people-outline', page: 'customers', permission: 'retail.customers.view' },
      { title: 'Quotations', icon: 'document-text-outline', page: 'quotations', permission: 'retail.quotations.manage' },
      { title: 'Invoices', icon: 'reader-outline', page: 'invoices', permission: 'retail.customers.view' },
    ],
  },
  {
    title: 'Inventory',
    items: [
      { title: 'Inventory', icon: 'cube-outline', page: 'stock', permission: 'retail.stock.view' },
      { title: 'Batches', icon: 'layers-outline', page: 'batches', permission: 'retail.stock.view' },
      { title: 'Receive stock', icon: 'archive-outline', page: 'receipts/create', permission: 'retail.purchasing.receive' },
      { title: 'Products', icon: 'pricetags-outline', page: 'products', permission: 'retail.products.view' },
      { title: 'Goods received', icon: 'checkbox-outline', page: 'receipts', permission: 'retail.purchasing.view' },
      { title: 'Suppliers', icon: 'bus-outline', page: 'suppliers', permission: 'retail.purchasing.view' },
    ],
  },
  {
    title: 'Finance',
    items: [
      { title: 'Expenses', icon: 'wallet-outline', page: 'expenses', permission: 'retail.expenses.manage' },
      { title: 'Reports', icon: 'bar-chart-outline', page: 'reports', permission: 'retail.reports.view' },
    ],
  },
  {
    title: 'Admin',
    items: [
      { title: 'Users', icon: 'people-outline', page: 'users', permission: 'retail.users.manage' },
      { title: 'Settings', icon: 'settings-outline', page: 'settings', permission: 'retail.settings.manage' },
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
