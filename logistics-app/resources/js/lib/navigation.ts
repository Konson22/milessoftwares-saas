import {
    Building2,
    LayoutDashboard,
    Package,
    ReceiptText,
    Route,
    Truck,
    UserRound,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as customers } from '@/routes/customers';
import { index as drivers } from '@/routes/drivers';
import { index as invoices } from '@/routes/invoices';
import { index as shipments } from '@/routes/shipments';
import { index as trips } from '@/routes/trips';
import { index as vehicles } from '@/routes/vehicles';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The product's sections, in menu order. Each feature adds its own entry.
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
        ],
    },
    {
        title: 'Operations',
        items: [
            {
                title: 'Shipments',
                href: shipments(),
                icon: Package,
                permission: 'logistics.shipments.view',
            },
            {
                title: 'Trips',
                href: trips(),
                icon: Route,
                permission: 'logistics.trips.view',
            },
            {
                title: 'Customers',
                href: customers(),
                icon: Building2,
                permission: 'logistics.shipments.view',
            },
        ],
    },
    {
        title: 'Fleet',
        items: [
            {
                title: 'Vehicles',
                href: vehicles(),
                icon: Truck,
                permission: 'logistics.fleet.view',
            },
            {
                title: 'Drivers',
                href: drivers(),
                icon: UserRound,
                permission: 'logistics.fleet.view',
            },
        ],
    },
    {
        title: 'Billing',
        items: [
            {
                title: 'Invoices',
                href: invoices(),
                icon: ReceiptText,
                permission: 'logistics.billing.view',
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
