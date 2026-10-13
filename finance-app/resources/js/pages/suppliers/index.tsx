import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { EmptyState } from '@/components/empty-state';
import { Field } from '@/components/field';
import { PageHeader } from '@/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@/components/panel';
import { SearchField } from '@/components/search-field';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/lib/access';
import { sourceLabel } from '@/lib/format';
import { index, show, store } from '@/routes/suppliers';

type Props = {
    search: string;
    suppliers: {
        id: string;
        name: string;
        email: string | null;
        phone: string | null;
        source: string | null;
        bills: number;
        outstandingMinor: number;
    }[];
};

/**
 * Suppliers and what is owed to each.
 */
export default function Suppliers({ search, suppliers }: Props) {
    const can = useCan();
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title="Suppliers" />
            <PageHeader
                title="Suppliers"
                description="Suppliers from your products are added automatically with their first bill."
                actions={
                    can('finance.payables.manage') && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            New supplier
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-5">
                    <PanelHeader title="New supplier" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="name"
                                        label="Name"
                                        error={errors.name}
                                        className="sm:col-span-2"
                                    >
                                        <Input id="name" name="name" required />
                                    </Field>
                                    <Field
                                        id="email"
                                        label="Email"
                                        error={errors.email}
                                    >
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                        />
                                    </Field>
                                    <Field
                                        id="phone"
                                        label="Phone"
                                        error={errors.phone}
                                    >
                                        <Input id="phone" name="phone" />
                                    </Field>
                                    <div className="flex gap-2 sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add supplier
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setAdding(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} options={{ preserveState: true }}>
                        <SearchField
                            name="search"
                            defaultValue={search}
                            placeholder="Search suppliers"
                            aria-label="Search suppliers"
                        />
                    </Form>
                </PanelToolbar>
                {suppliers.length === 0 && search === '' ? (
                    <EmptyState icon={Truck} title="No suppliers yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead>From</TableHead>
                                <TableHead className="text-right">
                                    Bills
                                </TableHead>
                                <TableHead className="text-right">
                                    Owed
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {suppliers.length === 0 && (
                                <TableEmpty colSpan={5}>
                                    No supplier matches.
                                </TableEmpty>
                            )}
                            {suppliers.map((supplier) => (
                                <TableRow key={supplier.id}>
                                    <TableCell>
                                        <Link
                                            href={show(supplier.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {supplier.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {[supplier.phone, supplier.email]
                                            .filter(Boolean)
                                            .join(' · ') || '—'}
                                    </TableCell>
                                    <TableCell>
                                        {supplier.source ? (
                                            <StatusBadge tone="info">
                                                {sourceLabel(supplier.source)}
                                            </StatusBadge>
                                        ) : (
                                            <StatusBadge>Finance</StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {supplier.bills}
                                    </TableCell>
                                    <TableCell className="text-right font-medium">
                                        <Amount
                                            minor={supplier.outstandingMinor}
                                        />
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
