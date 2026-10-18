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

/** Digits after the point in the currency: 0 for those above, 2 otherwise. */
export function currencyDecimals(currency: string): number {
    return ZERO_DECIMALS.has(currency) ? 0 : 2;
}

/**
 * What was typed in a money field ("1,250.5") in minor units, or null when
 * it is not an amount.
 */
export function parseMoney(value: string, currency: string): number | null {
    const amount = Number.parseFloat(value.replace(/,/g, '').trim());

    return Number.isFinite(amount)
        ? Math.round(amount * 10 ** currencyDecimals(currency))
        : null;
}

/**
 * 1250 minor units → "12.50": a value for a money field, without the
 * currency or thousands separators.
 */
export function toMajor(minor: number, currency: string): string {
    return (minor / 10 ** currencyDecimals(currency)).toFixed(
        currencyDecimals(currency),
    );
}
