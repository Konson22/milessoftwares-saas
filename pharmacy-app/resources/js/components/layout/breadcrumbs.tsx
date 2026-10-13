import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { NavGroup, NavItem } from '@/lib/navigation';
import { home } from '@/routes';

/**
 * Where the member is: product, then the section group and item.
 */
export function Breadcrumbs({
    active,
}: {
    active: { group: NavGroup; item: NavItem } | null;
}) {
    const { product } = usePage().props;
    const crumbs: { label: string; href?: string }[] = [
        { label: product.name, href: home().url },
    ];

    if (active) {
        const itemUrl =
            typeof active.item.href === 'string'
                ? active.item.href
                : active.item.href.url;
        const isDashboard = itemUrl.split('?')[0] === home.url();

        if (!isDashboard) {
            crumbs.push({ label: active.group.title });
            crumbs.push({ label: active.item.title });
        }
    }

    return (
        <nav aria-label="Breadcrumb" className="min-w-0">
            <ol className="flex flex-wrap items-center gap-1 text-sm">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;

                    return (
                        <li
                            key={`${crumb.label}-${index}`}
                            className="flex items-center gap-1"
                        >
                            {index > 0 && (
                                <ChevronRight
                                    className="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                            )}
                            {crumb.href && !last ? (
                                <Link
                                    href={crumb.href}
                                    className="truncate text-muted-foreground hover:text-foreground"
                                >
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span
                                    className={
                                        last
                                            ? 'truncate font-medium text-foreground'
                                            : 'truncate text-muted-foreground'
                                    }
                                    aria-current={last ? 'page' : undefined}
                                >
                                    {crumb.label}
                                </span>
                            )}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
