import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Landmark, Pill, SquareArrowOutUpRight } from 'lucide-react';
import type { NavGroup, NavItem } from '@/lib/navigation';
import { cn } from '@/lib/utils';
import { home } from '@/routes';

const linkClass =
    'relative flex h-9 items-center gap-3 rounded-md px-3 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none text-sidebar-foreground hover:bg-white/5 hover:text-white';

/**
 * Sibling products on the same workspace host (outside Pharmacy).
 */
function sharedProductHref(workspaceUrl: string, prefix: string): string {
    return new URL(prefix, workspaceUrl).toString();
}

/**
 * The navy sidebar: back to the workspace, the product, its sections, and
 * Finance (where the pharmacy's sales, payments and bills are booked).
 */
export function AppSidebar({
    groups,
    active,
    onNavigate,
}: {
    groups: NavGroup[];
    active: NavItem | null;
    onNavigate?: () => void;
}) {
    const { product, workspace } = usePage().props;

    const shared = workspace
        ? [
              {
                  title: 'Finance',
                  href: sharedProductHref(workspace.url, 'finance/'),
                  icon: Landmark,
              },
          ]
        : [];

    return (
        <div className="flex h-full flex-col bg-sidebar text-sidebar-foreground">
            <div className="border-b border-sidebar-border px-4 pt-4 pb-4">
                {workspace && (
                    <a
                        href={workspace.url}
                        className="inline-flex items-center gap-1.5 text-sm text-sidebar-foreground/70 transition-colors hover:text-white"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        {workspace.name} home
                    </a>
                )}
                <Link
                    href={home()}
                    onClick={onNavigate}
                    className="mt-4 flex items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none"
                >
                    <span className="grid size-9 shrink-0 place-items-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground shadow-sm">
                        <Pill className="size-5" aria-hidden />
                    </span>
                    <span className="min-w-0 leading-tight">
                        <span className="block truncate font-semibold text-white">
                            {product.name}
                        </span>
                        {workspace && (
                            <span className="block truncate text-xs text-sidebar-foreground/70">
                                {workspace.name}
                            </span>
                        )}
                    </span>
                </Link>
            </div>

            <nav
                aria-label={product.name}
                className="flex-1 space-y-5 overflow-y-auto px-2 py-4"
            >
                {groups.map((group) => (
                    <div key={group.title}>
                        {groups.length > 1 && (
                            <p className="px-3 pb-1.5 text-[11px] font-semibold tracking-wider text-sidebar-foreground/50 uppercase">
                                {group.title}
                            </p>
                        )}
                        <ul className="space-y-0.5">
                            {group.items.map((item) => {
                                const current = item === active;

                                return (
                                    <li key={item.title}>
                                        <Link
                                            href={item.href}
                                            onClick={onNavigate}
                                            aria-current={
                                                current ? 'page' : undefined
                                            }
                                            className={cn(
                                                'relative flex h-9 items-center gap-3 rounded-md px-3 text-sm transition-colors focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-none',
                                                current
                                                    ? 'bg-sidebar-accent font-medium text-sidebar-accent-foreground before:absolute before:inset-y-1.5 before:left-0 before:w-[3px] before:rounded-full before:bg-cyan-400'
                                                    : 'text-sidebar-foreground hover:bg-white/5 hover:text-white',
                                            )}
                                        >
                                            <item.icon
                                                className={cn(
                                                    'size-4 shrink-0',
                                                    current
                                                        ? 'text-cyan-300'
                                                        : 'text-sidebar-foreground/70',
                                                )}
                                                aria-hidden
                                            />
                                            {item.title}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                ))}

                {shared.length > 0 && (
                    <div>
                        <p className="px-3 pb-1.5 text-[11px] font-semibold tracking-wider text-sidebar-foreground/50 uppercase">
                            Shared
                        </p>
                        <ul className="space-y-0.5">
                            {shared.map((item) => (
                                <li key={item.title}>
                                    <a href={item.href} className={linkClass}>
                                        <item.icon
                                            className="size-4 shrink-0 text-sidebar-foreground/70"
                                            aria-hidden
                                        />
                                        <span className="min-w-0 flex-1 truncate">
                                            {item.title}
                                        </span>
                                        <SquareArrowOutUpRight
                                            className="size-3.5 shrink-0 text-sidebar-foreground/50"
                                            aria-hidden
                                        />
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </nav>
        </div>
    );
}
