import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Minus, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { store } from '@/routes/sales';
import { show as showTill, store as openTill } from '@/routes/tills';

type Medicine = {
    id: string;
    label: string;
    genericName: string;
    barcode: string | null;
    unit: string;
    priceMinor: number;
    available: number;
    prescriptionOnly: boolean;
};

type Props = {
    medicines: Medicine[];
    paymentMethods: { value: string; label: string }[];
    /** 0 when this member may not give discounts. */
    maxDiscountPercent: number;
    /** The seller's open till here; nothing is sold without one. */
    till: { id: string; number: string } | null;
};

type BasketLine = { medicine: Medicine; quantity: number; discount: number };

const lineTotal = (line: BasketLine) => {
    const gross = line.quantity * line.medicine.priceMinor;

    return gross - Math.round((gross * line.discount) / 100);
};

/**
 * The counter: find medicines, build the basket, take payment. Prices,
 * discounts and stock are checked again on the server. Each basket is sent
 * with its own key, so a double click or a retry records one sale.
 */
export default function Counter({
    medicines,
    paymentMethods,
    maxDiscountPercent,
    till,
}: Props) {
    const { currency, branch } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const decimals = money(0).includes('.') ? 2 : 0;

    const [search, setSearch] = useState('');
    const [basket, setBasket] = useState<BasketLine[]>([]);
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
    const [tendered, setTendered] = useState('');
    const [submissionId, setSubmissionId] = useState(() => crypto.randomUUID());

    const matches = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (term === '') {
            return [];
        }

        return medicines
            .filter(
                (m) =>
                    m.label.toLowerCase().includes(term) ||
                    m.genericName.toLowerCase().includes(term) ||
                    m.barcode === search.trim(),
            )
            .slice(0, 8);
    }, [medicines, search]);

    const total = basket.reduce((sum, line) => sum + lineTotal(line), 0);
    const tenderedMinor = Math.round(
        Number.parseFloat(tendered || '0') * 10 ** decimals,
    );
    const change =
        method === 'cash' && tendered !== '' ? tenderedMinor - total : null;

    const add = (medicine: Medicine) => {
        setBasket((lines) => {
            const existing = lines.find(
                (line) => line.medicine.id === medicine.id,
            );
            if (existing) {
                return lines.map((line) =>
                    line.medicine.id === medicine.id
                        ? {
                              ...line,
                              quantity: Math.min(
                                  line.quantity + 1,
                                  medicine.available,
                              ),
                          }
                        : line,
                );
            }

            return [...lines, { medicine, quantity: 1, discount: 0 }];
        });
        setSearch('');
    };

    const setQuantity = (id: string, quantity: number) =>
        setBasket((lines) =>
            lines.map((line) =>
                line.medicine.id === id
                    ? {
                          ...line,
                          quantity: Math.max(
                              1,
                              Math.min(quantity, line.medicine.available),
                          ),
                      }
                    : line,
            ),
        );

    const setDiscount = (id: string, discount: number) =>
        setBasket((lines) =>
            lines.map((line) =>
                line.medicine.id === id
                    ? {
                          ...line,
                          discount: Math.max(
                              0,
                              Math.min(
                                  Number.isFinite(discount) ? discount : 0,
                                  maxDiscountPercent,
                              ),
                          ),
                      }
                    : line,
            ),
        );

    if (till === null) {
        return (
            <>
                <Head title="Counter" />
                <PageHeader title="Counter" description={branch?.name} />
                <Panel className="max-w-md">
                    <PanelHeader title="Open your till first" />
                    <PanelBody>
                        <Form
                            {...openTill.form()}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="opening_float">
                                            Cash in the drawer to start
                                        </Label>
                                        <Input
                                            id="opening_float"
                                            name="opening_float"
                                            inputMode="decimal"
                                            defaultValue="0"
                                            className="w-44"
                                        />
                                        {errors.opening_float && (
                                            <p className="text-sm text-destructive">
                                                {errors.opening_float}
                                            </p>
                                        )}
                                    </div>
                                    <Button type="submit" disabled={processing}>
                                        Open till
                                    </Button>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            </>
        );
    }

    return (
        <>
            <Head title="Counter" />
            <PageHeader
                title="Counter"
                description={branch?.name}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={showTill(till.id)}>Till {till.number}</Link>
                    </Button>
                }
            />

            <div className="grid gap-5 lg:grid-cols-[1fr_22rem]">
                <Panel>
                    <PanelHeader title="Find medicines" />
                    <PanelBody>
                        <Label htmlFor="search">Find a medicine</Label>
                        <Input
                            id="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Name, generic name or scan a barcode"
                            autoFocus
                            autoComplete="off"
                            className="mt-1.5"
                        />
                        {matches.length > 0 && (
                            <ul className="mt-2 divide-y rounded-md border">
                                {matches.map((medicine) => {
                                    const blocked =
                                        medicine.prescriptionOnly ||
                                        medicine.available === 0;

                                    return (
                                        <li key={medicine.id}>
                                            <button
                                                type="button"
                                                disabled={blocked}
                                                onClick={() => add(medicine)}
                                                className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-left text-sm hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <span className="font-medium">
                                                    {medicine.label}
                                                </span>
                                                <span className="text-muted-foreground">
                                                    {medicine.genericName}
                                                </span>
                                                {medicine.prescriptionOnly && (
                                                    <StatusBadge tone="info">
                                                        Needs prescription
                                                    </StatusBadge>
                                                )}
                                                <span className="ml-auto tabular-nums">
                                                    {money(medicine.priceMinor)}{' '}
                                                    · {medicine.available}{' '}
                                                    {medicine.unit} in stock
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        <h2 className="mt-6 text-sm font-semibold">Basket</h2>
                        {basket.length === 0 ? (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Nothing yet: search above.
                            </p>
                        ) : (
                            <ul className="mt-2 divide-y rounded-md border">
                                {basket.map((line) => (
                                    <li
                                        key={line.medicine.id}
                                        className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm"
                                    >
                                        <span className="font-medium">
                                            {line.medicine.label}
                                        </span>
                                        <div className="ml-auto flex items-center gap-1">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                aria-label={`One less ${line.medicine.label}`}
                                                onClick={() =>
                                                    setQuantity(
                                                        line.medicine.id,
                                                        line.quantity - 1,
                                                    )
                                                }
                                            >
                                                <Minus
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            </Button>
                                            <Input
                                                type="number"
                                                min={1}
                                                max={line.medicine.available}
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    setQuantity(
                                                        line.medicine.id,
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                aria-label={`Quantity of ${line.medicine.label}`}
                                                className="h-8 w-20 text-center"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                aria-label={`One more ${line.medicine.label}`}
                                                onClick={() =>
                                                    setQuantity(
                                                        line.medicine.id,
                                                        line.quantity + 1,
                                                    )
                                                }
                                            >
                                                <Plus
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            </Button>
                                        </div>
                                        {maxDiscountPercent > 0 && (
                                            <label className="flex items-center gap-1 text-muted-foreground">
                                                <Input
                                                    type="number"
                                                    min={0}
                                                    max={maxDiscountPercent}
                                                    value={line.discount}
                                                    onChange={(event) =>
                                                        setDiscount(
                                                            line.medicine.id,
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={`Discount on ${line.medicine.label}, percent`}
                                                    className="h-8 w-16 text-center"
                                                />
                                                % off
                                            </label>
                                        )}
                                        <span className="w-28 text-right tabular-nums">
                                            {money(lineTotal(line))}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            aria-label={`Remove ${line.medicine.label}`}
                                            onClick={() =>
                                                setBasket(
                                                    basket.filter(
                                                        (l) =>
                                                            l.medicine.id !==
                                                            line.medicine.id,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </PanelBody>
                </Panel>

                <Form
                    {...store.form()}
                    onSuccess={() => {
                        setBasket([]);
                        setTendered('');
                        setSubmissionId(crypto.randomUUID());
                    }}
                    className="grid content-start gap-4 rounded-lg border bg-card p-4 shadow-xs"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="submission_id"
                                value={submissionId}
                            />
                            {basket.map((line, i) => (
                                <span key={line.medicine.id}>
                                    <input
                                        type="hidden"
                                        name={`items[${i}][item_id]`}
                                        value={line.medicine.id}
                                    />
                                    <input
                                        type="hidden"
                                        name={`items[${i}][quantity]`}
                                        value={line.quantity}
                                    />
                                    {line.discount > 0 && (
                                        <input
                                            type="hidden"
                                            name={`items[${i}][discount_percent]`}
                                            value={line.discount}
                                        />
                                    )}
                                </span>
                            ))}
                            <p className="flex items-baseline justify-between">
                                <span className="text-muted-foreground">
                                    Total
                                </span>
                                <span className="text-2xl font-semibold tabular-nums">
                                    {money(total)}
                                </span>
                            </p>

                            <div className="grid gap-1.5">
                                <Label htmlFor="payment_method">Payment</Label>
                                <select
                                    id="payment_method"
                                    name="payment_method"
                                    value={method}
                                    onChange={(event) =>
                                        setMethod(event.target.value)
                                    }
                                    className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                >
                                    {paymentMethods.map((option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {method === 'cash' ? (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="amount_tendered">
                                        Cash given
                                    </Label>
                                    <Input
                                        id="amount_tendered"
                                        name="amount_tendered"
                                        inputMode="decimal"
                                        value={tendered}
                                        onChange={(event) =>
                                            setTendered(event.target.value)
                                        }
                                        aria-invalid={
                                            errors.amount_tendered
                                                ? true
                                                : undefined
                                        }
                                    />
                                    {change !== null && (
                                        <p
                                            className={
                                                change < 0
                                                    ? 'text-sm text-destructive'
                                                    : 'text-sm'
                                            }
                                        >
                                            {change < 0
                                                ? `Short by ${money(-change)}`
                                                : `Change: ${money(change)}`}
                                        </p>
                                    )}
                                    {errors.amount_tendered && (
                                        <p className="text-sm text-destructive">
                                            {errors.amount_tendered}
                                        </p>
                                    )}
                                </div>
                            ) : (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="payment_reference">
                                        Payment reference
                                    </Label>
                                    <Input
                                        id="payment_reference"
                                        name="payment_reference"
                                    />
                                </div>
                            )}

                            <div className="grid gap-1.5">
                                <Label htmlFor="customer_name">
                                    Customer (optional)
                                </Label>
                                <Input
                                    id="customer_name"
                                    name="customer_name"
                                />
                            </div>

                            {Object.entries(errors)
                                .filter(([field]) => field.startsWith('items'))
                                .map(([field, message]) => (
                                    <p
                                        key={field}
                                        className="text-sm text-destructive"
                                    >
                                        {message}
                                    </p>
                                ))}

                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing || basket.length === 0}
                            >
                                Complete sale
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
