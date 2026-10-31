import {
    CheckSquare,
    FolderKanban,
    LayoutDashboard,
    Users,
} from 'lucide-react';
import type { ShellNavItem } from '@miles/ui/shell/navigation';
import { home, myWork } from '@/routes';
import { index as people } from '@/routes/people';
import { index as projects } from '@/routes/projects';

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
                title: 'Portfolio',
                href: home(),
                icon: LayoutDashboard,
                permission: null,
            },
            {
                title: 'My work',
                href: myWork(),
                icon: CheckSquare,
                permission: null,
            },
        ],
    },
    {
        title: 'Projects',
        items: [
            {
                title: 'Projects',
                href: projects(),
                icon: FolderKanban,
                permission: 'projects.view',
            },
            {
                title: 'People',
                href: people(),
                icon: Users,
                permission: 'projects.view',
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
