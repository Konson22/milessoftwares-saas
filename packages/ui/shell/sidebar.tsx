import { Link } from '@inertiajs/react';
import { ArrowLeft, SquareArrowOutUpRight } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '../lib/utils';
import { hrefOf } from './navigation';
import type { ShellHref, ShellNavGroup, ShellNavItem } from './navigation';

const itemClass =
    'relative flex h-9 items-center gap-3 rounded-md px-3 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none';

const tiles = {
    blue: 'bg-sidebar-primary text-sidebar-primary-foreground',
    // The staff console, so it is never mistaken for a customer workspace.
    amber: 'bg-amber-400 text-slate-950',
};

/**
 * The navy sidebar every signed-in app shares: an optional way back (to
 * the workspace), the app's name, and its sections in groups.
 */
export function ShellSidebar({
    label,
    back,
    brand,
    groups,
    active,
    footer,
    onNavigate,
}: {
    /** Accessible name of the navigation. */
    label: string;
    back?: { label: string; href: string };
    brand: {
        title: string;
        subtitle?: string | null;
        icon: LucideIcon;
        href: ShellHref;
        tone?: keyof typeof tiles;
        /** On another host: a full page load. */
        external?: boolean;
    };
    groups: ShellNavGroup[];
    active: ShellNavItem | null;
    footer?: ReactNode;
    onNavigate?: () => void;
}) {
    const BrandIcon = brand.icon;
    const brandClass =
        'flex min-w-0 items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none';
    const brandContent = (
        <>
            <span
                className={cn(
                    'grid size-9 shrink-0 place-items-center rounded-lg shadow-sm',
                    tiles[brand.tone ?? 'blue'],
                )}
            >
                <BrandIcon className="size-5" aria-hidden />
            </span>
            <span className="min-w-0 leading-tight">
                <span className="block truncate font-semibold text-white">
                    {brand.title}
                </span>
                {brand.subtitle && (
                    <span className="block truncate text-xs text-sidebar-foreground/70">
                        {brand.subtitle}
                    </span>
                )}
            </span>
        </>
    );

    return (
        <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
            {/* Same height as the top bar, so the two borders line up. */}
            <div className="flex h-14 shrink-0 items-center border-b border-sidebar-border px-4">
                {brand.external ? (
                    <a href={hrefOf(brand.href)} className={brandClass}>
                        {brandContent}
                    </a>
                ) : (
                    <Link
                        href={brand.href}
                        onClick={onNavigate}
                        className={brandClass}
                    >
                        {brandContent}
                    </Link>
                )}
            </div>

            <nav
                aria-label={label}
                className="flex-1 space-y-5 overflow-y-auto px-2 py-4"
            >
                {back && (
                    <a
                        href={back.href}
                        className="flex items-center gap-1.5 px-3 text-sm text-sidebar-foreground/70 transition-colors hover:text-white"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        {back.label}
                    </a>
                )}
                {groups.map((group) => (
                    <div key={group.title}>
                        {(groups.length > 1 || group.items.some((i) => i.external)) &&
                            group.title !== '' && (
                                <p className="px-3 pb-1.5 text-[11px] font-semibold tracking-wider text-sidebar-foreground/50 uppercase">
                                    {group.title}
                                </p>
                            )}
                        <ul className="space-y-0.5">
                            {group.items.map((item) => (
                                <li key={`${item.title}-${hrefOf(item.href)}`}>
                                    <SidebarItem
                                        item={item}
                                        current={item === active}
                                        onNavigate={onNavigate}
                                    />
                                </li>
                            ))}
                        </ul>
                    </div>
                ))}
            </nav>

            {footer && (
                <div className="border-t border-sidebar-border p-2">
                    {footer}
                </div>
            )}
        </div>
    );
}

function SidebarItem({
    item,
    current,
    onNavigate,
}: {
    item: ShellNavItem;
    current: boolean;
    onNavigate?: () => void;
}) {
    const Icon = item.icon;

    if (item.external) {
        return (
            <a
                href={hrefOf(item.href)}
                className={cn(
                    itemClass,
                    'text-sidebar-foreground hover:bg-white/5 hover:text-white',
                )}
            >
                <Icon
                    className="size-4 shrink-0 text-sidebar-foreground/70"
                    aria-hidden
                />
                <span className="min-w-0 flex-1 truncate">{item.title}</span>
                <SquareArrowOutUpRight
                    className="size-3.5 shrink-0 text-sidebar-foreground/50"
                    aria-hidden
                />
            </a>
        );
    }

    return (
        <Link
            href={item.href}
            onClick={onNavigate}
            aria-current={current ? 'page' : undefined}
            className={cn(
                itemClass,
                current
                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground before:absolute before:inset-y-1.5 before:left-0 before:w-[3px] before:rounded-full before:bg-cyan-400'
                    : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
            )}
        >
            <Icon
                className={cn(
                    'size-4 shrink-0',
                    current ? 'text-cyan-300' : 'text-sidebar-foreground/70',
                )}
                aria-hidden
            />
            <span className="min-w-0 flex-1 truncate">{item.title}</span>
        </Link>
    );
}
