import { usePage } from '@inertiajs/react';
import { formatMoney } from '@/lib/money';

/**
 * Formats minor units in the workspace currency.
 */
export function useMoney(): (minor: number) => string {
    const { currency } = usePage().props;

    return (minor) => formatMoney(minor, currency ?? 'USD');
}
