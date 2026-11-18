import { Form, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';

/** A native select styled like the inputs. */
export const SELECT =
    'h-9 rounded-md border bg-background px-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none';

export function Field({
    id,
    label,
    children,
}: {
    id: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            {children}
        </div>
    );
}

export function Stat({
    label,
    value,
    note,
    tone,
}: {
    label: string;
    value: string;
    note?: ReactNode;
    tone?: 'danger';
}) {
    return (
        <div className="rounded-lg border bg-card p-4">
            <p className="text-sm text-muted-foreground">{label}</p>
            <p
                className={`mt-1 text-2xl font-semibold tabular-nums ${tone === 'danger' ? 'text-red-700' : ''}`}
            >
                {value}
            </p>
            {note && (
                <div className="mt-1 text-xs text-muted-foreground">{note}</div>
            )}
        </div>
    );
}

type Option = { value: string; label: string };

/**
 * A period (from, to), and optionally a branch and other choices, sent as
 * the page's query: the server checks them again.
 */
export function PeriodFilter({
    action,
    from,
    to,
    branches,
    branch,
    selects = [],
}: {
    action: { action: string; method: 'get' };
    from: string;
    to: string;
    branches?: { id: string; name: string }[];
    branch?: string | null;
    selects?: {
        name: string;
        label: string;
        any: string;
        value: string | null;
        options: Option[];
    }[];
}) {
    return (
        <PanelToolbar>
            <Form {...action} className="flex flex-wrap items-end gap-2">
                <Field id="from" label="From">
                    <Input
                        id="from"
                        name="from"
                        type="date"
                        defaultValue={from}
                        className="w-44"
                    />
                </Field>
                <Field id="to" label="To">
                    <Input
                        id="to"
                        name="to"
                        type="date"
                        defaultValue={to}
                        className="w-44"
                    />
                </Field>
                {branches && (
                    <Field id="branch" label="Branch">
                        <select
                            id="branch"
                            name="branch"
                            defaultValue={branch ?? ''}
                            className={`${SELECT} w-48`}
                        >
                            <option value="">All branches</option>
                            {branches.map((option) => (
                                <option key={option.id} value={option.id}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                {selects.map((select) => (
                    <Field
                        key={select.name}
                        id={select.name}
                        label={select.label}
                    >
                        <select
                            id={select.name}
                            name={select.name}
                            defaultValue={select.value ?? ''}
                            className={`${SELECT} w-48`}
                        >
                            <option value="">{select.any}</option>
                            {select.options.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                ))}
                <Button type="submit" variant="outline">
                    Show
                </Button>
            </Form>
        </PanelToolbar>
    );
}

/** Previous and next page links, with where we are. */
export function Pager({
    currentPage,
    lastPage,
    total,
    prev,
    next,
}: {
    currentPage: number;
    lastPage: number;
    total: number;
    prev: string | null;
    next: string | null;
}) {
    if (lastPage <= 1) {
        return null;
    }

    return (
        <div className="flex items-center justify-between gap-2 border-t px-4 py-3 text-sm">
            <span className="text-muted-foreground">
                Page {currentPage} of {lastPage} · {total} in all
            </span>
            <span className="flex gap-2">
                {prev ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={prev} preserveScroll>
                            Previous
                        </Link>
                    </Button>
                ) : null}
                {next ? (
                    <Button asChild size="sm" variant="outline">
                        <Link href={next} preserveScroll>
                            Next
                        </Link>
                    </Button>
                ) : null}
            </span>
        </div>
    );
}

/** "2026-11-01" or "2026-11-01 to 2026-11-07", as a heading says it. */
export function periodLabel(from: string, to: string): string {
    const day = (iso: string) =>
        new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
            dateStyle: 'medium',
        });

    return from === to ? day(from) : `${day(from)} to ${day(to)}`;
}
