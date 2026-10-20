import { useMoney } from '@/lib/format';
import { cn } from '@miles/ui/lib/utils';

/**
 * A money amount, right-aligned in tables; blank for zero when asked. In
 * the books' currency unless another is given.
 */
export function Amount({
    minor,
    blankZero = false,
    currency = null,
    className,
}: {
    minor: number;
    blankZero?: boolean;
    currency?: string | null;
    className?: string;
}) {
    const money = useMoney(currency);

    return (
        <span
            className={cn(
                'tabular-nums',
                minor < 0 && 'text-destructive',
                className,
            )}
        >
            {blankZero && minor === 0 ? '' : money(minor)}
        </span>
    );
}
