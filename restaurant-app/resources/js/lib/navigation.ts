import {
    Armchair,
    Boxes,
    ChefHat,
    ClipboardList,
    LayoutDashboard,
    LayoutGrid,
    ReceiptText,
    UtensilsCrossed,
    Wallet,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { floor, home, kitchen } from '@/routes';
import { index as bills } from '@/routes/bills';
import { index as menu } from '@/routes/menu';
import { index as orders } from '@/routes/orders';
import { index as stock } from '@/routes/stock';
import { index as tables } from '@/routes/tables';
import { index as tills } from '@/routes/tills';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
    /**
     * org: the permission anywhere is enough (the menu).
     * branch: only when working in a branch where the permission applies.
     */
    scope: 'org' | 'branch';
    /** Any one of these instead of `permission` (tills: cashiers and managers). */
    anyOf?: string[];
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The restaurant's sections, in menu order. Each feature adds its own entry.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Overview',
        items: [
            {
                title: 'Home',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
                scope: 'org',
            },
        ],
    },
    {
        title: 'Service',
        items: [
            {
                title: 'Floor',
                href: floor(),
                icon: LayoutGrid,
                permission: 'restaurant.orders.take',
                scope: 'branch',
            },
            {
                title: 'Orders',
                href: orders(),
                icon: ClipboardList,
                permission: 'restaurant.orders.take',
                scope: 'branch',
            },
            {
                title: 'Kitchen',
                href: kitchen(),
                icon: ChefHat,
                permission: 'restaurant.kitchen.view',
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Cashier',
        items: [
            {
                title: 'Bills paid',
                href: bills(),
                icon: ReceiptText,
                permission: 'restaurant.bills.view',
                scope: 'branch',
            },
            {
                title: 'Shifts',
                href: tills(),
                icon: Wallet,
                permission: 'restaurant.bills.take',
                anyOf: ['restaurant.bills.take', 'restaurant.tills.manage'],
                scope: 'branch',
            },
        ],
    },
    {
        title: 'Set-up',
        items: [
            {
                title: 'Menu',
                href: menu(),
                icon: UtensilsCrossed,
                permission: 'restaurant.menu.view',
                scope: 'org',
            },
            {
                title: 'Tables',
                href: tables(),
                icon: Armchair,
                permission: 'restaurant.tables.manage',
                scope: 'branch',
            },
            {
                title: 'Drinks stock',
                href: stock(),
                icon: Boxes,
                permission: 'restaurant.stock.view',
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
