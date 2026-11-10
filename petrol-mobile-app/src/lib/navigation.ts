import type { IconName } from '@/components/ui/button';

import type { SharedProps } from './pages';

/**
 * The station's sections, in the web's menu order (petrol-app
 * resources/js/lib/navigation.ts). The everyday ones are tabs; the rest open
 * from the drawer. Each shows the web page of its path.
 */
export interface NavItem {
  title: string;
  /** The web path ("" is home). */
  path: string;
  icon: IconName;
  /** Shown to members holding one of these (or owners); empty for everyone. */
  anyOf: string[];
  /** org: the permission anywhere; branch: in the branch worked in. */
  scope: 'org' | 'branch';
  /** A tab of its own (its route), rather than a page from the drawer. */
  tab?: string;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

export const navigation: NavGroup[] = [
  {
    title: 'Dashboard',
    items: [
      { title: 'Home', path: '', icon: 'home-outline', anyOf: [], scope: 'org', tab: '/home' },
      { title: 'Operations dashboard', path: 'overview', icon: 'business-outline', anyOf: ['petrol.reports.view', 'petrol.cash.report'], scope: 'org' },
    ],
  },
  {
    title: 'Management',
    items: [
      { title: 'Stations', path: 'stations', icon: 'location-outline', anyOf: ['petrol.reports.view', 'petrol.stations.manage'], scope: 'org' },
      { title: 'Staff', path: 'staff', icon: 'people-outline', anyOf: ['petrol.attendants.view', 'petrol.attendants.manage'], scope: 'org' },
      {
        title: 'Inventory',
        path: 'inventory',
        icon: 'cube-outline',
        anyOf: ['petrol.tanks.view', 'petrol.purchases.view', 'petrol.store.manage', 'petrol.store.move', 'petrol.reports.view'],
        scope: 'org',
      },
    ],
  },
  {
    title: 'Operations',
    items: [
      { title: 'Sell fuel', path: 'sales/create', icon: 'add-circle-outline', anyOf: ['petrol.sales.create'], scope: 'branch', tab: '/sell' },
      { title: 'Sales', path: 'sales', icon: 'receipt-outline', anyOf: ['petrol.sales.view'], scope: 'branch', tab: '/sales' },
      { title: 'Shifts', path: 'shifts', icon: 'time-outline', anyOf: ['petrol.shifts.view'], scope: 'branch', tab: '/shifts' },
      { title: 'Assignments', path: 'assignments', icon: 'people-outline', anyOf: ['petrol.shifts.view'], scope: 'branch' },
      { title: 'Readings', path: 'readings', icon: 'speedometer-outline', anyOf: ['petrol.shifts.view'], scope: 'branch' },
      { title: 'Reconciliation', path: 'reconciliation', icon: 'shield-checkmark-outline', anyOf: ['petrol.shifts.view'], scope: 'branch' },
      { title: 'Service bay', path: 'service', icon: 'construct-outline', anyOf: ['petrol.service.work', 'petrol.service.manage'], scope: 'branch' },
    ],
  },
  {
    title: 'Fuel',
    items: [
      { title: 'Products', path: 'products', icon: 'color-fill-outline', anyOf: ['petrol.tanks.view', 'petrol.products.manage', 'petrol.prices.manage'], scope: 'branch' },
      { title: 'Tanks', path: 'tanks', icon: 'water-outline', anyOf: ['petrol.tanks.view'], scope: 'branch', tab: '/tanks' },
      { title: 'Deliveries', path: 'deliveries', icon: 'bus-outline', anyOf: ['petrol.purchases.view'], scope: 'branch' },
      { title: 'Calibration', path: 'calibration', icon: 'options-outline', anyOf: ['petrol.pumps.view'], scope: 'branch' },
      { title: 'Handovers', path: 'handovers', icon: 'swap-horizontal-outline', anyOf: ['petrol.assignments.view'], scope: 'branch' },
      { title: 'Exceptions', path: 'exceptions', icon: 'warning-outline', anyOf: ['petrol.exceptions.review', 'petrol.shifts.view'], scope: 'branch' },
      { title: 'Pumps', path: 'pumps', icon: 'speedometer-outline', anyOf: ['petrol.pumps.view'], scope: 'branch' },
      { title: 'Transfers', path: 'transfers', icon: 'git-compare-outline', anyOf: ['petrol.transfers.manage', 'petrol.transfers.approve'], scope: 'branch' },
    ],
  },
  {
    title: 'Business',
    items: [
      { title: 'Attendants', path: 'attendants', icon: 'person-outline', anyOf: ['petrol.attendants.view'], scope: 'branch' },
      { title: 'Customers', path: 'customers', icon: 'people-circle-outline', anyOf: ['petrol.customers.view'], scope: 'branch' },
      { title: 'Suppliers', path: 'suppliers', icon: 'storefront-outline', anyOf: ['petrol.purchases.view'], scope: 'branch' },
      { title: 'Purchases', path: 'purchases', icon: 'cart-outline', anyOf: ['petrol.purchases.view'], scope: 'branch' },
      { title: 'Store', path: 'stores', icon: 'cube-outline', anyOf: ['petrol.store.manage', 'petrol.store.move'], scope: 'branch' },
    ],
  },
  {
    title: 'Finance',
    items: [
      { title: 'Expenses', path: 'expenses', icon: 'card-outline', anyOf: ['petrol.expenses.manage', 'petrol.finance.view'], scope: 'branch' },
      {
        title: 'Cash',
        path: 'cash',
        icon: 'wallet-outline',
        anyOf: ['petrol.cash.manage', 'petrol.finance.view', 'petrol.cash.view', 'petrol.cash.submit'],
        scope: 'branch',
      },
      { title: 'Finance', path: 'finance', icon: 'stats-chart-outline', anyOf: ['petrol.finance.view'], scope: 'branch' },
    ],
  },
  {
    title: 'Reports',
    items: [
      { title: 'Reports', path: 'reports', icon: 'bar-chart-outline', anyOf: ['petrol.reports.view'], scope: 'branch' },
      { title: 'Audit', path: 'audit', icon: 'document-text-outline', anyOf: ['petrol.audit.view'], scope: 'branch' },
    ],
  },
  {
    title: 'Settings',
    items: [
      {
        title: 'Station',
        path: 'settings',
        icon: 'settings-outline',
        anyOf: ['petrol.stations.manage', 'petrol.prices.manage', 'petrol.products.manage'],
        scope: 'branch',
      },
    ],
  },
];

/** Whether this member may open a section here. */
export function allowed(item: NavItem, access: Pick<SharedProps, 'access' | 'branchPermissions'> | null): boolean {
  if (item.anyOf.length === 0) return true;
  if (access === null) return false;
  const owner = access.access?.isOwner ?? false;
  const can = (p: string) => owner || (access.access?.permissions.includes(p) ?? false);
  const canHere = (p: string) => owner || access.branchPermissions.includes(p);
  return item.anyOf.some(item.scope === 'org' ? can : canHere);
}

/** The sections this member may use here, without empty groups; home only until the first page answers. */
export function visibleNavigation(access: Pick<SharedProps, 'access' | 'branchPermissions'> | null): NavGroup[] {
  return navigation
    .map((group) => ({ ...group, items: group.items.filter((item) => allowed(item, access)) }))
    .filter((group) => group.items.length > 0);
}

const items = navigation.flatMap((g) => g.items);

export const navItem = (path: string) => items.find((i) => i.path === path) ?? null;

/** A section's title ("tanks" → "Tanks"). */
export const sectionTitle = (section: string) => navItem(section)?.title ?? null;
