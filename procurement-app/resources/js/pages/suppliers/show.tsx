import { Head, Link, useForm } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { DocumentsPanel } from '@/components/actions';
import { SelectField, Status, TextArea, TextField } from '@/components/fields';
import type { Documents } from '@/lib/procurement';
import { formatDate, money } from '@/lib/procurement';
import { show as order } from '@/routes/orders';
import { status as setStatus, update } from '@/routes/suppliers';

type Supplier = {
    id: string;
    name: string;
    type: string;
    status: string;
    status_reason: string | null;
    contact_person: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    country: string | null;
    registration_number: string | null;
    tax_number: string | null;
    notes: string | null;
    categories: string[];
};

type Props = {
    supplier: Supplier;
    orders: {
        id: string;
        number: string | null;
        status: string;
        currency: string;
        total: string;
        date: string | null;
    }[];
    quotations: number;
    documents: Documents;
    types: string[];
    canManage: boolean;
};

const label = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');

export default function ShowSupplier({
    supplier,
    orders,
    quotations,
    documents,
    types,
    canManage,
}: Props) {
    const form = useForm({
        name: supplier.name,
        type: supplier.type,
        contact_person: supplier.contact_person ?? '',
        email: supplier.email ?? '',
        phone: supplier.phone ?? '',
        address: supplier.address ?? '',
        country: supplier.country ?? '',
        registration_number: supplier.registration_number ?? '',
        tax_number: supplier.tax_number ?? '',
        categories: supplier.categories.join(', '),
        notes: supplier.notes ?? '',
    });
    const statusForm = useForm({ status: supplier.status, reason: '' });

    return (
        <>
            <Head title={supplier.name} />
            <PageHeader
                title={supplier.name}
                badges={<Status value={supplier.status} />}
            />
            <div className="grid gap-6">
                {supplier.status !== 'active' && supplier.status_reason && (
                    <div className="rounded-md border border-amber-500 bg-background px-3 py-2 text-sm">
                        {label(supplier.status)}: {supplier.status_reason}
                    </div>
                )}

                <Panel>
                    <PanelHeader
                        title="Details"
                        description={`${quotations} quotations recorded · ${orders.length} purchase orders`}
                    />
                    <PanelBody>
                        <form
                            className="grid gap-3 sm:grid-cols-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.transform((data) => ({
                                    ...data,
                                    categories: data.categories
                                        .split(',')
                                        .map((c) => c.trim())
                                        .filter(Boolean),
                                }));
                                form.put(update(supplier.id).url, {
                                    preserveScroll: true,
                                });
                            }}
                        >
                            <fieldset
                                disabled={!canManage}
                                className="contents"
                            >
                                <TextField
                                    label="Name"
                                    value={form.data.name}
                                    onChange={(v) => form.setData('name', v)}
                                    error={form.errors.name}
                                />
                                <SelectField
                                    label="Type"
                                    value={form.data.type}
                                    onChange={(v) => form.setData('type', v)}
                                    options={types.map((t) => ({
                                        value: t,
                                        label: label(t),
                                    }))}
                                />
                                <TextField
                                    label="Categories (comma separated)"
                                    value={form.data.categories}
                                    onChange={(v) =>
                                        form.setData('categories', v)
                                    }
                                />
                                <TextField
                                    label="Contact person"
                                    value={form.data.contact_person}
                                    onChange={(v) =>
                                        form.setData('contact_person', v)
                                    }
                                />
                                <TextField
                                    label="Email"
                                    value={form.data.email}
                                    onChange={(v) => form.setData('email', v)}
                                    error={form.errors.email}
                                />
                                <TextField
                                    label="Phone"
                                    value={form.data.phone}
                                    onChange={(v) => form.setData('phone', v)}
                                />
                                <TextField
                                    label="Registration number"
                                    value={form.data.registration_number}
                                    onChange={(v) =>
                                        form.setData('registration_number', v)
                                    }
                                />
                                <TextField
                                    label="Tax number"
                                    value={form.data.tax_number}
                                    onChange={(v) =>
                                        form.setData('tax_number', v)
                                    }
                                    error={form.errors.tax_number}
                                />
                                <TextField
                                    label="Country (2 letters)"
                                    value={form.data.country}
                                    onChange={(v) =>
                                        form.setData('country', v.toUpperCase())
                                    }
                                    error={form.errors.country}
                                />
                                <TextArea
                                    label="Address"
                                    value={form.data.address}
                                    onChange={(v) => form.setData('address', v)}
                                    rows={2}
                                />
                                <TextArea
                                    label="Notes"
                                    value={form.data.notes}
                                    onChange={(v) => form.setData('notes', v)}
                                    rows={2}
                                    className="sm:col-span-2"
                                />
                            </fieldset>
                            {canManage && (
                                <div className="sm:col-span-3">
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Save
                                    </Button>
                                </div>
                            )}
                        </form>
                    </PanelBody>
                </Panel>

                {canManage && (
                    <Panel>
                        <PanelHeader
                            title="Status"
                            description="Suppliers are never deleted. Suspended or inactive suppliers keep their history but receive nothing new."
                        />
                        <PanelBody>
                            <form
                                className="grid gap-3 sm:grid-cols-3 sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    statusForm.post(
                                        setStatus(supplier.id).url,
                                        { preserveScroll: true },
                                    );
                                }}
                            >
                                <SelectField
                                    label="Status"
                                    value={statusForm.data.status}
                                    onChange={(v) =>
                                        statusForm.setData('status', v)
                                    }
                                    options={[
                                        { value: 'active', label: 'Active' },
                                        {
                                            value: 'inactive',
                                            label: 'Inactive',
                                        },
                                        {
                                            value: 'suspended',
                                            label: 'Suspended',
                                        },
                                    ]}
                                    error={statusForm.errors.status}
                                />
                                <TextField
                                    label="Reason"
                                    value={statusForm.data.reason}
                                    onChange={(v) =>
                                        statusForm.setData('reason', v)
                                    }
                                    error={statusForm.errors.reason}
                                />
                                <div>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={
                                            statusForm.processing ||
                                            statusForm.data.status ===
                                                supplier.status
                                        }
                                    >
                                        Change status
                                    </Button>
                                </div>
                            </form>
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader title="Purchase orders" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Number</TableHead>
                                <TableHead>Date</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="text-muted-foreground"
                                    >
                                        No orders yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {orders.map((o) => (
                                <TableRow key={o.id}>
                                    <TableCell>
                                        <Link
                                            href={order(o.id)}
                                            className="hover:underline"
                                        >
                                            {o.number ?? 'Draft'}
                                        </Link>
                                    </TableCell>
                                    <TableCell>{formatDate(o.date)}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(o.total, o.currency)}
                                    </TableCell>
                                    <TableCell>
                                        <Status value={o.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <DocumentsPanel documents={documents} />
            </div>
        </>
    );
}
