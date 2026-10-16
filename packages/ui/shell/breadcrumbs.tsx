import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import type { Crumb } from './navigation';

/**
 * Where the user is: the app, then the section. The last crumb is the
 * current page.
 */
export function Breadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
    return (
        <nav aria-label="Breadcrumb" className="min-w-0">
            <ol className="flex flex-wrap items-center gap-1 text-sm">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;

                    return (
                        <li
                            key={`${crumb.label}-${index}`}
                            className="flex min-w-0 items-center gap-1"
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
