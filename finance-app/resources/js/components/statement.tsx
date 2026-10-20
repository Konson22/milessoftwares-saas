import { Link } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { show as showAccount } from '@/routes/accounts';

export type StatementRow = {
    id: string;
    code: string;
    name: string;
    amountMinor: number;
    /** The same account in the compared period, when comparing. */
    compareMinor?: number;
};

/**
 * One or two amount columns: this period and, when comparing, the other.
 */
function Amounts({
    minor,
    compareMinor,
    comparing,
}: {
    minor: number;
    compareMinor?: number;
    comparing: boolean;
}) {
    return (
        <span className="flex shrink-0 gap-4">
            <Amount minor={minor} className="w-32 text-right" />
            {comparing && (
                <Amount
                    minor={compareMinor ?? 0}
                    className="w-32 text-right text-muted-foreground"
                />
            )}
        </span>
    );
}

/**
 * The column headings when a statement is compared with another period.
 */
export function StatementHeading({
    current,
    compared,
}: {
    current: string;
    compared: string | null;
}) {
    if (compared === null) {
        return null;
    }

    return (
        <p className="flex justify-end gap-4 border-b px-4 py-2 text-xs font-medium text-muted-foreground">
            <span className="w-32 text-right">{current}</span>
            <span className="w-32 text-right">{compared}</span>
        </p>
    );
}

/**
 * One section of a statement (Revenue, Assets, ...): its accounts and total.
 */
export function StatementSection({
    title,
    rows,
    totalLabel,
    totalMinor,
    compareTotalMinor,
    extra,
}: {
    title: string;
    rows: StatementRow[];
    totalLabel: string;
    totalMinor: number;
    /** Set when comparing: the other period's total. */
    compareTotalMinor?: number;
    extra?: { label: string; amountMinor: number; compareMinor?: number };
}) {
    const comparing = compareTotalMinor !== undefined;

    return (
        <section>
            <h2 className="border-b bg-muted/70 px-4 py-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h2>
            <ul className="text-sm">
                {rows.length === 0 && !extra && (
                    <li className="px-4 py-2 text-muted-foreground">
                        Nothing.
                    </li>
                )}
                {rows.map((row) => (
                    <li
                        key={row.id}
                        className="flex justify-between gap-4 border-b px-4 py-2 last:border-b-0"
                    >
                        <Link
                            href={showAccount(row.id)}
                            className="hover:underline"
                        >
                            <span className="mr-2 font-mono text-xs text-muted-foreground">
                                {row.code}
                            </span>
                            {row.name}
                        </Link>
                        <Amounts
                            minor={row.amountMinor}
                            compareMinor={row.compareMinor}
                            comparing={comparing}
                        />
                    </li>
                ))}
                {extra && (
                    <li className="flex justify-between gap-4 border-t px-4 py-2 italic">
                        <span>{extra.label}</span>
                        <Amounts
                            minor={extra.amountMinor}
                            compareMinor={extra.compareMinor}
                            comparing={comparing}
                        />
                    </li>
                )}
            </ul>
            <p className="flex justify-between gap-4 border-y bg-muted/30 px-4 py-2 text-sm font-semibold">
                <span>{totalLabel}</span>
                <Amounts
                    minor={totalMinor}
                    compareMinor={compareTotalMinor}
                    comparing={comparing}
                />
            </p>
        </section>
    );
}

/**
 * A statement's bottom line, with the compared period's when comparing.
 */
export function StatementTotal({
    label,
    minor,
    compareMinor,
}: {
    label: string;
    minor: number;
    compareMinor?: number;
}) {
    return (
        <p className="flex justify-between gap-4 px-4 py-3 text-base font-semibold">
            <span>{label}</span>
            <Amounts
                minor={minor}
                compareMinor={compareMinor}
                comparing={compareMinor !== undefined}
            />
        </p>
    );
}
