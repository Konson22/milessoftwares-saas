import { Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { MovementTable, type MovementPage } from '@/components/movement-table';
import { PeriodFilter, Stat, periodLabel } from '@/components/report-bits';
import { formatMoney } from '@/lib/money';
import { movements as movementsRoute } from '@/routes/management';

type Option = { value: string; label: string };

type Props = {
    filters: {
        from: string;
        to: string;
        type: string | null;
        item: string | null;
        branch: string | null;
    };
    branchOptions: { id: string; name: string }[];
    movements: MovementPage;
    types: Option[];
    items: Option[];
};

/**
 * Stock movements of every drink across the branches: deliveries, sales,
 * counts and write-offs, newest first. Recorded in each branch.
 */
export default function ManagementMovements({
    filters,
    branchOptions,
    movements,
    types,
    items,
}: Props) {
    const currency = usePage().props.currency ?? 'USD';

    return (
        <>
            <Head title="Stock movements" />
            <PageHeader
                title="Stock movements"
                description={`${filters.branch ? (branchOptions.find((branch) => branch.id === filters.branch)?.name ?? '') : 'All branches'}, ${periodLabel(filters.from, filters.to)}`}
            />

            <div className="mb-4 grid gap-3 sm:grid-cols-3">
                <Stat label="Came in" value={String(movements.in)} />
                <Stat label="Went out" value={String(movements.out)} />
                <Stat
                    label="Went out, at cost"
                    value={formatMoney(movements.outCostMinor, currency)}
                />
            </div>

            <Panel>
                <PeriodFilter
                    action={movementsRoute.form()}
                    from={filters.from}
                    to={filters.to}
                    branches={branchOptions}
                    branch={filters.branch}
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
                <MovementTable page={movements} showBranch />
            </Panel>
        </>
    );
}
