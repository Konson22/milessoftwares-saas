/**
 * Integer minor units to "SSP 1,500.00". Every currency the platform
 * offers has two decimal places.
 */
export function formatMoney(
    minor: number | null | undefined,
    currency?: string | null,
): string {
    if (minor === null || minor === undefined) {
        return '—';
    }

    const amount = (minor / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });

    return currency ? `${currency} ${amount}` : amount;
}

/** Minor units to what a form field shows ("1500.00"). */
export function toInput(minor: number | null | undefined): string {
    return minor === null || minor === undefined
        ? ''
        : (minor / 100).toFixed(2);
}

/** Basis points to a percentage ("8" for 800). */
export function percent(basisPoints: number): string {
    return `${basisPoints / 100}%`;
}
