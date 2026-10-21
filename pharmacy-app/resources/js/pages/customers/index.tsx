import { Form, Head, Link } from '@inertiajs/react';
import { Users } from 'lucide-react';
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
import { create, index, show } from '@/routes/customers';

type Customer = {
    id: string;
    number: string;
    name: string;
    phone: string | null;
    active: boolean;
    purchases: number;
    lastBoughtAt: string | null;
};

type Props = {
    customers: Customer[];
    filters: { search: string; inactive: boolean };
    canManage: boolean;
};

/**
 * Registered customers and how often they buy. Customers are optional at
 * the counter; registering one keeps their purchases together.
 */
export default function Customers({ customers, filters, canManage }: Props) {
    return (
        <>
            <Head title="Customers" />
            <PageHeader
                title="Customers"
                description="Registered customers, in every branch"
                actions={
                    canManage ? (
                        <Button asChild>
                            <Link href={create()}>Register customer</Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name, number or phone"
                            aria-label="Search customers"
                            className="max-w-sm"
                        />
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="inactive"
                                value="1"
                                defaultChecked={filters.inactive}
                            />
                            Include inactive
                        </label>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {customers.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title={
                            filters.search
                                ? 'No customer matches'
                                : 'No registered customers yet'
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Phone</TableHead>
                                <TableHead className="text-right">
                                    Purchases
                                </TableHead>
                                <TableHead>Last bought</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.map((customer) => (
                                <TableRow key={customer.id}>
                                    <TableCell className="text-muted-foreground">
                                        {customer.number}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={show(customer.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {customer.name}
                                        </Link>
                                        {!customer.active && (
                                            <StatusBadge
                                                tone="neutral"
                                                className="ml-2"
                                            >
                                                Inactive
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        {customer.phone ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {customer.purchases}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {customer.lastBoughtAt
                                            ? new Date(
                                                  customer.lastBoughtAt,
                                              ).toLocaleDateString()
                                            : '—'}
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
