/** Currencies without minor units (same list as App\Pharmacy\Money). */
const ZERO_DECIMALS = new Set([
    'BIF',
    'CLP',
    'DJF',
    'GNF',
    'JPY',
    'KMF',
    'KRW',
    'PYG',
    'RWF',
    'UGX',
    'VND',
    'VUV',
    'XAF',
    'XOF',
    'XPF',
]);

/**
 * 1250 minor units → "SSP 12.50".
 */
export function formatMoney(minor: number, currency: string): string {
    const decimals = ZERO_DECIMALS.has(currency) ? 0 : 2;
    const amount = (minor / 10 ** decimals).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });

    return `${currency} ${amount}`;
}
