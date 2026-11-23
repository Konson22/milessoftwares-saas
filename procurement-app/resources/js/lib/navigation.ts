import {
    BarChart3,
    CalendarRange,
    CheckSquare,
    ClipboardList,
    FileSearch,
    History,
    LayoutDashboard,
    PackageCheck,
    Settings,
    ShoppingCart,
    Truck,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as approvals } from '@/routes/approvals';
import { index as audit } from '@/routes/audit';
import { index as orders } from '@/routes/orders';
import { index as plans } from '@/routes/plans';
import { index as receiving } from '@/routes/receiving';
import { index as reports } from '@/routes/reports';
import { index as requisitions } from '@/routes/requisitions';
import { show as settings } from '@/routes/settings';
import { index as sourcing } from '@/routes/sourcing';
import { index as suppliers } from '@/routes/suppliers';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The product's sections, in the order buying happens.
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
            },
            {
                title: 'Approvals',
                href: approvals(),
                icon: CheckSquare,
                permission: null,
            },
        ],
    },
    {
        title: 'Buying',
        items: [
            {
                title: 'Requisitions',
                href: requisitions(),
                icon: ClipboardList,
                permission: null,
            },
            {
                title: 'Plans',
                href: plans(),
                icon: CalendarRange,
                permission: 'procurement.view',
            },
            {
                title: 'Sourcing',
                href: sourcing(),
                icon: FileSearch,
                permission: 'procurement.view',
            },
            {
                title: 'Purchase orders',
                href: orders(),
                icon: ShoppingCart,
                permission: 'procurement.view',
            },
            {
                title: 'Receiving',
                href: receiving(),
                icon: PackageCheck,
                permission: 'procurement.view',
            },
            {
                title: 'Suppliers',
                href: suppliers(),
                icon: Truck,
                permission: 'procurement.view',
            },
        ],
    },
    {
        title: 'Oversight',
        items: [
            {
                title: 'Reports',
                href: reports(),
                icon: BarChart3,
                permission: 'procurement.reports.view',
            },
            {
                title: 'Audit trail',
                href: audit(),
                icon: History,
                permission: 'procurement.audit.view',
            },
            {
                title: 'Settings',
                href: settings(),
                icon: Settings,
                permission: 'procurement.settings.manage',
            },
        ],
    },
];

/**
 * The sections the member may use, without empty groups.
 */
export function visibleNavigation(
    can: (permission: string) => boolean,
): NavGroup[] {
    return navigation
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) => item.permission === null || can(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
}
