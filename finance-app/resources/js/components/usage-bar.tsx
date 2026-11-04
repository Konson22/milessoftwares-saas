import { Amount } from '@/components/amount';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { useMoney } from '@/lib/format';

/**
 * Spending against its plan: one bar, the figures beside it, and a label
 * (not just a colour) when it is over.
 */
export function UsageBar({
    label,
    actualMinor,
    budgetMinor,
    percent,
}: {
    label: string;
    actualMinor: number;
    budgetMinor: number;
    percent: number | null;
}) {
    const money = useMoney();
    const over = percent === null ? actualMinor > 0 : percent > 100;

    return (
        <div
            className="grid gap-1 text-sm"
            title={`${label}: ${money(actualMinor)} of ${money(budgetMinor)}`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="truncate">{label}</span>
                <span className="flex items-center gap-2 tabular-nums">
                    {over && (
                        <StatusBadge tone="danger">Over budget</StatusBadge>
                    )}
                    <Amount minor={actualMinor} className="font-medium" />
                    <span className="text-muted-foreground">
                        of {money(budgetMinor)}
                        {percent !== null && ` (${percent}%)`}
                    </span>
                </span>
            </div>
            <div className="h-2 rounded-sm bg-muted">
                <div
                    className={`h-2 rounded-sm ${over ? 'bg-destructive' : 'bg-primary'}`}
                    style={{
                        width: `${percent === null ? (over ? 100 : 0) : Math.min(percent, 100)}%`,
                    }}
                />
            </div>
        </div>
    );
}
