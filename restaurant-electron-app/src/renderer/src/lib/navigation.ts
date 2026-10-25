import { Armchair, Boxes, ChefHat, ClipboardList, LayoutDashboard, LayoutGrid, ReceiptText, UtensilsCrossed, Wallet, type LucideIcon } from 'lucide-react';

import type { SharedProps } from './pages';

/**
 * The restaurant's sections, in the web's menu order (restaurant-app
 * resources/js/lib/navigation.ts). Each opens the web page of that path.
 */
export interface NavItem {
  title: string;
  /** The web path ("" is home). */
  path: string;
  icon: LucideIcon;
  /** Shown to members holding one of these (or owners); empty for everyone. */
  anyOf: string[];
  /** org: the permission anywhere; branch: in the branch worked in. */
  scope: 'org' | 'branch';
  key?: string;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

export const navigation: NavGroup[] = [
  {
    title: 'Overview',
    items: [{ title: 'Home', path: '', icon: LayoutDashboard, anyOf: [], scope: 'org' }],
  },
  {
    title: 'Service',
    items: [
      { title: 'Floor', path: 'floor', icon: LayoutGrid, anyOf: ['restaurant.orders.take'], scope: 'branch', key: 'F1' },
      { title: 'Orders', path: 'orders', icon: ClipboardList, anyOf: ['restaurant.orders.take'], scope: 'branch', key: 'F2' },
      { title: 'Kitchen', path: 'kitchen', icon: ChefHat, anyOf: ['restaurant.kitchen.view'], scope: 'branch', key: 'F3' },
    ],
  },
  {
    title: 'Cashier',
    items: [
      { title: 'Bills paid', path: 'bills', icon: ReceiptText, anyOf: ['restaurant.bills.view'], scope: 'branch' },
      { title: 'Shifts', path: 'tills', icon: Wallet, anyOf: ['restaurant.bills.take', 'restaurant.tills.manage'], scope: 'branch', key: 'F4' },
    ],
  },
  {
    title: 'Set-up',
    items: [
      { title: 'Menu', path: 'menu', icon: UtensilsCrossed, anyOf: ['restaurant.menu.view'], scope: 'org' },
      { title: 'Tables', path: 'tables', icon: Armchair, anyOf: ['restaurant.tables.manage'], scope: 'branch' },
      { title: 'Drinks stock', path: 'stock', icon: Boxes, anyOf: ['restaurant.stock.view'], scope: 'branch' },
    ],
  },
];

/** The sections this member may use here, without empty groups; home only until the first page answers. */
export function visibleNavigation(access: Pick<SharedProps, 'access' | 'branchPermissions'> | null): NavGroup[] {
  const owner = access?.access?.isOwner ?? false;
  const can = (p: string) => owner || (access?.access?.permissions.includes(p) ?? false);
  const canHere = (p: string) => owner || (access?.branchPermissions.includes(p) ?? false);

  return navigation
    .map((group) => ({
      ...group,
      items: group.items.filter(
        (item) => item.anyOf.length === 0 || (access !== null && item.anyOf.some(item.scope === 'org' ? can : canHere)),
      ),
    }))
    .filter((group) => group.items.length > 0);
}

/** The section a page belongs to: its own, or the one it sits under ("orders/<id>" under Orders). */
export function isCurrent(item: NavItem, path: string): boolean {
  if (item.path === path) return true;
  return item.path !== '' && (path.startsWith(`${item.path}/`) || path.startsWith(`${item.path}?`));
}
