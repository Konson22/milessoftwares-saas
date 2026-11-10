import {
    Building2,
    ClipboardList,
    Droplets,
    Fuel,
    Gauge,
    LayoutDashboard,
    MapPinned,
    ArrowLeftRight,
    Package,
    ReceiptText,
    Settings,
    ShieldCheck,
    ShoppingCart,
    Users,
    Wallet,
    UserCog,
    Warehouse,
    Wrench,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as overview } from '@/routes/overview';
import { index as assignments } from '@/routes/assignments';
import { index as attendants } from '@/routes/attendants';
import { index as audit } from '@/routes/audit';
import { index as cash } from '@/routes/cash';
import { index as calibration } from '@/routes/calibration';
import { index as currencies } from '@/routes/currencies';
import { index as deliveries } from '@/routes/deliveries';
import { index as customers } from '@/routes/customers';
import { index as exceptions } from '@/routes/exceptions';
import { index as handovers } from '@/routes/handovers';
import { index as inventory } from '@/routes/inventory';
import { index as expenses } from '@/routes/expenses';
import { index as finance } from '@/routes/finance';
import { index as products } from '@/routes/products';
import { index as pumps } from '@/routes/pumps';
import { index as purchases } from '@/routes/purchases';
import { index as rates } from '@/routes/rates';
import { index as readings } from '@/routes/readings';
import { index as reconciliation } from '@/routes/reconciliation';
import { index as reports } from '@/routes/reports';
import { index as sales } from '@/routes/sales';
import { index as service } from '@/routes/service';
import { index as settings } from '@/routes/settings';
import { index as shifts } from '@/routes/shifts';
import { index as staff } from '@/routes/staff';
import { index as stationList } from '@/routes/stations';
import { index as stores } from '@/routes/stores';
import { index as suppliers } from '@/routes/suppliers';
import { index as tanks } from '@/routes/tanks';
import { index as transfers } from '@/routes/transfers';

export type NavItem = ShellNavItem & {
    permission: string | null;
    scope: 'org' | 'branch';
    anyOf?: string[];
};

export type NavGroup = { title: string; items: NavItem[] };

export const navigation: NavGroup[] = [
    {
        title: 'Dashboard',
        items: [
            {
                title: 'Home',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
                scope: 'org',
            },
            {
                title: 'Operations dashboard',
                href: overview(),
                icon: Building2,
                permission: 'petrol.reports.view',
                anyOf: ['petrol.reports.view', 'petrol.cash.report'],
                scope: 'org',
            },
        ],
    },
    {
        // Company-wide: every station, its pump staff and its stock, without choosing a station first.
        title: 'Management',
        items: [
            {
                title: 'Stations',
                href: stationList(),
                icon: MapPinned,
                permission: 'petrol.reports.view',
                anyOf: ['petrol.reports.view', 'petrol.stations.manage'],
                scope: 'org',
            },
            {
                title: 'Staff',
                href: staff(),
                icon: UserCog,
                permission: 'petrol.attendants.view',
                anyOf: ['petrol.attendants.view', 'petrol.attendants.manage'],
                scope: 'org',
            },
            {
                title: 'Inventory',
                href: inventory(),
                icon: Warehouse,
                permission: 'petrol.tanks.view',
                anyOf: [
                    'petrol.tanks.view',
                    'petrol.purchases.view',
                    'petrol.store.manage',
                    'petrol.store.move',
                    'petrol.reports.view',
                ],
                scope: 'org',
            },
        ],
    },
    {
        title: 'Operations',
        items: [
            {
                title: 'Sales',
                href: sales(),
                icon: ReceiptText,
                permission: 'petrol.sales.view',
                scope: 'branch',
            },
            {
                title: 'Shifts',
                href: shifts(),
                icon: ClipboardList,
                permission: 'petrol.shifts.view',
                scope: 'branch',
            },
            {
                title: 'Assignments',
                href: assignments(),
                icon: Users,
                permission: 'petrol.shifts.view',
                scope: 'branch',
            },
            {
                title: 'Readings',
                href: readings(),
                icon: Gauge,
                permission: 'petrol.shifts.view',
                scope: 'branch',
            },
            {
                title: 'Reconciliation',
                href: reconciliation(),
                icon: ShieldCheck,
                permission: 'petrol.shifts.view',
                scope: 'branch',
            },
            {
                title: 'Service bay',
                href: service(),
                icon: Wrench,
                permission: 'petrol.service.work',
                anyOf: ['petrol.service.work', 'petrol.service.manage'],
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Fuel',
        items: [
            {
                title: 'Products',
                href: products(),
                icon: Fuel,
                permission: 'petrol.tanks.view',
                anyOf: [
                    'petrol.tanks.view',
                    'petrol.products.manage',
                    'petrol.prices.manage',
                ],
                scope: 'branch',
            },
            {
                title: 'Tanks',
                href: tanks(),
                icon: Droplets,
                permission: 'petrol.tanks.view',
                scope: 'branch',
            },
            {
                title: 'Deliveries',
                href: deliveries(),
                icon: Droplets,
                permission: 'petrol.purchases.view',
                anyOf: [
                    'petrol.purchases.view',
                    'petrol.receiving.record',
                    'petrol.receiving.approve',
                ],
                scope: 'branch',
            },
            {
                title: 'Calibration',
                href: calibration(),
                icon: Gauge,
                permission: 'petrol.pumps.view',
                anyOf: [
                    'petrol.pumps.view',
                    'petrol.calibration.record',
                    'petrol.equipment.manage',
                ],
                scope: 'branch',
            },
            {
                title: 'Handovers',
                href: handovers(),
                icon: ArrowLeftRight,
                permission: 'petrol.assignments.view',
                anyOf: [
                    'petrol.assignments.view',
                    'petrol.handovers.initiate',
                    'petrol.shifts.view',
                ],
                scope: 'branch',
            },
            {
                title: 'Exceptions',
                href: exceptions(),
                icon: ClipboardList,
                permission: 'petrol.exceptions.review',
                anyOf: ['petrol.exceptions.review', 'petrol.shifts.view'],
                scope: 'branch',
            },
            {
                title: 'Pumps',
                href: pumps(),
                icon: Fuel,
                permission: 'petrol.pumps.view',
                scope: 'branch',
            },
            {
                title: 'Transfers',
                href: transfers(),
                icon: ArrowLeftRight,
                permission: 'petrol.transfers.manage',
                anyOf: ['petrol.transfers.manage', 'petrol.transfers.approve'],
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Business',
        items: [
            {
                title: 'Attendants',
                href: attendants(),
                icon: Users,
                permission: 'petrol.attendants.view',
                scope: 'branch',
            },
            {
                title: 'Customers',
                href: customers(),
                icon: Users,
                permission: 'petrol.customers.view',
                scope: 'branch',
            },
            {
                title: 'Suppliers',
                href: suppliers(),
                icon: ShoppingCart,
                permission: 'petrol.purchases.view',
                scope: 'branch',
            },
            {
                title: 'Purchases',
                href: purchases(),
                icon: ShoppingCart,
                permission: 'petrol.purchases.view',
                scope: 'branch',
            },
            {
                title: 'Store',
                href: stores(),
                icon: Package,
                permission: 'petrol.store.manage',
                anyOf: ['petrol.store.manage', 'petrol.store.move'],
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Finance',
        items: [
            {
                title: 'Expenses',
                href: expenses(),
                icon: Wallet,
                permission: 'petrol.expenses.manage',
                anyOf: ['petrol.expenses.manage', 'petrol.finance.view'],
                scope: 'branch',
            },
            {
                title: 'Cash',
                href: cash(),
                icon: Wallet,
                permission: 'petrol.cash.manage',
                anyOf: [
                    'petrol.cash.manage',
                    'petrol.finance.view',
                    'petrol.cash.view',
                    'petrol.cash.submit',
                ],
                scope: 'branch',
            },
            {
                title: 'Payouts',
                href: `${cash().url}#payouts`,
                icon: Wallet,
                permission: 'petrol.cash.transfer',
                scope: 'branch',
            },
            {
                title: 'Drawer adjustments',
                href: `${cash().url}#adjustments`,
                icon: Wallet,
                permission: 'petrol.cash.adjust',
                scope: 'branch',
            },
            {
                title: 'Overview',
                href: finance(),
                icon: Wallet,
                permission: 'petrol.finance.view',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Reports',
        items: [
            {
                title: 'Reports',
                href: reports(),
                icon: ClipboardList,
                permission: 'petrol.reports.view',
                scope: 'branch',
            },
            {
                title: 'Audit',
                href: audit(),
                icon: ShieldCheck,
                permission: 'petrol.audit.view',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Settings',
        items: [
            {
                title: 'Station',
                href: settings(),
                icon: Settings,
                permission: 'petrol.stations.manage',
                anyOf: [
                    'petrol.stations.manage',
                    'petrol.prices.manage',
                    'petrol.products.manage',
                ],
                scope: 'branch',
            },
            {
                title: 'Currencies',
                href: currencies(),
                icon: Wallet,
                permission: 'petrol.currencies.view',
                anyOf: [
                    'petrol.currencies.view',
                    'petrol.currencies.manage',
                    'petrol.currencies.enable',
                ],
                scope: 'branch',
            },
            {
                title: 'Exchange rates',
                href: rates(),
                icon: Wallet,
                permission: 'petrol.rates.manage',
                anyOf: ['petrol.rates.manage', 'petrol.prices.manage'],
                scope: 'branch',
            },
        ],
    },
];

function itemVisible(
    item: NavItem,
    can: (permission: string) => boolean,
    canHere: (permission: string) => boolean,
    hasBranch: boolean,
): boolean {
    if (item.permission === null) {
        return true;
    }

    const permissions = item.anyOf ?? [item.permission];
    if (item.scope === 'org') {
        return permissions.some(can);
    }

    return hasBranch && permissions.some(canHere);
}

export function visibleNavigation(
    can: (permission: string) => boolean,
    canHere: (permission: string) => boolean,
    hasBranch: boolean,
): NavGroup[] {
    return navigation
        .map((group) => ({
            ...group,
            items: group.items.filter((item) =>
                itemVisible(item, can, canHere, hasBranch),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
