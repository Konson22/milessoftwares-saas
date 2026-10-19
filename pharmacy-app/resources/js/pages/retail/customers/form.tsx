import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { index, show, store, update } from '@/routes/retail/customers';

type Customer = {
    id: string;
    number: string;
    name: string;
    phone: string | null;
    email: string | null;
    address: string | null;
    creditLimit: string;
    priceListId: string | null;
    paymentTermsDays: number;
    active: boolean;
};

type Props = {
    customer: Customer | null;
    priceLists: { id: string; name: string; active: boolean }[];
};

const FIELDS = [
    ['name', 'Name', 'text', true],
    ['phone', 'Phone', 'tel', false],
    ['email', 'Email', 'email', false],
    ['address', 'Address', 'text', false],
] as const;

/**
 * Adding or editing an account customer: who they are, how much they may
 * owe, and how long they have to pay.
 */
export default function CustomerForm({ customer, priceLists }: Props) {
    const action = customer ? update.form(customer.id) : store.form();
    const values: Record<string, string | null | undefined> = {
        name: customer?.name,
        phone: customer?.phone,
        email: customer?.email,
        address: customer?.address,
    };

    return (
        <>
            <Head title={customer ? `Edit ${customer.name}` : 'Add customer'} />
            <PageHeader
                title={customer ? `Edit ${customer.name}` : 'Add customer'}
                description={customer?.number}
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                {FIELDS.map(([name, label, type, required]) => (
                                    <div key={name} className="grid gap-1.5">
                                        <Label htmlFor={name}>{label}</Label>
                                        <Input
                                            id={name}
                                            name={name}
                                            type={type}
                                            required={required}
                                            defaultValue={values[name] ?? ''}
                                            aria-invalid={
                                                errors[name] ? true : undefined
                                            }
                                        />
                                        {errors[name] && (
                                            <p className="text-sm text-destructive">
                                                {errors[name]}
                                            </p>
                                        )}
                                    </div>
                                ))}
                                <div className="grid gap-1.5">
                                    <Label htmlFor="credit_limit">
                                        Credit limit (0: pays at once)
                                    </Label>
                                    <Input
                                        id="credit_limit"
                                        name="credit_limit"
                                        inputMode="decimal"
                                        required
                                        defaultValue={
                                            customer?.creditLimit ?? '0'
                                        }
                                        aria-invalid={
                                            errors.credit_limit
                                                ? true
                                                : undefined
                                        }
                                    />
                                    {errors.credit_limit && (
                                        <p className="text-sm text-destructive">
                                            {errors.credit_limit}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="payment_terms_days">
                                        Days to pay
                                    </Label>
                                    <Input
                                        id="payment_terms_days"
                                        name="payment_terms_days"
                                        type="number"
                                        min={0}
                                        max={365}
                                        required
                                        defaultValue={
                                            customer?.paymentTermsDays ?? 30
                                        }
                                    />
                                    {errors.payment_terms_days && (
                                        <p className="text-sm text-destructive">
                                            {errors.payment_terms_days}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor="price_list_id">
                                        Buys at
                                    </Label>
                                    <select
                                        id="price_list_id"
                                        name="price_list_id"
                                        defaultValue={
                                            customer?.priceListId ?? ''
                                        }
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                    >
                                        <option value="">
                                            The shop's own prices
                                        </option>
                                        {priceLists.map((list) => (
                                            <option
                                                key={list.id}
                                                value={list.id}
                                            >
                                                {list.name}
                                                {list.active ? '' : ' (off)'}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.price_list_id && (
                                        <p className="text-sm text-destructive">
                                            {errors.price_list_id}
                                        </p>
                                    )}
                                </div>
                                <div className="sm:col-span-2">
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
                                                customer?.active ?? true
                                            }
                                        />
                                        Active (can buy on account)
                                    </label>
                                </div>
                                <div className="flex gap-3 sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Save
                                    </Button>
                                    <Button variant="ghost" asChild>
                                        <Link
                                            href={
                                                customer
                                                    ? show(customer.id)
                                                    : index()
                                            }
                                        >
                                            Cancel
                                        </Link>
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
