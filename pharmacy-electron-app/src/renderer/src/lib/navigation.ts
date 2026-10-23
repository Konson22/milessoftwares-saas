import {
  ArrowLeftRight,
  Boxes,
  ChartColumn,
  ClipboardCheck,
  ClipboardList,
  CloudUpload,
  FileText,
  Gift,
  LayoutDashboard,
  ListOrdered,
  MapPin,
  Package,
  PackageCheck,
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
 * its sales and the member's shift.
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

export const navigation: Record<Product, NavGroup[]> = { pharmacy, retail };

/** The sections this member may use here, without empty groups. */
export function visibleNavigation(
  product: Product,
  access: Pick<SharedProps, 'access' | 'branchPermissions'> | null,
  pagesAvailable: boolean,
): NavGroup[] {
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
