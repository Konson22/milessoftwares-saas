import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Boxes } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { index, show } from '@/routes/stock';

type Row = {
    id: string;
    name: string;
    unit: string;
    onHand: number;
    averageCostMinor: number;
    reorderLevel: number;
    low: boolean;
};

type Props = {
    rows: Row[];
    filters: { search: string; filter: 'all' | 'low' };
};

const FILTERS = [
    { value: 'all', label: 'All drinks' },
    { value: 'low', label: 'Running low' },
] as const;

/**
 * Drinks on hand at the branch; dishes are made to order and keep none.
 */
export default function StockIndex({ rows, filters }: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title="Drinks stock" />
            <PageHeader
                title="Drinks stock"
                description={`On hand in ${branch?.name}, at average cost.`}
            />

            <Panel>
                <PanelToolbar className="justify-between gap-3">
                    <nav
                        aria-label="Stock filters"
                        className="flex flex-wrap gap-1"
                    >
                        {FILTERS.map((filter) => (
                            <Button
                                key={filter.value}
                                size="sm"
                                variant={
                                    filters.filter === filter.value
                                        ? 'default'
                                        : 'outline'
                                }
                                asChild
                            >
                                <Link
                                    href={index({
                                        query: {
                                            filter: filter.value,
                                            search: filters.search || undefined,
                                        },
                                    })}
                                >
                                    {filter.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                    <Form {...index.form()} className="flex items-center gap-2">
                        <input
                            type="hidden"
                            name="filter"
                            value={filters.filter}
                        />
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Drink or barcode"
                            aria-label="Search drinks"
                            className="w-64"
                        />
                    </Form>
                </PanelToolbar>

                {rows.length === 0 ? (
                    <EmptyState icon={Boxes} title="No drinks to show" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Drink</TableHead>
                                <TableHead className="text-right">
                                    On hand
                                </TableHead>
                                <TableHead className="text-right">
                                    Reorder at
                                </TableHead>
                                <TableHead className="text-right">
                                    Average cost
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={show(row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.onHand} {row.unit}
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {row.reorderLevel}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(row.averageCostMinor)}
                                    </TableCell>
                                    <TableCell>
                                        {row.low && (
                                            <StatusBadge tone="warning">
                                                Running low
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
