import { Form, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronDown } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { useDismiss } from '@/lib/use-dismiss';
import { cn } from '@/lib/utils';
import { update } from '@/routes/branch';

const PILL =
    'inline-flex h-9 max-w-56 items-center gap-2 rounded-md border border-blue-200 bg-blue-50 px-3 text-sm font-medium text-blue-700';

/**
 * The branch being worked in; a menu to switch when more are open.
 */
export function BranchSwitcher() {
    const { branch, branches } = usePage().props;
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismiss(ref, open, close);

    if (!branch) {
        return null;
    }

    if (branches.length < 2) {
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
                        Work in branch
                    </p>
                    {branches.map((option) => (
                        <Form
                            key={option.id}
                            {...update.form()}
                            onSuccess={close}
                        >
                            <input
                                type="hidden"
                                name="branch_id"
                                value={option.id}
                            />
                            <button
                                type="submit"
                                role="menuitemradio"
                                aria-checked={option.id === branch.id}
                                className="flex w-full items-center justify-between gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            >
                                <span className="truncate">{option.name}</span>
                                {option.id === branch.id && (
                                    <Check
                                        className="size-4 text-primary"
                                        aria-hidden
                                    />
                                )}
                            </button>
                        </Form>
                    ))}
                </div>
            )}
        </div>
    );
}
