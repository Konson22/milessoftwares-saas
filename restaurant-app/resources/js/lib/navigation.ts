import {
    Armchair,
    Boxes,
    Building2,
    ChartColumn,
    ChefHat,
    ClipboardList,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    Network,
    ReceiptText,
    Truck,
    UserCog,
    UtensilsCrossed,
    Wallet,
    Warehouse,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { floor, home, kitchen, overview } from '@/routes';
import { index as bills } from '@/routes/bills';
import {
    branches as managementBranches,
    inventory as managementInventory,
} from '@/routes/management';
import { index as menu } from '@/routes/menu';
import { index as orders } from '@/routes/orders';
import { day as dayReport } from '@/routes/reports';
import { index as staff } from '@/routes/staff';
import { index as stock } from '@/routes/stock';
import { index as deliveries } from '@/routes/stock/deliveries';
import { index as tables } from '@/routes/tables';
import { index as tills } from '@/routes/tills';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
    /** Any one of these instead of `permission` (tills: cashiers and managers). */
    anyOf?: string[];
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The management workspace: the whole restaurant, for those who oversee
 * every branch. Branch service (floor, kitchen, tills) is not here: each
 * branch opens in its own tab. Branches and roles are set up in the
 * workspace (shared by every app), so those two link there.
 */
export function managementNavigation(workspaceUrl: string | null): NavGroup[] {
    const inWorkspace = (path: string) =>
        workspaceUrl === null ? null : new URL(path, workspaceUrl).toString();
    const structure = inWorkspace('manage/structure');
    const access = inWorkspace('manage/access');

    return [
        {
            title: 'Overview',
            items: [
                {
                    title: 'Dashboard',
                    href: overview(),
                    icon: LayoutDashboard,
                    permission: 'restaurant.branches.overview',
                },
            ],
        },
        {
            title: 'Branches',
            items: [
                {
                    title: 'All branches',
                    href: managementBranches(),
                    icon: Network,
                    permission: 'restaurant.branches.overview',
                },
                ...(structure
                    ? [
                          {
                              title: 'Branch set-up',
                              href: structure,
                              icon: Building2,
                              permission: 'restaurant.access.manage',
                              external: true,
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Oversight',
            items: [
                {
                    title: 'Inventory',
                    href: managementInventory(),
                    icon: Warehouse,
                    permission: 'restaurant.branches.overview',
                },
                {
                    title: 'Menu',
                    href: menu(),
                    icon: UtensilsCrossed,
                    permission: 'restaurant.menu.view',
                },
            ],
        },
        {
            title: 'Administration',
            items: [
                {
                    title: 'Staff & access',
                    href: staff(),
                    icon: UserCog,
                    permission: 'restaurant.access.manage',
                },
                ...(access
                    ? [
                          {
                              title: 'Roles and permissions',
                              href: access,
                              icon: KeyRound,
                              permission: 'restaurant.access.manage',
                              external: true,
                          },
                      ]
                    : []),
            ],
        },
    ];
}

/**
 * One branch's workspace: its service, kitchen, stock, tills and day.
 * Links stay in this branch (the tab's own, see ProductLayout).
 */
export function branchNavigation(): NavGroup[] {
    return [
        {
            title: 'Overview',
            items: [
                {
                    title: 'Branch dashboard',
                    href: home(),
                    icon: LayoutDashboard,
                    permission: null,
                },
            ],
        },
        {
            title: 'Service',
            items: [
                {
                    title: 'Floor (POS)',
                    href: floor(),
                    icon: LayoutGrid,
                    permission: 'restaurant.orders.take',
                },
                {
                    title: 'Orders',
                    href: orders(),
                    icon: ClipboardList,
                    permission: 'restaurant.orders.take',
                },
                {
                    title: 'Tables',
                    href: tables(),
                    icon: Armchair,
                    permission: 'restaurant.tables.manage',
                },
                {
                    title: 'Payments',
                    href: bills(),
                    icon: ReceiptText,
                    permission: 'restaurant.bills.view',
                },
            ],
        },
        {
            title: 'Kitchen',
            items: [
                {
                    title: 'Kitchen display',
                    href: kitchen(),
                    icon: ChefHat,
                    permission: 'restaurant.kitchen.view',
                },
            ],
        },
        {
            title: 'Inventory',
            items: [
                {
                    title: 'Branch stock',
                    href: stock(),
                    icon: Boxes,
                    permission: 'restaurant.stock.view',
                },
                {
                    title: 'Stock receipts',
                    href: deliveries(),
                    icon: Truck,
                    permission: 'restaurant.stock.view',
                    anyOf: [
                        'restaurant.stock.view',
                        'restaurant.stock.receive',
                    ],
                },
            ],
        },
        {
            title: 'Staff operations',
            items: [
                {
                    title: 'Shifts and cash',
                    href: tills(),
                    icon: Wallet,
                    permission: 'restaurant.bills.take',
                    anyOf: ['restaurant.bills.take', 'restaurant.tills.manage'],
                },
            ],
        },
        {
            title: 'Reports',
            items: [
                {
                    title: 'Day report',
                    href: dayReport(),
                    icon: ChartColumn,
                    permission: 'restaurant.reports.view',
                },
            ],
        },
    ];
}

/**
 * The sections the member may use, without empty groups: in management
 * by their permissions anywhere, in a branch by those that apply there.
 */
export function visible(
    groups: NavGroup[],
    can: (permission: string) => boolean,
): NavGroup[] {
    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    item.permission === null ||
                    (item.anyOf ?? [item.permission]).some(can),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
