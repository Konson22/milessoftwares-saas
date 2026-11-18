import { Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { MovementTable, type MovementPage } from '@/components/movement-table';
import { PeriodFilter, Stat, periodLabel } from '@/components/report-bits';
import { formatMoney } from '@/lib/money';
import { index, movements as movementsRoute, show } from '@/routes/stock';

type Option = { value: string; label: string };

type Props = {
    filters: {
        from: string;
        to: string;
        type: string | null;
        item: string | null;
    };
    movements: MovementPage;
    types: Option[];
    items: Option[];
};

/**
 * Every drink's stock movements at the branch: deliveries, what bills
 * took, counts and write-offs, newest first.
 */
export default function StockMovements({
    filters,
    movements,
    types,
    items,
}: Props) {
    const { branch, currency } = usePage().props;

    return (
        <>
            <Head title="Stock movements" />
            <PageHeader
                title="Stock movements"
                description={`${branch?.name ?? 'This branch'}, ${periodLabel(filters.from, filters.to)}`}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Branch stock</Link>
                    </Button>
                }
            />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <Stat label="Came in" value={String(movements.in)} />
                <Stat label="Went out" value={String(movements.out)} />
                <Stat
                    label="Went out, at cost"
                    value={formatMoney(
                        movements.outCostMinor,
                        currency ?? 'USD',
                    )}
                />
            </div>

            <Panel>
                <PeriodFilter
                    action={movementsRoute.form()}
                    from={filters.from}
                    to={filters.to}
                    selects={[
                        {
                            name: 'type',
                            label: 'What',
                            any: 'Every kind',
                            value: filters.type,
                            options: types,
                        },
                        {
                            name: 'item',
                            label: 'Drink',
                            any: 'Every drink',
                            value: filters.item,
                            options: items,
                        },
                    ]}
                />
                <MovementTable
                    page={movements}
                    itemHref={(movement) => show.url({ item: movement.itemId })}
                />
            </Panel>
        </>
    );
}
