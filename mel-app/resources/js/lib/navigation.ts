import {
    BarChart3,
    ClipboardCheck,
    FolderKanban,
    Gauge,
    History,
    LayoutDashboard,
    ListTree,
    Settings,
    SquarePen,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as audit } from '@/routes/audit';
import { index as data } from '@/routes/data';
import { index as frameworks } from '@/routes/frameworks';
import { index as indicators } from '@/routes/indicators';
import { index as programmes } from '@/routes/programmes';
import { index as reports } from '@/routes/reports';
import { show as settings } from '@/routes/settings';
import { index as verification } from '@/routes/verification';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * The product's sections, in menu order.
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
            },
        ],
    },
    {
        title: 'Plan',
        items: [
            {
                title: 'Programmes',
                href: programmes(),
                icon: FolderKanban,
                permission: 'mel.programmes.view',
            },
            {
                title: 'Results frameworks',
                href: frameworks(),
                icon: ListTree,
                permission: 'mel.programmes.view',
            },
            {
                title: 'Indicators',
                href: indicators(),
                icon: Gauge,
                permission: 'mel.programmes.view',
            },
        ],
    },
    {
        title: 'Results',
        items: [
            {
                title: 'Data entry',
                href: data(),
                icon: SquarePen,
                permission: 'mel.data.view',
            },
            {
                title: 'Verification',
                href: verification(),
                icon: ClipboardCheck,
                permission: 'mel.data.review',
            },
            {
                title: 'Reports',
                href: reports(),
                icon: BarChart3,
                permission: 'mel.reports.view',
            },
        ],
    },
    {
        title: 'Administration',
        items: [
            {
                title: 'Settings',
                href: settings(),
                icon: Settings,
                permission: 'mel.settings.manage',
            },
            {
                title: 'Audit trail',
                href: audit(),
                icon: History,
                permission: 'mel.audit.view',
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
