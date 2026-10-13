import { Form, usePage } from '@inertiajs/react';
import { ArrowLeft, ChevronDown, LogOut } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import { useDismiss } from '@/lib/use-dismiss';
import { logout } from '@/routes/platform';

function initials(name: string): string {
    const parts = name.trim().split(/\s+/);

    return (
        (parts[0]?.[0] ?? '') + (parts.length > 1 ? parts.at(-1)![0] : '')
    ).toUpperCase();
}

/**
 * Who is signed in, for which workspace; signing out of the pharmacy.
 */
export function UserMenu() {
    const { user, workspace } = usePage().props;
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLDivElement>(null);
    const close = useCallback(() => setOpen(false), []);
    useDismiss(ref, open, close);

    if (!user) {
        return null;
    }

    return (
        <div ref={ref} className="relative">
            <button
                type="button"
                aria-haspopup="menu"
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
                className="flex items-center gap-2 rounded-md px-1.5 py-1 text-left transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <span className="grid size-8 shrink-0 place-items-center rounded-full bg-blue-100 text-xs font-semibold text-blue-700">
                    {initials(user.name)}
                </span>
                <span className="hidden min-w-0 leading-tight sm:block">
                    <span className="block max-w-36 truncate text-sm font-medium">
                        {user.name}
                    </span>
                    {workspace && (
                        <span className="block max-w-36 truncate text-xs text-muted-foreground">
                            {workspace.name}
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
                    <div className="my-1 h-px bg-border" />
                    {workspace && (
                        <a
                            href={workspace.url}
                            role="menuitem"
                            className="flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            {workspace.name} home
                        </a>
                    )}
                    <Form {...logout.form()}>
                        <button
                            type="submit"
                            role="menuitem"
                            className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                        >
                            <LogOut className="size-4" aria-hidden />
                            Sign out of the pharmacy
                        </button>
                    </Form>
                </div>
            )}
        </div>
    );
}
