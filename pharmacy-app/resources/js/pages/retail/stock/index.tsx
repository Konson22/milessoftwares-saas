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
import { index, show } from '@/routes/retail/stock';

type Row = {
    id: string;
    label: string;
    unit: string;
    sellable: number;
    expired: number;
    held: number;
    nextExpiry: string | null;
    low: boolean;
    expiringSoon: boolean;
    reorderLevel: number;
};

type Props = {
    rows: Row[];
    filters: { search: string; filter: string };
    expiringDays: number;
};

const FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'low', label: 'Low stock' },
    { value: 'expiring', label: 'Expiring soon' },
    { value: 'expired', label: 'Expired' },
    { value: 'held', label: 'On hold' },
];

export default function StockIndex({ rows, filters, expiringDays }: Props) {
    const { branch } = usePage().props;

    return (
        <>
            <Head title="Stock" />
            <PageHeader
                title="Stock"
                description={`Sellable stock in ${branch?.name}. Expiring soon: within ${expiringDays} days.`}
            />

            <Panel>
                <PanelToolbar className="justify-between gap-3">
                    <nav
                        aria-label="Stock filters"
                        className="flex flex-wrap gap-2"
                    >
                        {FILTERS.map((option) => (
                            <Button
                                key={option.value}
                                size="sm"
                                variant={
                                    filters.filter === option.value
                                        ? 'default'
                                        : 'outline'
                                }
                                asChild
                            >
                                <Link
                                    href={index({
                                        query: {
                                            filter: option.value,
                                            search: filters.search || undefined,
                                        },
                                    })}
                                    preserveState
                                >
                                    {option.label}
                                </Link>
                            </Button>
                        ))}
                    </nav>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex gap-2"
                    >
                        <input
                            type="hidden"
                            name="filter"
                            value={filters.filter}
                        />
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Search products"
                            aria-label="Search products"
                        />
                    </Form>
                </PanelToolbar>

                {rows.length === 0 ? (
                    <EmptyState icon={Boxes} title="Nothing to show" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead className="text-right">
                                    Sellable
                                </TableHead>
                                <TableHead>Next expiry</TableHead>
                                <TableHead>Flags</TableHead>
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
                                            {row.label}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.sellable} {row.unit}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {row.nextExpiry ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {row.low && (
                                                <StatusBadge tone="danger">
                                                    Low (reorder at{' '}
                                                    {row.reorderLevel})
                                                </StatusBadge>
                                            )}
                                            {row.expiringSoon && (
                                                <StatusBadge tone="warning">
                                                    Expiring soon
                                                </StatusBadge>
                                            )}
                                            {row.expired > 0 && (
                                                <StatusBadge tone="neutral">
                                                    {row.expired} expired
                                                </StatusBadge>
                                            )}
                                            {row.held > 0 && (
                                                <StatusBadge tone="warning">
                                                    {row.held} on hold
                                                </StatusBadge>
                                            )}
                                        </div>
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
