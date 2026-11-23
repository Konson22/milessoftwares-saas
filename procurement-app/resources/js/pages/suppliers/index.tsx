import { Head, Link, router, useForm } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { SelectField, Status, TextField } from '@/components/fields';
import { index, show, store } from '@/routes/suppliers';

type Props = {
    status: string;
    search: string;
    suppliers: {
        id: string;
        name: string;
        type: string;
        status: string;
        contact: string | null;
        email: string | null;
        phone: string | null;
        categories: string[];
    }[];
    types: string[];
    canManage: boolean;
};

const label = (value: string) =>
    value.charAt(0).toUpperCase() + value.slice(1).replace(/_/g, ' ');

export default function Suppliers({
    status,
    search,
    suppliers,
    types,
    canManage,
}: Props) {
    const [q, setQ] = useState(search);
    const form = useForm({
        name: '',
        type: types[0] ?? 'company',
        contact_person: '',
        email: '',
        phone: '',
        tax_number: '',
    });
    const filter = (next: { status?: string; q?: string }) =>
        router.get(
            index().url,
            { status: next.status ?? status, q: next.q ?? q },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Suppliers" />
            <PageHeader title="Suppliers" />
            <div className="grid gap-6">
                <Panel>
                    <PanelToolbar className="flex flex-wrap items-end gap-4">
                        <SelectField
                            label="Status"
                            value={status}
                            onChange={(v) => filter({ status: v })}
                            options={[
                                { value: 'active', label: 'Active' },
                                { value: 'inactive', label: 'Inactive' },
                                { value: 'suspended', label: 'Suspended' },
                                { value: 'all', label: 'All' },
                            ]}
                            className="w-40"
                        />
                        <form
                            className="flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                filter({ q });
                            }}
                        >
                            <Input
                                aria-label="Search"
                                placeholder="Name or tax number"
                                value={q}
                                onChange={(e) => setQ(e.target.value)}
                                className="w-64"
                            />
                            <Button type="submit" variant="outline">
                                Search
                            </Button>
                        </form>
                    </PanelToolbar>
                    {suppliers.length === 0 ? (
                        <EmptyState icon={Truck} title="No suppliers found" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Contact</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {suppliers.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell>
                                            <Link
                                                href={show(s.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {s.name}
                                            </Link>
                                            {s.categories.length > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {s.categories.join(', ')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{label(s.type)}</TableCell>
                                        <TableCell>
                                            {s.contact ?? '—'}
                                            <span className="block text-xs text-muted-foreground">
                                                {[s.email, s.phone]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <Status value={s.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                {canManage && (
                    <Panel>
                        <PanelHeader
                            title="Add a supplier"
                            description="Names and tax numbers already on the list are refused."
                        />
                        <PanelBody>
                            <form
                                className="grid gap-3 sm:grid-cols-3 sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(store().url);
                                }}
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
                                    error={form.errors.type}
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
                                    label="Contact person"
                                    value={form.data.contact_person}
                                    onChange={(v) =>
                                        form.setData('contact_person', v)
                                    }
                                    error={form.errors.contact_person}
                                />
                                <TextField
                                    label="Email"
                                    type="email"
                                    value={form.data.email}
                                    onChange={(v) => form.setData('email', v)}
                                    error={form.errors.email}
                                />
                                <TextField
                                    label="Phone"
                                    value={form.data.phone}
                                    onChange={(v) => form.setData('phone', v)}
                                    error={form.errors.phone}
                                />
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Add supplier
                                    </Button>
                                </div>
                            </form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
