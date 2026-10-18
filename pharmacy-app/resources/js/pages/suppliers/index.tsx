import { Form, Head } from '@inertiajs/react';
import { Truck } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { store, update } from '@/routes/suppliers';

type Supplier = {
    id: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    active: boolean;
};

type Props = { suppliers: Supplier[]; canManage: boolean };

function SupplierFields({ supplier }: { supplier?: Supplier }) {
    return (
        <>
            {(
                [
                    ['name', 'Name', supplier?.name, true],
                    ['phone', 'Phone', supplier?.phone, false],
                    ['email', 'Email', supplier?.email, false],
                    ['address', 'Address', supplier?.address, false],
                ] as const
            ).map(([name, label, value, required]) => (
                <div key={name} className="grid gap-1.5">
                    <Label htmlFor={`${supplier?.id ?? 'new'}-${name}`}>
                        {label}
                    </Label>
                    <Input
                        id={`${supplier?.id ?? 'new'}-${name}`}
                        name={name}
                        defaultValue={value ?? ''}
                        required={required}
                        type={name === 'email' ? 'email' : 'text'}
                    />
                </div>
            ))}
        </>
    );
}

export default function Suppliers({ suppliers, canManage }: Props) {
    const [editing, setEditing] = useState<string | null>(null);

    return (
        <>
            <Head title="Suppliers" />
            <PageHeader title="Suppliers" />

            {canManage && (
                <Panel className="mb-3">
                    <PanelHeader title="Add supplier" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            resetOnSuccess
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <SupplierFields />
                                    <div className="flex items-center gap-3 sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add supplier
                                        </Button>
                                        {errors.name && (
                                            <p className="text-sm text-destructive">
                                                {errors.name}
                                            </p>
                                        )}
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                {suppliers.length === 0 ? (
                    <EmptyState icon={Truck} title="No suppliers yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Contact</TableHead>
                                <TableHead>Status</TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {suppliers.map((supplier) =>
                                editing === supplier.id ? (
                                    <TableRow key={supplier.id}>
                                        <TableCell
                                            colSpan={canManage ? 4 : 3}
                                            className="p-4"
                                        >
                                            <Form
                                                {...update.form(supplier.id)}
                                                onSuccess={() =>
                                                    setEditing(null)
                                                }
                                                className="grid gap-3 sm:grid-cols-4"
                                            >
                                                {({ processing }) => (
                                                    <>
                                                        <SupplierFields
                                                            supplier={supplier}
                                                        />
                                                        <input
                                                            type="hidden"
                                                            name="active"
                                                            value="0"
                                                        />
                                                        <label className="flex items-center gap-2 text-sm">
                                                            <input
                                                                type="checkbox"
                                                                name="active"
                                                                value="1"
                                                                defaultChecked={
                                                                    supplier.active
                                                                }
                                                            />
                                                            Active
                                                        </label>
                                                        <div className="flex gap-2 sm:col-span-3">
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                Save
                                                            </Button>
                                                            <Button
                                                                type="button"
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    setEditing(
                                                                        null,
                                                                    )
                                                                }
                                                            >
                                                                Cancel
                                                            </Button>
                                                        </div>
                                                    </>
                                                )}
                                            </Form>
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    <TableRow key={supplier.id}>
                                        <TableCell className="font-medium">
                                            {supplier.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {[
                                                supplier.phone,
                                                supplier.email,
                                                supplier.address,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || '—'}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={
                                                    supplier.active
                                                        ? 'success'
                                                        : 'neutral'
                                                }
                                            >
                                                {supplier.active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </StatusBadge>
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setEditing(supplier.id)
                                                    }
                                                >
                                                    Edit
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ),
                            )}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
