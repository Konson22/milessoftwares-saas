import {
  ArrowLeftRight,
  Banknote,
  Boxes,
  ChartColumn,
  ClipboardCheck,
  ClipboardList,
  CloudUpload,
  FileText,
  Gift,
  Layers,
  LayoutDashboard,
  ListOrdered,
  MapPin,
  Package,
  PackageCheck,
  PackagePlus,
  Percent,
  Pill,
  ReceiptText,
  ScanBarcode,
  Settings2,
  ShieldAlert,
  ShoppingCart,
  Tags,
  Trash2,
  Truck,
  Undo2,
  UserCog,
  Users,
  Wallet,
  type LucideIcon,
} from 'lucide-react';

import type { Product } from '../../../shared/ipc';
import type { SharedProps } from './pages';
import type { Page } from '@/store/ui';

/**
 * The pharmacy's sections, in the web's menu order (pharmacy-app
 * resources/js/lib/navigation.ts, and retail-navigation.ts for a shop), with
 * the counter's own screens that work offline in place of the web counter,
 * its sales and the member's shift. Offline, the pharmacy on this PC has
 * its own menu (`here`): its pages are src/main/db/pages.ts.
 */
export interface NavItem {
  title: string;
  page: Page;
  icon: LucideIcon;
  /** Shown to members holding it (or owners); null for everyone. */
  permission: string | null;
  /** org: the permission anywhere; branch: in the branch worked in. */
  scope: 'org' | 'branch';
  key?: string;
}

export interface NavGroup {
  title: string;
  items: NavItem[];
}

const thisPc: NavGroup = {
  title: 'This PC',
  items: [{ title: 'Sync', page: 'sync', icon: CloudUpload, permission: null, scope: 'org', key: 'F4' }],
};

const pharmacy: NavGroup[] = [
  {
    title: 'Overview',
    items: [{ title: 'Dashboard', page: 'app:', icon: LayoutDashboard, permission: null, scope: 'org' }],
  },
  {
    title: 'Counter',
    items: [
      { title: 'Sell', page: 'sell', icon: ShoppingCart, permission: 'pharmacy.sales.create', scope: 'branch', key: 'F1' },
      { title: 'Sales', page: 'sales', icon: ReceiptText, permission: null, scope: 'branch', key: 'F2' },
      { title: 'My shift', page: 'shift', icon: Wallet, permission: 'pharmacy.sales.create', scope: 'branch', key: 'F3' },
      { title: 'Order queue', page: 'app:orders', icon: ListOrdered, permission: 'pharmacy.sales.view', scope: 'branch' },
      { title: 'Shifts', page: 'app:tills', icon: Wallet, permission: 'pharmacy.sales.create', scope: 'branch' },
      { title: 'Prescriptions', page: 'app:prescriptions', icon: FileText, permission: 'pharmacy.prescriptions.view', scope: 'branch' },
      { title: 'Customers', page: 'app:customers', icon: Users, permission: 'pharmacy.customers.view', scope: 'branch' },
      { title: 'Counter settings', page: 'app:counter-settings', icon: Settings2, permission: 'pharmacy.sales.settings', scope: 'branch' },
    ],
  },
  {
    title: 'Inventory',
    items: [
      { title: 'Stock', page: 'app:stock', icon: Boxes, permission: 'pharmacy.stock.view', scope: 'branch' },
      { title: 'Locations', page: 'app:locations', icon: MapPin, permission: 'pharmacy.stock.view', scope: 'branch' },
      { title: 'Stock counts', page: 'app:counts', icon: ClipboardCheck, permission: 'pharmacy.stock.view', scope: 'branch' },
      { title: 'Transfers', page: 'app:transfers', icon: ArrowLeftRight, permission: 'pharmacy.stock.view', scope: 'branch' },
      { title: 'Disposals', page: 'app:disposals', icon: Trash2, permission: 'pharmacy.stock.adjust', scope: 'branch' },
      { title: 'Medicines', page: 'app:medicines', icon: Pill, permission: 'pharmacy.medicines.view', scope: 'org' },
      { title: 'Controlled register', page: 'app:controlled-register', icon: ShieldAlert, permission: 'pharmacy.controlled.view', scope: 'branch' },
    ],
  },
  {
    title: 'Purchasing',
    items: [
      { title: 'Purchase orders', page: 'app:purchase-orders', icon: ClipboardList, permission: 'pharmacy.purchasing.view', scope: 'branch' },
      { title: 'Goods received', page: 'app:receipts', icon: PackageCheck, permission: 'pharmacy.purchasing.view', scope: 'branch' },
      { title: 'Returns', page: 'app:supplier-returns', icon: Undo2, permission: 'pharmacy.purchasing.view', scope: 'branch' },
      { title: 'Suppliers', page: 'app:suppliers', icon: Truck, permission: 'pharmacy.purchasing.view', scope: 'org' },
    ],
  },
  {
    title: 'Insights',
    items: [{ title: 'Reports', page: 'app:reports', icon: ChartColumn, permission: 'pharmacy.reports.view', scope: 'branch' }],
  },
  thisPc,
];

const retail: NavGroup[] = [
  {
    title: 'Overview',
    items: [
      { title: 'Dashboard', page: 'app:', icon: LayoutDashboard, permission: null, scope: 'org' },
      { title: 'Reports', page: 'app:reports', icon: ChartColumn, permission: 'retail.reports.view', scope: 'branch' },
    ],
  },
  {
    title: 'Counter',
    items: [
      { title: 'Sell', page: 'sell', icon: ShoppingCart, permission: 'retail.sales.create', scope: 'branch', key: 'F1' },
      { title: 'Sales', page: 'sales', icon: ReceiptText, permission: null, scope: 'branch', key: 'F2' },
      { title: 'My shift', page: 'shift', icon: Wallet, permission: 'retail.sales.create', scope: 'branch', key: 'F3' },
      { title: 'Shifts', page: 'app:tills', icon: Wallet, permission: 'retail.sales.create', scope: 'branch' },
      { title: 'Customers', page: 'app:customers', icon: Users, permission: 'retail.customers.view', scope: 'branch' },
      { title: 'Counter settings', page: 'app:counter-settings', icon: Settings2, permission: 'retail.sales.settings', scope: 'branch' },
    ],
  },
  {
    title: 'Wholesale',
    items: [
      { title: 'Orders', page: 'app:orders', icon: ListOrdered, permission: 'retail.orders.view', scope: 'branch' },
      { title: 'Price lists', page: 'app:price-lists', icon: Tags, permission: 'retail.prices.manage', scope: 'org' },
      { title: 'Promotions', page: 'app:promotions', icon: Percent, permission: 'retail.prices.manage', scope: 'org' },
      { title: 'Loyalty', page: 'app:loyalty', icon: Gift, permission: 'retail.prices.manage', scope: 'org' },
    ],
  },
  {
    title: 'Inventory',
    items: [
      { title: 'Stock', page: 'app:stock', icon: Boxes, permission: 'retail.stock.view', scope: 'branch' },
      { title: 'Shelves', page: 'app:locations', icon: MapPin, permission: 'retail.stock.view', scope: 'branch' },
      { title: 'Stock counts', page: 'app:counts', icon: ClipboardCheck, permission: 'retail.stock.view', scope: 'branch' },
      { title: 'Serial numbers', page: 'app:serials', icon: ScanBarcode, permission: 'retail.stock.view', scope: 'branch' },
      { title: 'Transfers', page: 'app:transfers', icon: ArrowLeftRight, permission: 'retail.stock.view', scope: 'branch' },
      { title: 'Write-offs', page: 'app:disposals', icon: Trash2, permission: 'retail.stock.adjust', scope: 'branch' },
      { title: 'Products', page: 'app:products', icon: Package, permission: 'retail.products.view', scope: 'org' },
    ],
  },
  {
    title: 'Purchasing',
    items: [
      { title: 'Purchase orders', page: 'app:purchase-orders', icon: ClipboardList, permission: 'retail.purchasing.view', scope: 'branch' },
      { title: 'Goods received', page: 'app:receipts', icon: PackageCheck, permission: 'retail.purchasing.view', scope: 'branch' },
      { title: 'Returns', page: 'app:supplier-returns', icon: Undo2, permission: 'retail.purchasing.view', scope: 'branch' },
      { title: 'Suppliers', page: 'app:suppliers', icon: Truck, permission: 'retail.purchasing.view', scope: 'org' },
    ],
  },
  thisPc,
];

/** Offline: the pharmacy on this PC, one branch, permissions by role (src/main/db/session.ts). */
const here: NavGroup[] = [
  {
    title: 'Overview',
    items: [{ title: 'Dashboard', page: 'app:', icon: LayoutDashboard, permission: null, scope: 'org' }],
  },
  {
    title: 'Counter',
    items: [
      { title: 'Sell', page: 'sell', icon: ShoppingCart, permission: 'pharmacy.sales.create', scope: 'org', key: 'F1' },
      { title: 'Sales', page: 'sales', icon: ReceiptText, permission: null, scope: 'org', key: 'F2' },
      { title: 'My shift', page: 'shift', icon: Wallet, permission: 'pharmacy.sales.create', scope: 'org', key: 'F3' },
      { title: 'Prescriptions', page: 'prescriptions', icon: FileText, permission: 'pharmacy.prescriptions.view', scope: 'org', key: 'F4' },
    ],
  },
  {
    title: 'Inventory',
    items: [
      { title: 'Inventory', page: 'app:stock', icon: Boxes, permission: 'pharmacy.stock.view', scope: 'org' },
      { title: 'Batches', page: 'app:batches', icon: Layers, permission: 'pharmacy.stock.view', scope: 'org' },
      { title: 'Receive stock', page: 'app:receipts/create', icon: PackagePlus, permission: 'pharmacy.purchasing.receive', scope: 'org' },
      { title: 'Medicines', page: 'app:medicines', icon: Pill, permission: 'pharmacy.medicines.view', scope: 'org' },
      { title: 'Goods received', page: 'app:receipts', icon: PackageCheck, permission: 'pharmacy.purchasing.view', scope: 'org' },
      { title: 'Suppliers', page: 'app:suppliers', icon: Truck, permission: 'pharmacy.purchasing.view', scope: 'org' },
    ],
  },
  {
    title: 'Finance',
    items: [
      { title: 'Expenses', page: 'app:expenses', icon: Banknote, permission: 'pharmacy.expenses.manage', scope: 'org' },
      { title: 'Reports', page: 'app:reports', icon: ChartColumn, permission: 'pharmacy.reports.view', scope: 'org' },
    ],
  },
  {
    title: 'Admin',
    items: [
      { title: 'Users', page: 'app:users', icon: UserCog, permission: 'pharmacy.users.manage', scope: 'org' },
      { title: 'Settings', page: 'app:settings', icon: Settings2, permission: 'pharmacy.settings.manage', scope: 'org' },
    ],
  },
];

export const navigation: Record<Product | 'here', NavGroup[]> = { pharmacy, retail, here };

/**
 * The sections this member may use here, without empty groups. Until the
 * permissions load: the counter only.
 */
export function visibleNavigation(
  product: Product,
  access: Pick<SharedProps, 'access' | 'branchPermissions'> | null,
  pagesAvailable: boolean,
  local = false,
): NavGroup[] {
  if (local) {
    const permissions = access?.access?.permissions ?? null;
    return navigation.here
      .map((group) => ({
        ...group,
        items: group.items.filter((item) => {
          if (permissions === null) return !item.page.startsWith('app:');
          return item.permission === null || permissions.includes(item.permission);
        }),
      }))
      .filter((group) => group.items.length > 0);
  }
  const owner = access?.access?.isOwner ?? false;
  const can = (p: string) => owner || (access?.access?.permissions.includes(p) ?? false);
  const canHere = (p: string) => owner || (access?.branchPermissions.includes(p) ?? false);

  return navigation[product]
    .map((group) => ({
      ...group,
      items: group.items.filter((item) => {
        const native = !item.page.startsWith('app:');
        // Without the pages (the demo), or before they first load: the counter only.
        if (!native && !pagesAvailable) return false;
        if (item.permission === null) return native || access !== null;
        if (access === null) return native;
        return item.scope === 'org' ? can(item.permission) : canHere(item.permission);
      }),
    }))
    .filter((group) => group.items.length > 0);
}
