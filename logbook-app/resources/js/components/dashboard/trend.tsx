import { ArrowDown, ArrowUp } from 'lucide-react';

/** A change against the period before: an arrow, the difference, the comparison. */
export function Trend({
    now,
    before,
    upIsGood = false,
    suffix,
}: {
    now: number;
    before: number;
    upIsGood?: boolean;
    suffix: string;
}) {
    const change = now - before;
    if (change === 0) {
        return <span>No change {suffix}</span>;
    }

    const good = change > 0 === upIsGood;
    const percent = upIsGood && before > 0;
    const shown = percent
        ? `${change > 0 ? '+' : ''}${Math.round((change / before) * 100)}%`
        : String(Math.abs(change));
    const Arrow = change > 0 ? ArrowUp : ArrowDown;

    return (
        <span className="inline-flex flex-wrap items-center gap-1">
            <span
                className={`inline-flex items-center gap-0.5 font-medium ${
                    good ? 'text-[#137a4b]' : 'text-[#c01c1c]'
                }`}
            >
                <Arrow className="size-3.5" aria-hidden />
                {shown}
            </span>
            {suffix}
        </span>
    );
}

/** "14 – 20 Nov 2026". */
export function formatRange(from: string, to: string): string {
    const a = new Date(from);
    const b = new Date(to);
    const sameMonth =
        a.getMonth() === b.getMonth() && a.getFullYear() === b.getFullYear();
    const start = a.toLocaleDateString(
        undefined,
        sameMonth ? { day: 'numeric' } : { day: 'numeric', month: 'short' },
    );
    const end = b.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });

    return `${start} – ${end}`;
}
