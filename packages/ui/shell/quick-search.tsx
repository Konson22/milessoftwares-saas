import { router } from '@inertiajs/react';
import { CornerDownLeft, Search } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { cn } from '../lib/utils';
import { hrefOf } from './navigation';
import type { ShellNavGroup } from './navigation';

type Entry = {
    key: string;
    label: string;
    hint: string;
    icon: LucideIcon;
    url: string;
    external: boolean;
};

/** A search the app offers for the typed term, e.g. "Medicines matching …". */
export type QuickSearchTarget = { key: string; label: string; url: string };

/**
 * Ctrl+K / ⌘K: jump to a section, or run one of the app's searches.
 */
export function QuickSearch({
    groups,
    searches,
    placeholder = 'Go to a section…',
}: {
    groups: ShellNavGroup[];
    searches?: (term: string) => QuickSearchTarget[];
    placeholder?: string;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [selected, setSelected] = useState(0);
    const input = useRef<HTMLInputElement>(null);

    const close = useCallback(() => {
        setOpen(false);
        setQuery('');
        setSelected(0);
    }, []);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();
                setOpen((value) => !value);
            }
        };
        document.addEventListener('keydown', onKey);

        return () => document.removeEventListener('keydown', onKey);
    }, []);

    useEffect(() => {
        if (open) {
            input.current?.focus();
        }
    }, [open]);

    const entries = useMemo<Entry[]>(() => {
        const term = query.trim().toLowerCase();
        const sections: Entry[] = groups.flatMap((group) =>
            group.items
                .filter(
                    (item) =>
                        term === '' ||
                        item.title.toLowerCase().includes(term) ||
                        group.title.toLowerCase().includes(term),
                )
                .map((item) => ({
                    key: `nav:${group.title}:${item.title}`,
                    label: item.title,
                    hint: group.title,
                    icon: item.icon,
                    url: hrefOf(item.href),
                    external: item.external === true,
                })),
        );

        if (term === '' || searches === undefined) {
            return sections;
        }

        return [
            ...sections,
            ...searches(query.trim()).map((target) => ({
                key: `search:${target.key}`,
                label: target.label,
                hint: 'Search',
                icon: Search,
                url: target.url,
                external: false,
            })),
        ];
    }, [groups, query, searches]);

    const go = (entry: Entry | undefined) => {
        if (!entry) {
            return;
        }

        close();

        if (entry.external) {
            window.location.assign(entry.url);
        } else {
            router.visit(entry.url);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => setOpen(true)}
                className="hidden h-8 w-56 items-center gap-2 border bg-background/60 px-2.5 text-sm text-muted-foreground transition-colors hover:bg-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none md:flex"
            >
                <Search className="size-4" aria-hidden />
                <span className="flex-1 text-left">Search…</span>
                <kbd className="rounded border bg-card px-1.5 font-sans text-[11px]">
                    Ctrl
                </kbd>
                <kbd className="rounded border bg-card px-1.5 font-sans text-[11px]">
                    K
                </kbd>
            </button>
            <button
                type="button"
                onClick={() => setOpen(true)}
                aria-label="Search"
                className="grid size-8 place-items-center text-muted-foreground hover:bg-accent md:hidden"
            >
                <Search className="size-4" aria-hidden />
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-50 flex items-start justify-center bg-slate-950/40 px-4 pt-[12vh]"
                    onPointerDown={(event) => {
                        if (event.target === event.currentTarget) {
                            close();
                        }
                    }}
                >
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-label="Search"
                        className="w-full max-w-lg overflow-hidden rounded-lg border bg-popover text-popover-foreground shadow-2xl"
                    >
                        <div className="flex items-center gap-2 border-b px-3">
                            <Search
                                className="size-4 text-muted-foreground"
                                aria-hidden
                            />
                            <input
                                ref={input}
                                value={query}
                                onChange={(event) => {
                                    setQuery(event.target.value);
                                    setSelected(0);
                                }}
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape') {
                                        close();
                                    } else if (event.key === 'ArrowDown') {
                                        event.preventDefault();
                                        setSelected((value) =>
                                            Math.min(
                                                value + 1,
                                                entries.length - 1,
                                            ),
                                        );
                                    } else if (event.key === 'ArrowUp') {
                                        event.preventDefault();
                                        setSelected((value) =>
                                            Math.max(value - 1, 0),
                                        );
                                    } else if (event.key === 'Enter') {
                                        event.preventDefault();
                                        go(entries[selected]);
                                    }
                                }}
                                placeholder={placeholder}
                                aria-label="Search"
                                className="h-10 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                            />
                            <kbd className="rounded border px-1.5 text-[11px] text-muted-foreground">
                                Esc
                            </kbd>
                        </div>
                        <ul
                            role="listbox"
                            aria-label="Results"
                            className="max-h-80 overflow-y-auto p-1"
                        >
                            {entries.length === 0 && (
                                <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                                    Nothing matches.
                                </li>
                            )}
                            {entries.map((entry, position) => (
                                <li
                                    key={entry.key}
                                    role="option"
                                    aria-selected={position === selected}
                                >
                                    <button
                                        type="button"
                                        onClick={() => go(entry)}
                                        onPointerMove={() =>
                                            setSelected(position)
                                        }
                                        className={cn(
                                            'flex w-full items-center gap-3 px-3 py-1.5 text-left text-sm',
                                            position === selected &&
                                                'bg-accent',
                                        )}
                                    >
                                        <entry.icon
                                            className="size-4 shrink-0 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <span className="flex-1 truncate">
                                            {entry.label}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {entry.hint}
                                        </span>
                                        {position === selected && (
                                            <CornerDownLeft
                                                className="size-3.5 text-muted-foreground"
                                                aria-hidden
                                            />
                                        )}
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}
        </>
    );
}
