import type { Report } from '@/components/report-header';
import { ReportTabs } from '@/components/report-header';
import {
    cashiers,
    index,
    profitability,
    purchases,
    sales,
    stock,
} from '@/routes/retail/reports';

const REPORTS: Report[] = [
    {
        key: 'overview',
        title: 'Overview',
        route: index,
        exports: [
            { key: 'days', title: 'Sales by day' },
            { key: 'methods', title: 'By payment' },
            { key: 'top', title: 'Best sellers' },
        ],
    },
    {
        key: 'sales',
        title: 'Sales',
        route: sales,
        exports: [
            { key: 'categories', title: 'By category' },
            { key: 'cashiers', title: 'By cashier' },
        ],
    },
    {
        key: 'profitability',
        title: 'Margins',
        route: profitability,
        exports: [{ key: 'items', title: 'By product' }],
    },
    {
        key: 'stock',
        title: 'Stock',
        route: stock,
        exports: [
            { key: 'reorder', title: 'Reorder list' },
            { key: 'slow', title: 'Slow movers' },
        ],
    },
    {
        key: 'purchases',
        title: 'Purchases',
        route: purchases,
        exports: [
            { key: 'suppliers', title: 'By supplier' },
            { key: 'items', title: 'By product' },
            { key: 'deliveries', title: 'Deliveries' },
        ],
    },
    {
        key: 'cashiers',
        title: 'Tills',
        route: cashiers,
        exports: [
            { key: 'cashiers', title: 'By cashier' },
            { key: 'tills', title: 'Tills' },
        ],
    },
];

export type RetailReportKey =
    | 'overview'
    | 'sales'
    | 'profitability'
    | 'stock'
    | 'purchases'
    | 'cashiers';

/**
 * The shop's reports' title, tabs and period.
 */
export function RetailReportHeader({
    current,
    from,
    to,
}: {
    current: RetailReportKey;
    from: string;
    to: string;
}) {
    return (
        <ReportTabs reports={REPORTS} current={current} from={from} to={to} />
    );
}
