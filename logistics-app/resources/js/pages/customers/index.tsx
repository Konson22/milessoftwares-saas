import { Form, Head, Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';
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
import { useMoney } from '@/lib/use-money';
import { create, edit, index } from '@/routes/customers';
import { index as shipments } from '@/routes/shipments';

type Customer = {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    active: boolean;
    openShipments: number;
    balanceMinor: number;
};

type Props = {
    customers: Customer[];
    filters: { search: string };
    canManage: boolean;
};

export default function Customers({ customers, filters, canManage }: Props) {
    const money = useMoney();
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a customer
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Customers" />
            <PageHeader
                title="Customers"
                description="Who you carry for and invoice."
                actions={add}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex gap-2">
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name, phone or email"
                            aria-label="Search customers"
                        />
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {customers.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="No customers yet"
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Customer</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead className="text-right">
                                    Open shipments
                                </TableHead>
                                <TableHead className="text-right">
                                    Owes
                                </TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers.map((customer) => (
                                <TableRow key={customer.id}>
                                    <TableCell>
                                        <Link
                                            href={shipments({
                                                query: {
                                                    customer: customer.id,
                                                },
                                            })}
                                            className="font-medium hover:underline"
                                        >
                                            {customer.name}
                                        </Link>
                                        {!customer.active && (
                                            <StatusBadge className="ml-2">
                                                Inactive
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {[customer.phone, customer.email]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {customer.openShipments}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {customer.balanceMinor > 0
                                            ? money(customer.balanceMinor)
                                            : '—'}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(customer.id)}>
                                                    Edit
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
