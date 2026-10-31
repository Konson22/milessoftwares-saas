import type { IconName } from '@/components/ui/button';

import type { SharedProps } from './pages';
import type { Mode } from './types';

/**
 * The restaurant's sections, in the web's menu order (restaurant-app
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
  /** A screen of the app rather than a page (backups). */
  screen?: string;
  /** Only in this mode: the phone's people, settings and backups are offline only. */
  only?: Mode;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

export const navigation: NavGroup[] = [
  {
    title: 'Overview',
    items: [{ title: 'Home', path: '', icon: 'home-outline', anyOf: [], scope: 'org', tab: '/home' }],
  },
  {
    title: 'Service',
    items: [
      { title: 'Floor', path: 'floor', icon: 'grid-outline', anyOf: ['restaurant.orders.take'], scope: 'branch', tab: '/floor' },
      { title: 'Orders', path: 'orders', icon: 'list-outline', anyOf: ['restaurant.orders.take'], scope: 'branch' },
      { title: 'Kitchen', path: 'kitchen', icon: 'flame-outline', anyOf: ['restaurant.kitchen.view'], scope: 'branch', tab: '/kitchen' },
    ],
  },
  {
    title: 'Cashier',
    items: [
      { title: 'Bills paid', path: 'bills', icon: 'receipt-outline', anyOf: ['restaurant.bills.view'], scope: 'branch', tab: '/bills' },
      { title: 'Shifts', path: 'tills', icon: 'wallet-outline', anyOf: ['restaurant.bills.take', 'restaurant.tills.manage'], scope: 'branch', tab: '/tills' },
    ],
  },
  {
    title: 'Set-up',
    items: [
      { title: 'Menu', path: 'menu', icon: 'restaurant-outline', anyOf: ['restaurant.menu.view'], scope: 'org' },
      { title: 'Tables', path: 'tables', icon: 'apps-outline', anyOf: ['restaurant.tables.manage'], scope: 'branch' },
      { title: 'Drinks stock', path: 'stock', icon: 'beer-outline', anyOf: ['restaurant.stock.view'], scope: 'branch' },
    ],
  },
  {
    title: 'This phone',
    items: [
      { title: 'Staff', path: 'users', icon: 'people-outline', anyOf: ['restaurant.users.manage'], scope: 'org', only: 'offline' },
      { title: 'Settings', path: 'settings', icon: 'settings-outline', anyOf: ['restaurant.settings.manage'], scope: 'org', only: 'offline' },
      { title: 'Backups', path: 'backup', icon: 'cloud-download-outline', anyOf: ['restaurant.users.manage'], scope: 'org', only: 'offline', screen: '/backup' },
    ],
  },
];

/** Whether this member may open a section here. */
export function allowed(item: NavItem, access: Pick<SharedProps, 'access' | 'branchPermissions'> | null, mode: Mode | null): boolean {
  if (item.only && item.only !== mode) return false;
  if (item.anyOf.length === 0) return true;
  if (access === null) return false;
  const owner = access.access?.isOwner ?? false;
  const can = (p: string) => owner || (access.access?.permissions.includes(p) ?? false);
  const canHere = (p: string) => owner || access.branchPermissions.includes(p);
  return item.anyOf.some(item.scope === 'org' ? can : canHere);
}

/** The sections this member may use here, without empty groups; home only until the first page answers. */
export function visibleNavigation(access: Pick<SharedProps, 'access' | 'branchPermissions'> | null, mode: Mode | null): NavGroup[] {
  return navigation
    .map((group) => ({ ...group, items: group.items.filter((item) => allowed(item, access, mode)) }))
    .filter((group) => group.items.length > 0);
}

const items = navigation.flatMap((g) => g.items);

export const navItem = (path: string) => items.find((i) => i.path === path) ?? null;

/** A section's title ("stock" → "Drinks stock"). */
export const sectionTitle = (section: string) => navItem(section)?.title ?? null;
