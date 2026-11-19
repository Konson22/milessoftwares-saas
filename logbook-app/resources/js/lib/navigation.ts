import {
    BarChart3,
    BookOpen,
    ClipboardCheck,
    History,
    LayoutDashboard,
    NotebookPen,
    UserCog,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as audit } from '@/routes/audit';
import { index as entries } from '@/routes/entries';
import { index as logbooks } from '@/routes/logbooks';
import { index as people } from '@/routes/people';
import { index as reports } from '@/routes/reports';
import { index as reviews } from '@/routes/reviews';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * Five sections. Log sheet configuration lives inside Logbooks.
 */
export const navigation: NavGroup[] = [
    {
        title: 'Logbook',
        items: [
            {
                title: 'Overview',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
            },
            {
                title: 'My entries',
                href: entries(),
                icon: NotebookPen,
                permission: null,
            },
            {
                title: 'Logbooks',
                href: logbooks(),
                icon: BookOpen,
                permission: null,
            },
            {
                title: 'Reviews',
                href: reviews(),
                icon: ClipboardCheck,
                permission: 'logbook.entries.review',
            },
            {
                title: 'Reports',
                href: reports(),
                icon: BarChart3,
                permission: 'logbook.reports.view',
            },
        ],
    },
    {
        title: 'Oversight',
        items: [
            {
                title: 'Audit trail',
                href: audit(),
                icon: History,
                permission: 'logbook.audit.view',
            },
            {
                title: 'People',
                href: people(),
                icon: UserCog,
                permission: 'logbook.people.manage',
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
