import type { InertiaLinkProps } from '@inertiajs/react';

export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    /** Shown to members holding this catalog permission (or owners). */
    permission: string;
};

/**
 * The pharmacy's sections, in menu order. Each feature adds its own entry.
 */
export const navigation: NavItem[] = [];
