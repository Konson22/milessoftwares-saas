import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useMoney } from '@/lib/use-money';
import { index } from '@/routes/reports';

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

export default function Reports({
    from,
    to,
    period,
}: {
    from: string;
    to: string;
    period: Period;
}) {
    const money = useMoney();

    return (
        <>
            <Head title="Reports" />
            <PageHeader title="Reports" description={`${from} to ${to}`} />
            <Form {...index.form()} className="mb-4 flex flex-wrap gap-2">
                <Input
                    name="from"
                    type="date"
                    defaultValue={from}
                    aria-label="From"
                />
                <Input
                    name="to"
                    type="date"
                    defaultValue={to}
                    aria-label="To"
                />
                <Button type="submit" variant="outline">
                    Show
                </Button>
            </Form>
            <Panel>
                <PanelBody className="grid gap-3 text-sm">
                    <p>
                        {period.sales.count} sales,{' '}
                        {money(period.sales.total_minor)}. Profit{' '}
                        {money(period.profitMinor)}.
                    </p>
                    <div>
                        <p className="font-medium">By nozzle</p>
                        <ul>
                            {Object.entries(period.byNozzle).map(
                                ([code, amount]) => (
                                    <li key={code}>
                                        {code}: {money(amount)}
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>
                    <div>
                        <p className="font-medium">By attendant</p>
                        <ul>
                            {Object.entries(period.byAttendant).map(
                                ([name, amount]) => (
                                    <li key={name}>
                                        {name}: {money(amount)}
                                    </li>
                                ),
                            )}
                        </ul>
                    </div>
                    <div>
                        <p className="font-medium">Tanks</p>
                        <ul>
                            {period.tanks.map((tank) => (
                                <li key={tank.code}>
                                    {tank.code} {tank.product} {tank.quantity} L
                                    {tank.low ? ' (low)' : ''}
                                </li>
                            ))}
                        </ul>
                    </div>
                </PanelBody>
            </Panel>
        </>
    );
}
