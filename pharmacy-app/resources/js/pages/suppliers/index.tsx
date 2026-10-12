import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            <h1 className="text-2xl font-semibold">Suppliers</h1>

            {canManage && (
                <Form
                    {...store.form()}
                    resetOnSuccess
                    className="mt-6 grid max-w-4xl gap-3 rounded-md border p-4 sm:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <SupplierFields />
                            <div className="flex items-center gap-3 sm:col-span-4">
                                <Button type="submit" disabled={processing}>
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
            )}

            <ul className="mt-6 divide-y rounded-md border">
                {suppliers.length === 0 && (
                    <li className="px-4 py-3 text-sm text-muted-foreground">
                        No suppliers yet.
                    </li>
                )}
                {suppliers.map((supplier) =>
                    editing === supplier.id ? (
                        <li key={supplier.id} className="px-4 py-3">
                            <Form
                                {...update.form(supplier.id)}
                                onSuccess={() => setEditing(null)}
                                className="grid gap-3 sm:grid-cols-4"
                            >
                                {({ processing }) => (
                                    <>
                                        <SupplierFields supplier={supplier} />
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
                                                defaultChecked={supplier.active}
                                            />
                                            Active
                                        </label>
                                        <div className="flex gap-2 sm:col-span-3">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Save
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setEditing(null)}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </li>
                    ) : (
                        <li
                            key={supplier.id}
                            className="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-sm"
                        >
                            <span className="font-medium">{supplier.name}</span>
                            {!supplier.active && (
                                <Badge variant="outline">Inactive</Badge>
                            )}
                            <span className="text-muted-foreground">
                                {[
                                    supplier.phone,
                                    supplier.email,
                                    supplier.address,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                            {canManage && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="ml-auto"
                                    onClick={() => setEditing(supplier.id)}
                                >
                                    Edit
                                </Button>
                            )}
                        </li>
                    ),
                )}
            </ul>
        </>
    );
}
