import {
  Banknote,
  Boxes,
  ChartColumn,
  FileText,
  Layers,
  LayoutDashboard,
  PackageCheck,
  PackagePlus,
  Pill,
  ReceiptText,
  Settings2,
  ShoppingCart,
  Truck,
  UserCog,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

import type { SharedProps } from './pages';
import type { Page } from '@/store/ui';

export interface NavItem {
  title: string;
  page: Page;
  icon: LucideIcon;
  /** Shown to members holding it; null for everyone. */
  permission: string | null;
  key?: string;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

export const navigation: NavGroup[] = [
  {
    title: 'Overview',
    items: [{ title: 'Dashboard', page: 'app:', icon: LayoutDashboard, permission: null }],
  },
  {
    title: 'Counter',
    items: [
      { title: 'Sell', page: 'sell', icon: ShoppingCart, permission: 'pharmacy.sales.create', key: 'F1' },
      { title: 'Sales', page: 'sales', icon: ReceiptText, permission: null, key: 'F2' },
      { title: 'My shift', page: 'shift', icon: Wallet, permission: 'pharmacy.sales.create', key: 'F3' },
      { title: 'Prescriptions', page: 'prescriptions', icon: FileText, permission: 'pharmacy.prescriptions.view', key: 'F4' },
    ],
  },
  {
    title: 'Inventory',
    items: [
      { title: 'Inventory', page: 'app:stock', icon: Boxes, permission: 'pharmacy.stock.view' },
      { title: 'Batches', page: 'app:batches', icon: Layers, permission: 'pharmacy.stock.view' },
      { title: 'Receive stock', page: 'app:receipts/create', icon: PackagePlus, permission: 'pharmacy.purchasing.receive' },
      { title: 'Medicines', page: 'app:medicines', icon: Pill, permission: 'pharmacy.medicines.view' },
      { title: 'Goods received', page: 'app:receipts', icon: PackageCheck, permission: 'pharmacy.purchasing.view' },
      { title: 'Suppliers', page: 'app:suppliers', icon: Truck, permission: 'pharmacy.purchasing.view' },
    ],
  },
  {
    title: 'Finance',
    items: [
      { title: 'Expenses', page: 'app:expenses', icon: Banknote, permission: 'pharmacy.expenses.manage' },
      { title: 'Reports', page: 'app:reports', icon: ChartColumn, permission: 'pharmacy.reports.view' },
    ],
  },
  {
    title: 'Admin',
    items: [
      { title: 'Users', page: 'app:users', icon: UserCog, permission: 'pharmacy.users.manage' },
      { title: 'Settings and backups', page: 'app:settings', icon: Settings2, permission: 'pharmacy.settings.manage' },
    ],
  },
];

/** The sections this member may use, without empty groups. Until the permissions load: the counter only. */
export function visibleNavigation(shared: Pick<SharedProps, 'access'> | null): NavGroup[] {
  const permissions = shared?.access?.permissions ?? null;
  return navigation
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        if (permissions === null) return !item.page.startsWith('app:');
        return item.permission === null || permissions.includes(item.permission);
      }),
    }))
    .filter((group) => group.items.length > 0);
}
