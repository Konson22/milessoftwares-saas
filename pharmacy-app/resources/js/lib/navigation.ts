import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Boxes,
    ChartColumn,
    ClipboardList,
    FileText,
    LayoutDashboard,
    PackageCheck,
    Pill,
    ReceiptText,
    ShieldAlert,
    ShoppingCart,
    Truck,
    Undo2,
} from 'lucide-react';
import { home } from '@/routes';
import { index as controlled } from '@/routes/controlled';
import { index as medicines } from '@/routes/medicines';
import { index as prescriptions } from '@/routes/prescriptions';
import { index as purchaseOrders } from '@/routes/purchase-orders';
import { index as receipts } from '@/routes/receipts';
import { index as reports } from '@/routes/reports';
import { create as counter, index as sales } from '@/routes/sales';
import { index as stock } from '@/routes/stock';
import { index as supplierReturns } from '@/routes/supplier-returns';
import { index as suppliers } from '@/routes/suppliers';

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon: LucideIcon;
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
    /**
     * org: permission anywhere is enough (medicines, suppliers).
     * branch: only when working in a branch where the permission applies.
     */
    scope: 'org' | 'branch';
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The pharmacy's sections, in menu order. Each feature adds its own entry.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Overview',
        items: [
            {
                title: 'Dashboard',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
                scope: 'org',
            },
        ],
    },
    {
        title: 'Counter',
        items: [
            {
                title: 'Counter',
                href: counter(),
                icon: ShoppingCart,
                permission: 'pharmacy.sales.create',
                scope: 'branch',
            },
            {
                title: 'Sales',
                href: sales(),
                icon: ReceiptText,
                permission: 'pharmacy.sales.view',
                scope: 'branch',
            },
            {
                title: 'Prescriptions',
                href: prescriptions(),
                icon: FileText,
                permission: 'pharmacy.prescriptions.view',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Inventory',
        items: [
            {
                title: 'Stock',
                href: stock(),
                icon: Boxes,
                permission: 'pharmacy.stock.view',
                scope: 'branch',
            },
            {
                title: 'Medicines',
                href: medicines(),
                icon: Pill,
                permission: 'pharmacy.medicines.view',
                scope: 'org',
            },
            {
                title: 'Controlled register',
                href: controlled(),
                icon: ShieldAlert,
                permission: 'pharmacy.controlled.view',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Purchasing',
        items: [
            {
                title: 'Purchase orders',
                href: purchaseOrders(),
                icon: ClipboardList,
                permission: 'pharmacy.purchasing.view',
                scope: 'branch',
            },
            {
                title: 'Goods received',
                href: receipts(),
                icon: PackageCheck,
                permission: 'pharmacy.purchasing.view',
                scope: 'branch',
            },
            {
                title: 'Returns',
                href: supplierReturns(),
                icon: Undo2,
                permission: 'pharmacy.purchasing.view',
                scope: 'branch',
            },
            {
                title: 'Suppliers',
                href: suppliers(),
                icon: Truck,
                permission: 'pharmacy.purchasing.view',
                scope: 'org',
            },
        ],
    },
    {
        title: 'Insights',
        items: [
            {
                title: 'Reports',
                href: reports(),
                icon: ChartColumn,
                permission: 'pharmacy.reports.view',
                scope: 'branch',
            },
        ],
    },
];

function pathOf(href: NavItem['href']): string {
    return (typeof href === 'string' ? href : href.url).split('?')[0];
}

function itemVisible(
    item: NavItem,
    can: (permission: string) => boolean,
    canHere: (permission: string) => boolean,
    hasBranch: boolean,
): boolean {
    if (item.permission === null) {
        return true;
    }

    if (item.scope === 'org') {
        return can(item.permission);
    }

    return hasBranch && canHere(item.permission);
}

/**
 * The sections the member may use in this branch, without empty groups.
 */
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

/**
 * The section a URL belongs to: the item whose path is the longest prefix
 * of it (the dashboard only on its own path).
 */
export function activeItem(
    groups: NavGroup[],
    url: string,
): { group: NavGroup; item: NavItem } | null {
    const path = url.split('?')[0].replace(/\/$/, '');
    const homePath = pathOf(home()).replace(/\/$/, '');
    let best: { group: NavGroup; item: NavItem; length: number } | null = null;

    for (const group of groups) {
        for (const item of group.items) {
            const itemPath = pathOf(item.href).replace(/\/$/, '');
            const matches =
                path === itemPath ||
                (itemPath !== homePath && path.startsWith(`${itemPath}/`));

            if (matches && (best === null || itemPath.length > best.length)) {
                best = { group, item, length: itemPath.length };
            }
        }
    }

    return best && { group: best.group, item: best.item };
}
