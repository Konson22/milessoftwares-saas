import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { useMoney } from '@/lib/use-money';
import { create, show } from '@/routes/sales';

type Sale = {
    id: string;
    number: string;
    status: string;
    product: string;
    nozzle: string;
    attendant: string;
    litres: string;
    totalMinor: number;
};

export default function Sales({
    sales,
    canSell,
}: {
    sales: Sale[];
    canSell: boolean;
}) {
    const money = useMoney();

    return (
        <>
            <Head title="Sales" />
            <PageHeader
                title="Sales"
                description="A sale takes litres from the connected tank and posts to Finance once."
                actions={
                    canSell ? (
                        <Button asChild>
                            <Link href={create()}>New sale</Link>
                        </Button>
                    ) : undefined
                }
            />
            <Panel>
                <PanelBody>
                    <ul className="grid gap-2 text-sm">
                        {sales.map((sale) => (
                            <li
                                key={sale.id}
                                className="flex flex-wrap items-center gap-2"
                            >
                                <Link
                                    href={show(sale.id)}
                                    className="underline"
                                >
                                    {sale.number}
                                </Link>
                                <span>
                                    {sale.litres} L {sale.product} ·{' '}
                                    {sale.nozzle} · {sale.attendant}
                                </span>
                                <span>{money(sale.totalMinor)}</span>
                                <StatusBadge
                                    tone={
                                        sale.status === 'posted'
                                            ? 'success'
                                            : 'danger'
                                    }
                                >
                                    {sale.status}
                                </StatusBadge>
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
