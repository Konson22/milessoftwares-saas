import type { Tone } from '@miles/ui/components/status-badge';

/*
 * What every M&E page shares: the shapes the server sends and how figures
 * and statuses read. Values arrive as exact decimal strings ("1250.000000")
 * and are only rounded here, for display.
 */

export type Option = { value: string; label: string };

export type Measure =
    | 'count'
    | 'number'
    | 'percentage'
    | 'rate'
    | 'ratio'
    | 'currency'
    | 'yes_no';

export type IndicatorSummary = {
    id: string;
    code: string;
    name: string;
    resultCode: string;
    type: string;
    measure: Measure;
    unitLabel: string | null;
    decimals: number;
    direction: 'increase' | 'decrease' | 'maintain';
    frequency: string;
    version: number;
    status: string;
};

export type Actual = {
    value: string | null;
    numerator: string | null;
    denominator: string | null;
    complete: boolean;
    reported: number;
    expected: number;
    mixedVersions?: boolean;
};

export type Target = {
    value: string | null;
    min: string | null;
    max: string | null;
};

export type PerformanceStatus =
    | 'on_track'
    | 'needs_attention'
    | 'off_track'
    | 'no_target'
    | 'no_data';

export type Assessment = {
    status: PerformanceStatus;
    achievement: string | null;
    progress: string | null;
};

export type Cell = {
    periodId: string;
    period: string;
    endsOn: string;
    target: Target | null;
    actual: Actual | null;
    assessment: Assessment;
};

export type PerformanceRow = {
    indicator: IndicatorSummary;
    baseline: { value: string; measuredOn: string } | null;
    periods: Cell[];
    toDate: {
        value: string | null;
        numerator: string | null;
        denominator: string | null;
        complete: boolean;
    } | null;
};

export type SubmissionStatus =
    | 'draft'
    | 'submitted'
    | 'under_review'
    | 'rejected'
    | 'verified'
    | 'published'
    | 'superseded';

export const MEASURES: Record<Measure, string> = {
    count: 'Count',
    number: 'Number (score, average)',
    percentage: 'Percentage',
    rate: 'Rate (per 1,000 and the like)',
    ratio: 'Ratio',
    currency: 'Amount of money',
    yes_no: 'Yes or no',
};

export const AGGREGATIONS: Record<string, string> = {
    sum: 'Add them up',
    latest: 'Take the latest',
    max: 'Take the highest',
    min: 'Take the lowest',
    recompute: 'Recompute from the parts',
    none: 'Do not combine',
};

export const DIRECTIONS: Record<string, string> = {
    increase: 'Higher is better',
    decrease: 'Lower is better',
    maintain: 'Keep within a range',
};

export const TYPES: Record<string, string> = {
    input: 'Input',
    process: 'Process',
    output: 'Output',
    outcome: 'Outcome',
    impact: 'Impact',
};

export const FREQUENCIES: Record<string, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    semiannual: 'Every six months',
    annual: 'Yearly',
    custom: 'Custom periods',
};

export const BREAKDOWN_RULES: Record<string, string> = {
    must_equal_total: 'Must add up to the total',
    at_most_total: 'May be less than the total',
    none: 'Not checked against the total',
};

export function isFraction(measure: Measure): boolean {
    return (
        measure === 'percentage' || measure === 'rate' || measure === 'ratio'
    );
}

/**
 * Rounds an exact decimal string half up to `places` without floats.
 */
export function roundDecimal(value: string, places: number): string {
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = (negative ? value.slice(1) : value).split(
        '.',
    );
    const padded = fraction.padEnd(places + 1, '0');
    // The value × 10^places, cut, then rounded on the next digit.
    let scaled = BigInt((whole || '0') + padded.slice(0, places));

    if (Number(padded.charAt(places)) >= 5) {
        scaled += 1n;
    }

    const digits = scaled.toString().padStart(places + 1, '0');
    const integer = digits.slice(0, digits.length - places);
    const decimals = digits.slice(digits.length - places);
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const sign = negative && scaled !== 0n ? '-' : '';

    return `${sign}${grouped}${decimals ? `.${decimals}` : ''}`;
}

/**
 * A figure as people read it: "1,250 people", "42.5%", "Yes".
 */
export function formatFigure(
    value: string | null | undefined,
    indicator: Pick<IndicatorSummary, 'measure' | 'decimals' | 'unitLabel'>,
    { withUnit = true }: { withUnit?: boolean } = {},
): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    if (indicator.measure === 'yes_no') {
        return Number(value) >= 1 ? 'Yes' : 'No';
    }

    const number = roundDecimal(value, indicator.decimals);

    if (indicator.measure === 'percentage') {
        return `${number}%`;
    }

    return withUnit && indicator.unitLabel
        ? `${number} ${indicator.unitLabel}`
        : number;
}

/** A plain decimal, for counts and percentages of target. */
export function formatNumber(value: string | null, places = 0): string {
    return value === null ? '—' : roundDecimal(value, places);
}

export function formatTarget(
    target: Target | null,
    indicator: Pick<IndicatorSummary, 'measure' | 'decimals' | 'unitLabel'>,
): string {
    if (target === null) {
        return '—';
    }

    if (target.min !== null || target.max !== null) {
        return `${formatFigure(target.min, indicator, { withUnit: false })} to ${formatFigure(target.max, indicator)}`;
    }

    return formatFigure(target.value, indicator);
}

export const PERFORMANCE: Record<
    PerformanceStatus,
    { label: string; tone: Tone }
> = {
    on_track: { label: 'On track', tone: 'success' },
    needs_attention: { label: 'Needs attention', tone: 'warning' },
    off_track: { label: 'Off track', tone: 'danger' },
    no_target: { label: 'No target', tone: 'neutral' },
    no_data: { label: 'No data', tone: 'neutral' },
};

export const SUBMISSION: Record<
    SubmissionStatus,
    { label: string; tone: Tone }
> = {
    draft: { label: 'Draft', tone: 'neutral' },
    submitted: { label: 'Submitted', tone: 'info' },
    under_review: { label: 'Under review', tone: 'info' },
    rejected: { label: 'Sent back', tone: 'danger' },
    verified: { label: 'Verified', tone: 'success' },
    published: { label: 'Published', tone: 'success' },
    superseded: { label: 'Replaced', tone: 'neutral' },
};

export function formatDate(date: string | null | undefined): string {
    if (!date) {
        return '—';
    }

    const parsed = new Date(date.length === 10 ? `${date}T00:00:00` : date);

    return parsed.toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

export function formatDateTime(date: string | null | undefined): string {
    if (!date) {
        return '—';
    }

    return new Date(date).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function formatBytes(size: number): string {
    if (size < 1024) {
        return `${size} B`;
    }

    return size < 1024 * 1024
        ? `${Math.round(size / 1024)} KB`
        : `${(size / 1024 / 1024).toFixed(1)} MB`;
}

export const PROGRAMME_STATUS: Record<string, { label: string; tone: Tone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    active: { label: 'Active', tone: 'success' },
    closed: { label: 'Closed', tone: 'neutral' },
};

export const UNIT_KINDS: Record<string, string> = {
    project: 'Project',
    site: 'Site',
    partner: 'Partner',
    other: 'Other',
};

export const INDICATOR_STATUS: Record<
    'draft' | 'active' | 'retired',
    { label: string; tone: Tone }
> = {
    draft: { label: 'Draft', tone: 'neutral' },
    active: { label: 'Active', tone: 'success' },
    retired: { label: 'Retired', tone: 'neutral' },
};

export type IndicatorDefinition = {
    version: number;
    name: string;
    definition: string | null;
    type: string;
    measure: Measure;
    unitLabel: string | null;
    decimals: number;
    numeratorLabel: string | null;
    denominatorLabel: string | null;
    rateMultiplier: number | null;
    calculationMethod: string | null;
    direction: 'increase' | 'decrease' | 'maintain';
    timeAggregation: string;
    unitAggregation: string;
    dataSource: string | null;
    collectionMethod: string | null;
    meansOfVerification: string | null;
    breakdowns: {
        id: string;
        dimensionId: string;
        secondDimensionId: string | null;
        label: string;
        rule: string;
    }[];
};

/** "12.500000" → "12.5", "3.000000" → "3", for editing a stored value. */
export function trimZeros(value: string): string {
    return value.includes('.')
        ? value.replace(/0+$/, '').replace(/\.$/, '')
        : value;
}
