import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { useMoney } from '@/lib/use-money';

type Period = {
    sales: { count: number; total_minor: number; milli: number };
    costMinor: number;
    expensesMinor: number;
    profitMinor: number;
    byNozzle: Record<string, number>;
    byAttendant: Record<string, number>;
    receipts: { number: string; measured: string; totalMinor: number }[];
    shifts: { id: string; number: string; differenceMinor: number | null }[];
    tanks: { code: string; product: string; quantity: string; low: boolean }[];
};

export default function Finance({
    from,
    to,
    period,
    note,
}: {
    from: string;
    to: string;
    period: Period;
    note: string;
}) {
    const money = useMoney();

    return (
        <>
            <Head title="Finance" />
            <PageHeader
                title="Finance"
                description={`${from} to ${to}. ${note}`}
            />
            <Panel>
                <PanelBody className="grid gap-2 text-sm">
                    <p>
                        Sales {money(period.sales.total_minor)} · cost{' '}
                        {money(period.costMinor)} · expenses{' '}
                        {money(period.expensesMinor)}
                    </p>
                    <p>Profit {money(period.profitMinor)}</p>
                    <p>
                        Receipts {period.receipts.length}. Approved shifts{' '}
                        {period.shifts.length}.
                    </p>
                </PanelBody>
            </Panel>
        </>
    );
}
