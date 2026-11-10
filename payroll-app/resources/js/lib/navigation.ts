import { Banknote, LayoutDashboard, Settings, Users } from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home, settings } from '@/routes';
import { index as employees } from '@/routes/employees';
import { index as runs } from '@/routes/runs';

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
        title: 'Payroll',
        items: [
            {
                title: 'People',
                href: employees(),
                icon: Users,
                permission: 'payroll.employees.view',
            },
            {
                title: 'Payroll runs',
                href: runs(),
                icon: Banknote,
                permission: 'payroll.pay.view',
            },
            {
                title: 'Settings',
                href: settings(),
                icon: Settings,
                permission: 'payroll.settings',
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
