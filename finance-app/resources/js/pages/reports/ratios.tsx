import { Head } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Amount } from '@/components/amount';
import { PeriodFilter } from '@/components/period-filter';
import { ReportActions } from '@/components/report-actions';
import { ratios as ratiosRoute } from '@/routes/reports';

type Report = {
    from: string;
    to: string;
    days: number;
    figures: Record<string, number>;
    ratios: Record<string, number | null>;
};

type Props = { from: string; to: string; report: Report; compare: Report };

type Unit = 'times' | 'percent' | 'days';

type Definition = {
    key: string;
    label: string;
    unit: Unit;
    explain: string;
    /** Whether a higher value is better (for the trend arrow). */
    higherIsBetter: boolean;
    /** A common rule of thumb, as a tone for the value. */
    health?: (value: number) => Tone;
};

const GROUPS: { title: string; description: string; items: Definition[] }[] = [
    {
        title: 'Liquidity',
        description:
            'Can the organization pay what falls due in the next year? On the last day of the period.',
        items: [
            {
                key: 'current',
                label: 'Current ratio',
                unit: 'times',
                explain: 'Current assets ÷ current liabilities',
                higherIsBetter: true,
                health: (v) =>
                    v >= 1.5 ? 'success' : v >= 1 ? 'warning' : 'danger',
            },
            {
                key: 'quick',
                label: 'Quick ratio',
                unit: 'times',
                explain: 'Current assets less inventory ÷ current liabilities',
                higherIsBetter: true,
                health: (v) =>
                    v >= 1 ? 'success' : v >= 0.7 ? 'warning' : 'danger',
            },
            {
                key: 'cash',
                label: 'Cash ratio',
                unit: 'times',
                explain: 'Bank and cash ÷ current liabilities',
                higherIsBetter: true,
            },
        ],
    },
    {
        title: 'Profitability',
        description: "The period's results against its sales and resources.",
        items: [
            {
                key: 'grossMargin',
                label: 'Gross margin',
                unit: 'percent',
                explain: 'Revenue less cost of sales ÷ revenue',
                higherIsBetter: true,
            },
            {
                key: 'netMargin',
                label: 'Net margin',
                unit: 'percent',
                explain: 'Net profit ÷ revenue',
                higherIsBetter: true,
                health: (v) => (v > 0 ? 'success' : 'danger'),
            },
            {
                key: 'returnOnAssets',
                label: 'Return on assets',
                unit: 'percent',
                explain: 'Net profit ÷ total assets (not annualised)',
                higherIsBetter: true,
            },
            {
                key: 'returnOnEquity',
                label: 'Return on equity',
                unit: 'percent',
                explain: 'Net profit ÷ equity (not annualised)',
                higherIsBetter: true,
            },
        ],
    },
    {
        title: 'Leverage',
        description:
            'How much of the organization is financed by what it owes.',
        items: [
            {
                key: 'debtToEquity',
                label: 'Debt to equity',
                unit: 'times',
                explain: 'Total liabilities ÷ equity',
                higherIsBetter: false,
                health: (v) =>
                    v <= 1 ? 'success' : v <= 2 ? 'warning' : 'danger',
            },
            {
                key: 'debtRatio',
                label: 'Debt ratio',
                unit: 'percent',
                explain: 'Total liabilities ÷ total assets',
                higherIsBetter: false,
            },
        ],
    },
    {
        title: 'Efficiency',
        description:
            "Balances on the last day in days of the period's activity.",
        items: [
            {
                key: 'receivableDays',
                label: 'Days sales outstanding',
                unit: 'days',
                explain: 'Receivable ÷ revenue × days',
                higherIsBetter: false,
            },
            {
                key: 'payableDays',
                label: 'Days payables outstanding',
                unit: 'days',
                explain: 'Payable ÷ cost of sales (or expenses) × days',
                higherIsBetter: true,
            },
            {
                key: 'inventoryDays',
                label: 'Days inventory on hand',
                unit: 'days',
                explain: 'Inventory ÷ cost of sales × days',
                higherIsBetter: false,
            },
        ],
    },
];

const FIGURES: { key: string; label: string }[] = [
    { key: 'revenueMinor', label: 'Revenue' },
    { key: 'costOfSalesMinor', label: 'Cost of sales' },
    { key: 'profitMinor', label: 'Net profit' },
    { key: 'currentAssetsMinor', label: 'Current assets' },
    { key: 'currentLiabilitiesMinor', label: 'Current liabilities' },
    { key: 'assetsMinor', label: 'Total assets' },
    { key: 'liabilitiesMinor', label: 'Total liabilities' },
    { key: 'equityMinor', label: 'Equity' },
];

function format(value: number | null, unit: Unit): string {
    if (value === null) {
        return '—';
    }

    const number = value.toLocaleString(undefined, {
        minimumFractionDigits: unit === 'times' ? 2 : 1,
        maximumFractionDigits: unit === 'times' ? 2 : 1,
    });

    return unit === 'times'
        ? `${number}×`
        : unit === 'percent'
          ? `${number}%`
          : `${number} days`;
}

/**
 * Financial ratios: liquidity, profitability, leverage and efficiency,
 * next to the period before.
 */
export default function Ratios({ from, to, report, compare }: Props) {
    return (
        <>
            <Head title="Financial ratios" />
            <PageHeader
                title="Financial ratios"
                description={`${from} to ${to}, against ${compare.from} to ${compare.to}. Current or not follows each account's place in the cash flow statement.`}
            />
            <Panel className="mb-3">
                <PanelToolbar>
                    <PeriodFilter
                        form={ratiosRoute.form()}
                        from={from}
                        to={to}
                    />
                    <ReportActions
                        route={ratiosRoute}
                        query={{ from, to }}
                        exports={[{ key: 'ratios', title: 'Ratios' }]}
                    />
                </PanelToolbar>
            </Panel>

            <div className="grid gap-3 lg:grid-cols-2">
                {GROUPS.map((group) => (
                    <Panel key={group.title}>
                        <PanelHeader
                            title={group.title}
                            description={group.description}
                        />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Ratio</TableHead>
                                    <TableHead className="text-right">
                                        This period
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Before
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {group.items.map((item) => {
                                    const value = report.ratios[item.key];
                                    const before = compare.ratios[item.key];
                                    const better =
                                        value !== null &&
                                        before !== null &&
                                        value !== before
                                            ? value > before ===
                                              item.higherIsBetter
                                            : null;

                                    return (
                                        <TableRow key={item.key}>
                                            <TableCell>
                                                <div className="font-medium">
                                                    {item.label}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {item.explain}
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                <span className="inline-flex items-center gap-1">
                                                    {better === true && (
                                                        <ArrowUpRight
                                                            className="size-4 text-emerald-600"
                                                            aria-label="Better than before"
                                                        />
                                                    )}
                                                    {better === false && (
                                                        <ArrowDownRight
                                                            className="size-4 text-red-600"
                                                            aria-label="Worse than before"
                                                        />
                                                    )}
                                                    {value !== null &&
                                                    item.health ? (
                                                        <StatusBadge
                                                            tone={item.health(
                                                                value,
                                                            )}
                                                        >
                                                            {format(
                                                                value,
                                                                item.unit,
                                                            )}
                                                        </StatusBadge>
                                                    ) : (
                                                        format(value, item.unit)
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-right text-muted-foreground tabular-nums">
                                                {format(before, item.unit)}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </Panel>
                ))}
            </div>

            <Panel className="mt-3">
                <PanelHeader
                    title="Figures used"
                    description="From the profit and loss for the period and the balance sheet on its last day."
                />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Figure</TableHead>
                            <TableHead className="text-right">
                                This period
                            </TableHead>
                            <TableHead className="text-right">Before</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {FIGURES.map((figure) => (
                            <TableRow key={figure.key}>
                                <TableCell>{figure.label}</TableCell>
                                <TableCell className="text-right">
                                    <Amount
                                        minor={report.figures[figure.key] ?? 0}
                                    />
                                </TableCell>
                                <TableCell className="text-right text-muted-foreground">
                                    <Amount
                                        minor={compare.figures[figure.key] ?? 0}
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
