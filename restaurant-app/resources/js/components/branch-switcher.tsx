import { Link, usePage } from '@inertiajs/react';
import {
    Building2,
    Check,
    ChevronDown,
    SquareArrowOutUpRight,
} from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { useDismiss } from '@miles/ui/lib/use-dismiss';
import { cn } from '@miles/ui/lib/utils';
import { home, overview as management } from '@/routes';

const PILL =
    'inline-flex h-9 max-w-56 items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700';

const ITEM =
    'flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none';

/**
 * Which workspace this tab is in: the branch (with the member's other
 * branches, opened in this tab) or the whole restaurant. Overseers also
 * get management, in a tab of its own.
 */
export function BranchSwitcher() {
    const { branch, branches, overview, area } = usePage().props;
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismiss(ref, open, close);

    if (area !== 'branch' || !branch) {
        return overview ? (
            <span className={PILL}>
                <Building2 className="size-4 shrink-0" aria-hidden />
                <span className="truncate">All branches</span>
            </span>
        ) : null;
    }

    const others = branches.filter((option) => option.id !== branch.id);
    if (others.length === 0 && !overview) {
        return (
            <span className={PILL}>
                <Building2 className="size-4 shrink-0" aria-hidden />
                <span className="truncate">{branch.name}</span>
            </span>
        );
    }

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className={cn(
                    PILL,
                    'transition-colors hover:bg-blue-100 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                )}
            >
                <Building2 className="size-4 shrink-0" aria-hidden />
                <span className="truncate">{branch.name}</span>
                <ChevronDown className="size-4 shrink-0" aria-hidden />
            </button>
            {open && (
                <div
                    role="menu"
                    className="absolute right-0 z-30 mt-1 w-64 rounded-md border bg-popover p-1 text-popover-foreground shadow-lg"
                >
                    <p className="px-2 py-1.5 text-xs font-medium text-muted-foreground">
                        Your branches
                    </p>
                    <span
                        role="menuitemradio"
                        aria-checked
                        className={cn(ITEM, 'font-medium hover:bg-transparent')}
                    >
                        <span className="truncate">{branch.name}</span>
                        <Check className="size-4 text-primary" aria-hidden />
                    </span>
                    {others.map((option) => (
                        <Link
                            key={option.id}
                            href={home({ branch: option.id })}
                            role="menuitemradio"
                            aria-checked={false}
                            onClick={close}
                            className={ITEM}
                        >
                            <span className="truncate">{option.name}</span>
                        </Link>
                    ))}
                    {overview && (
                        <a
                            href={management.url()}
                            target="_blank"
                            rel="noopener noreferrer"
                            role="menuitem"
                            onClick={close}
                            className={cn(ITEM, 'mt-1 border-t pt-2')}
                        >
                            <span className="truncate">
                                Restaurant management
                            </span>
                            <SquareArrowOutUpRight
                                className="size-3.5 text-muted-foreground"
                                aria-hidden
                            />
                        </a>
                    )}
                </div>
            )}
        </div>
    );
}
