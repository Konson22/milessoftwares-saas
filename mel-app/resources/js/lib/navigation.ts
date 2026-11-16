import {
    BarChart3,
    ClipboardCheck,
    ClipboardList,
    FolderKanban,
    Gauge,
    History,
    LayoutDashboard,
    ListTree,
    Settings,
    SquarePen,
    UserCheck,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes/mel';
import { index as audit } from '@/routes/mel/audit';
import { index as collectAssignments } from '@/routes/mel/collect/assignments';
import { index as collectForms } from '@/routes/mel/collect/forms';
import { index as data } from '@/routes/mel/data';
import { index as frameworks } from '@/routes/mel/frameworks';
import { index as indicators } from '@/routes/mel/indicators';
import { index as programmes } from '@/routes/mel/programmes';
import { index as reports } from '@/routes/mel/reports';
import { show as settings } from '@/routes/mel/settings';
import { index as verification } from '@/routes/mel/verification';

export type NavItem = ShellNavItem & {
    /**
     * Shown to members holding this catalog permission, or any of these
     * (or owners); null for everyone.
     */
    permission: string | string[] | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/** Programmes are reached with either permission (App\Mel\Access). */
const PROGRAMMES = ['mel.programmes.view', 'mel.programmes.all'];

/**
 * The product's sections, in menu order (ADR-0019 §5). Evaluations join
 * once they are built.
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
                permission: PROGRAMMES,
            },
            {
                title: 'Results frameworks',
                href: frameworks(),
                icon: ListTree,
                permission: PROGRAMMES,
            },
            {
                title: 'Indicators',
                href: indicators(),
                icon: Gauge,
                permission: PROGRAMMES,
            },
        ],
    },
    {
        title: 'Monitoring',
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
        ],
    },
    {
        title: 'Data collection',
        items: [
            {
                title: 'Forms & surveys',
                href: collectForms(),
                icon: ClipboardList,
                permission: 'mel.collect.view',
            },
            {
                title: 'Field assignments',
                href: collectAssignments(),
                icon: UserCheck,
                permission: 'mel.collect.view',
            },
        ],
    },
    {
        title: 'Reports',
        items: [
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

function allowed(
    permission: NavItem['permission'],
    can: (permission: string) => boolean,
): boolean {
    if (permission === null) {
        return true;
    }

    return (Array.isArray(permission) ? permission : [permission]).some(can);
}

/**
 * The sections the member may use, without empty groups.
 */
export function visibleNavigation(
    can: (permission: string) => boolean,
): NavGroup[] {
    return navigation
        .map((group) => ({
            ...group,
            items: group.items.filter((item) => allowed(item.permission, can)),
        }))
        .filter((group) => group.items.length > 0);
}
