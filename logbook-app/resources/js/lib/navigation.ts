import {
    BarChart3,
    BookOpen,
    Boxes,
    CalendarClock,
    ClipboardCheck,
    History,
    LayoutDashboard,
    MapPin,
    ListTodo,
    NotebookPen,
    Radio,
    Truck,
    TriangleAlert,
    UserCog,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home } from '@/routes';
import { index as audit } from '@/routes/audit';
import { index as entries } from '@/routes/entries';
import { index as exceptions } from '@/routes/exceptions';
import { index as logbooks } from '@/routes/logbooks';
import { index as people } from '@/routes/people';
import { index as reports } from '@/routes/reports';
import { index as resources } from '@/routes/resources';
import { index as reviews } from '@/routes/reviews';
import { index as schedules } from '@/routes/schedules';
import { comingSoon } from '@/routes';
import { index as tasks } from '@/routes/tasks';
import { index as tracks } from '@/routes/tracks';

export type NavItem = ShellNavItem & {
    /** Shown to members holding this catalog permission (or owners); null for everyone. */
    permission: string | null;
};

export type NavGroup = { title: string; items: NavItem[] };

/**
 * One list, as in the design reference: recording first, then follow-up
 * (ADR-0026) and oversight. Notifications live behind the bell in the top
 * bar. Log sheet configuration lives inside Logbooks. Only the planned
 * integrations keep a group title.
 */
export const navigation: NavGroup[] = [
    {
        title: '',
        items: [
            {
                title: 'Overview',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
            },
            {
                title: 'Logbooks',
                href: logbooks(),
                icon: BookOpen,
                permission: null,
            },
            {
                title: 'Entries',
                href: entries(),
                icon: NotebookPen,
                permission: null,
            },
            {
                title: 'Reviews',
                href: reviews(),
                icon: ClipboardCheck,
                permission: 'logbook.entries.review',
            },
            {
                title: 'Tasks',
                href: tasks(),
                icon: ListTodo,
                permission: null,
            },
            {
                title: 'Schedules',
                href: schedules(),
                icon: CalendarClock,
                permission: null,
            },
            {
                title: 'Exceptions',
                href: exceptions(),
                icon: TriangleAlert,
                permission: null,
            },
            {
                title: 'Resources',
                href: resources(),
                icon: Boxes,
                permission: null,
            },
            {
                title: 'GPS Routes',
                href: tracks(),
                icon: MapPin,
                permission: null,
            },
            {
                title: 'Reports',
                href: reports(),
                icon: BarChart3,
                permission: 'logbook.reports.view',
            },
            {
                title: 'People',
                href: people(),
                icon: UserCog,
                permission: 'logbook.people.manage',
            },
            {
                title: 'Audit trail',
                href: audit(),
                icon: History,
                permission: 'logbook.audit.view',
            },
        ],
    },
    {
        // ADR-0028 phases 2 and 3: pages describing what is planned, nothing more.
        title: 'Coming soon',
        items: [
            {
                title: 'GPS devices',
                href: comingSoon('gps-devices'),
                icon: Radio,
                permission: 'logbook.manage',
            },
            {
                title: 'Fleet management',
                href: comingSoon('fleet'),
                icon: Truck,
                permission: 'logbook.manage',
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
