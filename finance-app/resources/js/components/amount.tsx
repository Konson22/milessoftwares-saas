import { useMoney } from '@/lib/format';
import { cn } from '@miles/ui/lib/utils';

/**
 * A money amount, right-aligned in tables; blank for zero when asked.
 */
export function Amount({
    minor,
    blankZero = false,
    className,
}: {
    minor: number;
    blankZero?: boolean;
    className?: string;
}) {
    const money = useMoney();

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
