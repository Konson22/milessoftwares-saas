import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

export type ShellHref = NonNullable<InertiaLinkProps['href']>;

export type ShellNavItem = {
    title: string;
    href: ShellHref;
    icon: LucideIcon;
    /**
     * Another app (a sibling product, the workspace): a full page load,
     * marked with an outward arrow.
     */
    external?: boolean;
};

export type ShellNavGroup = { title: string; items: ShellNavItem[] };

export type ShellActive = { group: ShellNavGroup; item: ShellNavItem } | null;

export type Crumb = { label: string; href?: string };

export function hrefOf(href: ShellHref): string {
    return typeof href === 'string' ? href : href.url;
}

function pathOf(href: ShellHref): string {
    return hrefOf(href).split('?')[0].replace(/\/$/, '');
}

/**
 * The section a URL belongs to: the item whose path is the longest prefix
 * of it. The home item matches only its own path.
 */
export function activeItem(
    groups: ShellNavGroup[],
    url: string,
    home: ShellHref,
): ShellActive {
    const path = url.split('?')[0].replace(/\/$/, '');
    const homePath = pathOf(home);
    let best: { group: ShellNavGroup; item: ShellNavItem; length: number } | null =
        null;

    for (const group of groups) {
        for (const item of group.items) {
            if (item.external) {
                continue;
            }

            const itemPath = pathOf(item.href);
            const matches =
                path === itemPath ||
                (itemPath !== homePath && path.startsWith(`${itemPath}/`));

            if (matches && (best === null || itemPath.length > best.length)) {
                best = { group, item, length: itemPath.length };
            }
        }
    }

    return best && { group: best.group, item: best.item };
}

/**
 * Breadcrumbs for a page: the app, then the section group and item (none
 * on the home page).
 */
export function crumbsFor(
    app: Crumb,
    active: ShellActive,
    home: ShellHref,
): Crumb[] {
    const crumbs: Crumb[] = [app];

    if (active && pathOf(active.item.href) !== pathOf(home)) {
        if (active.group.title !== '') {
            crumbs.push({ label: active.group.title });
        }
        crumbs.push({ label: active.item.title });
    }

    return crumbs;
}
