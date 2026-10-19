import { Form, Head, Link, usePage } from '@inertiajs/react';
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
import { formatMoney } from '@/lib/money';
import { create, index, show } from '@/routes/retail/customers';

type Customer = {
    id: string;
    number: string;
    name: string;
    phone: string | null;
    creditLimitMinor: number;
    paymentTermsDays: number;
    active: boolean;
    owedMinor: number;
    overdue: boolean;
};

type Props = {
    customers: Customer[];
    filters: { search: string; inactive: boolean };
    canManage: boolean;
};

/**
 * Account customers: who owes what, and who is late.
 */
export default function Customers({ customers, filters, canManage }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const owed = customers.reduce((sum, c) => sum + c.owedMinor, 0);

    return (
        <>
            <Head title="Customers" />
            <PageHeader
                title="Customers"
                description={`Owed by customers: ${money(owed)}`}
                actions={
                    canManage ? (
                        <Button asChild>
                            <Link href={create()}>Add customer</Link>
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
                        title="No customers yet"
                        action={
                            canManage ? (
                                <Button asChild>
                                    <Link href={create()}>Add customer</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Customer</TableHead>
                                <TableHead>Phone</TableHead>
                                <TableHead className="text-right">
                                    Credit limit
                                </TableHead>
                                <TableHead className="text-right">
                                    Owes
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.map((customer) => (
                                <TableRow key={customer.id}>
                                    <TableCell>
                                        <Link
                                            href={show(customer.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {customer.name}
                                        </Link>{' '}
                                        <span className="text-muted-foreground">
                                            {customer.number}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {customer.phone ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {customer.creditLimitMinor === 0
                                            ? 'No credit'
                                            : money(customer.creditLimitMinor)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(customer.owedMinor)}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {customer.overdue && (
                                                <StatusBadge tone="danger">
                                                    Overdue
                                                </StatusBadge>
                                            )}
                                            {!customer.active && (
                                                <StatusBadge tone="neutral">
                                                    Inactive
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
