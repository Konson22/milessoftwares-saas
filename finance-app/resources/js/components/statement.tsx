import { Link } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { show as showAccount } from '@/routes/accounts';

export type StatementRow = {
    id: string;
    code: string;
    name: string;
    amountMinor: number;
};

/**
 * One section of a statement (Revenue, Assets, ...): its accounts and total.
 */
export function StatementSection({
    title,
    rows,
    totalLabel,
    totalMinor,
    extra,
}: {
    title: string;
    rows: StatementRow[];
    totalLabel: string;
    totalMinor: number;
    extra?: { label: string; amountMinor: number };
}) {
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
                        <Amount minor={row.amountMinor} />
                    </li>
                ))}
                {extra && (
                    <li className="flex justify-between gap-4 border-t px-4 py-2 italic">
                        <span>{extra.label}</span>
                        <Amount minor={extra.amountMinor} />
                    </li>
                )}
            </ul>
            <p className="flex justify-between gap-4 border-y bg-muted/30 px-4 py-2 text-sm font-semibold">
                <span>{totalLabel}</span>
                <Amount minor={totalMinor} />
            </p>
        </section>
    );
}
