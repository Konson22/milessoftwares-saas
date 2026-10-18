import { Form, Link } from '@inertiajs/react';
import { ChevronDown, LogOut } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { useDismiss } from '../lib/use-dismiss';

export type UserMenuLink = {
    label: string;
    href: string;
    icon: LucideIcon;
    /** Another app or host: a full page load instead of an Inertia visit. */
    external?: boolean;
};

function initials(name: string): string {
    const parts = name.trim().split(/\s+/);

    return (
        (parts[0]?.[0] ?? '') + (parts.length > 1 ? parts.at(-1)![0] : '')
    ).toUpperCase();
}

const menuItemClass =
    'flex w-full items-center gap-2 px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none';

/**
 * Who is signed in (and where), links of their own, and signing out.
 */
export function UserMenu({
    user,
    subtitle,
    links = [],
    signOut,
}: {
    user: { name: string; email: string };
    subtitle?: string | null;
    links?: UserMenuLink[];
    signOut?: { label: string; action: string; method: 'post' };
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismiss(ref, open, close);

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="flex items-center gap-2 px-1.5 py-0.5 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span className="grid size-7 shrink-0 place-items-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">
                    {initials(user.name)}
                </span>
                <span className="hidden min-w-0 leading-tight sm:block">
                    <span className="block max-w-36 truncate text-sm font-medium">
                        {user.name}
                    </span>
                    {subtitle && (
                        <span className="block max-w-36 truncate text-xs text-muted-foreground">
                            {subtitle}
                        </span>
                    )}
                </span>
                <ChevronDown
                    className="hidden size-4 text-muted-foreground sm:block"
                    aria-hidden
                />
            </button>
            {open && (
                <div
                    role="menu"
                    className="absolute right-0 z-30 mt-1 w-60 rounded-md border bg-popover p-1 text-popover-foreground shadow-lg"
                >
                    <div className="px-2 py-1.5">
                        <p className="truncate text-sm font-medium">
                            {user.name}
                        </p>
                        <p className="truncate text-xs text-muted-foreground">
                            {user.email}
                        </p>
                    </div>
                    {(links.length > 0 || signOut) && (
                        <div className="my-1 h-px bg-border" />
                    )}
                    {links.map((link) => {
                        const Icon = link.icon;
                        const content = (
                            <>
                                <Icon className="size-4" aria-hidden />
                                {link.label}
                            </>
                        );

                        return link.external ? (
                            <a
                                key={link.href}
                                href={link.href}
                                role="menuitem"
                                className={menuItemClass}
                            >
                                {content}
                            </a>
                        ) : (
                            <Link
                                key={link.href}
                                href={link.href}
                                role="menuitem"
                                onClick={close}
                                className={menuItemClass}
                            >
                                {content}
                            </Link>
                        );
                    })}
                    {signOut && (
                        <Form action={signOut.action} method={signOut.method}>
                            <button
                                type="submit"
                                role="menuitem"
                                className={menuItemClass}
                            >
                                <LogOut className="size-4" aria-hidden />
                                {signOut.label}
                            </button>
                        </Form>
                    )}
                </div>
            )}
        </div>
    );
}
