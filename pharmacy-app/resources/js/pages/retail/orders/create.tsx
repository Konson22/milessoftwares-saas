import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { MouseEvent } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { lineTotal, listPrice } from '@/lib/wholesale';
import type { ListPrice } from '@/lib/wholesale';
import { index, store } from '@/routes/retail/orders';

type Item = {
    id: string;
    label: string;
    unit: string;
    sellPriceMinor: number;
    /** Units the branch could ship today. */
    available: number;
    packs: {
        id: string;
        label: string;
        units: number;
        sellPriceMinor: number;
    }[];
};

type Props = {
    customers: {
        id: string;
        number: string;
        name: string;
        priceListId: string | null;
        creditLimitMinor: number;
        availableMinor: number;
    }[];
    items: Item[];
    priceLists: Record<string, { name: string; prices: ListPrice[] }>;
    maxDiscountPercent: number;
    validUntil: string;
};

type Line = {
    key: number;
    itemId: string;
    packId: string;
    quantity: string;
    discount: string;
};

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

/**
 * A quotation for an account customer, priced at their price list. Saved as
 * a quotation to send, or confirmed at once as a sales order.
 */
export default function CreateOrder({
    customers,
    items,
    priceLists,
    maxDiscountPercent,
    validUntil,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const [customerId, setCustomerId] = useState(customers[0]?.id ?? '');
    const [nextKey, setNextKey] = useState(1);
    const blank = (key: number): Line => ({
        key,
        itemId: items[0]?.id ?? '',
        packId: '',
        quantity: '',
        discount: '',
    });
    const [lines, setLines] = useState<Line[]>([blank(0)]);

    const customer = customers.find((c) => c.id === customerId);
    const list = customer?.priceListId
        ? priceLists[customer.priceListId]
        : undefined;
    const change = (key: number, update: Partial<Line>) =>
        setLines(lines.map((l) => (l.key === key ? { ...l, ...update } : l)));

    const priced = lines.map((line) => {
        const item = items.find((i) => i.id === line.itemId);
        const pack = item?.packs.find((p) => p.id === line.packId) ?? null;
        const price = item
            ? listPrice(
                  list?.prices,
                  item.id,
                  pack,
                  pack?.sellPriceMinor ?? item.sellPriceMinor,
              )
            : 0;
        const quantity = Number.parseInt(line.quantity || '0', 10) || 0;
        const total = lineTotal(
            quantity,
            price,
            Number.parseInt(line.discount || '0', 10) || 0,
        );
        const inStock = item
            ? Math.floor(item.available / (pack?.units ?? 1))
            : 0;

        return { line, item, pack, price, quantity, total, inStock };
    });
    const total = priced.reduce((sum, p) => sum + p.total, 0);
    const submitAs = (
        event: MouseEvent<HTMLButtonElement>,
        confirm: boolean,
    ) => {
        const input = event.currentTarget.form?.elements.namedItem('confirm');
        if (input instanceof HTMLInputElement) {
            input.value = confirm ? '1' : '0';
        }
    };

    if (customers.length === 0) {
        return (
            <>
                <Head title="New quotation" />
                <PageHeader title="New quotation" />
                <Panel className="max-w-4xl">
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            Add the customer first: quotations are for account
                            customers.
                        </p>
                    </PanelBody>
                </Panel>
            </>
        );
    }

    return (
        <>
            <Head title="New quotation" />
            <PageHeader
                title="New quotation"
                description={
                    list
                        ? `Priced at ${list.name}.`
                        : "Priced at the shop's own prices."
                }
            />

            <Panel className="max-w-5xl">
                <PanelBody>
                    <Form {...store.form()} className="grid gap-4">
                        {({ errors, processing }) => (
                            <>
                                {/* Set by the button that sends the form. */}
                                <input
                                    type="hidden"
                                    name="confirm"
                                    defaultValue="0"
                                />
                                <div className="grid gap-4 sm:grid-cols-4">
                                    <div className="grid gap-1.5 sm:col-span-2">
                                        <Label htmlFor="customer_id">
                                            Customer
                                        </Label>
                                        <select
                                            id="customer_id"
                                            name="customer_id"
                                            value={customerId}
                                            onChange={(e) =>
                                                setCustomerId(e.target.value)
                                            }
                                            className={selectClass}
                                        >
                                            {customers.map((c) => (
                                                <option key={c.id} value={c.id}>
                                                    {c.name} ({c.number})
                                                </option>
                                            ))}
                                        </select>
                                        {customer && (
                                            <p className="text-xs text-muted-foreground">
                                                {customer.creditLimitMinor === 0
                                                    ? 'No credit: give them a credit limit before delivering.'
                                                    : `Can still buy ${money(customer.availableMinor)} on account.`}
                                            </p>
                                        )}
                                        {errors.customer_id && (
                                            <p className="text-sm text-destructive">
                                                {errors.customer_id}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="valid_until">
                                            Valid until
                                        </Label>
                                        <Input
                                            id="valid_until"
                                            name="valid_until"
                                            type="date"
                                            required
                                            defaultValue={validUntil}
                                        />
                                        {errors.valid_until && (
                                            <p className="text-sm text-destructive">
                                                {errors.valid_until}
                                            </p>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="customer_reference">
                                            Their order no.
                                        </Label>
                                        <Input
                                            id="customer_reference"
                                            name="customer_reference"
                                        />
                                    </div>
                                </div>

                                <fieldset className="grid gap-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Lines
                                    </legend>
                                    {priced.map(
                                        (
                                            {
                                                line,
                                                item,
                                                price,
                                                quantity,
                                                total: amount,
                                                inStock,
                                            },
                                            i,
                                        ) => (
                                            <div
                                                key={line.key}
                                                className="grid items-start gap-2 sm:grid-cols-[1fr_10rem_7rem_6rem_9rem_auto]"
                                            >
                                                <div>
                                                    <select
                                                        name={`lines[${i}][item_id]`}
                                                        aria-label={`Product, line ${i + 1}`}
                                                        value={line.itemId}
                                                        onChange={(e) =>
                                                            change(line.key, {
                                                                itemId: e.target
                                                                    .value,
                                                                packId: '',
                                                            })
                                                        }
                                                        className={selectClass}
                                                    >
                                                        {items.map((option) => (
                                                            <option
                                                                key={option.id}
                                                                value={
                                                                    option.id
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ))}
                                                    </select>
                                                    {errors[
                                                        `lines.${i}.item_id`
                                                    ] && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                errors[
                                                                    `lines.${i}.item_id`
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                <select
                                                    name={`lines[${i}][pack_id]`}
                                                    aria-label={`Sold as, line ${i + 1}`}
                                                    value={line.packId}
                                                    onChange={(e) =>
                                                        change(line.key, {
                                                            packId: e.target
                                                                .value,
                                                        })
                                                    }
                                                    disabled={
                                                        !item ||
                                                        item.packs.length === 0
                                                    }
                                                    className={selectClass}
                                                >
                                                    <option value="">
                                                        {item?.unit ?? 'unit'}
                                                    </option>
                                                    {item?.packs.map((pack) => (
                                                        <option
                                                            key={pack.id}
                                                            value={pack.id}
                                                        >
                                                            {pack.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                <div>
                                                    <Input
                                                        name={`lines[${i}][quantity]`}
                                                        type="number"
                                                        min={1}
                                                        required
                                                        placeholder="Quantity"
                                                        aria-label={`Quantity, line ${i + 1}`}
                                                        value={line.quantity}
                                                        onChange={(e) =>
                                                            change(line.key, {
                                                                quantity:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                    <p
                                                        className={`text-xs ${quantity > inStock ? 'text-amber-600' : 'text-muted-foreground'}`}
                                                    >
                                                        {inStock} in stock
                                                    </p>
                                                </div>
                                                {maxDiscountPercent > 0 ? (
                                                    <Input
                                                        name={`lines[${i}][discount_percent]`}
                                                        type="number"
                                                        min={0}
                                                        max={maxDiscountPercent}
                                                        placeholder="% off"
                                                        aria-label={`Discount, line ${i + 1}`}
                                                        value={line.discount}
                                                        onChange={(e) =>
                                                            change(line.key, {
                                                                discount:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                ) : (
                                                    <span />
                                                )}
                                                <p className="py-2 text-right text-sm tabular-nums">
                                                    {money(amount)}
                                                    <span className="block text-xs text-muted-foreground">
                                                        {money(price)} each
                                                    </span>
                                                </p>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Remove line ${i + 1}`}
                                                    disabled={
                                                        lines.length === 1
                                                    }
                                                    onClick={() =>
                                                        setLines(
                                                            lines.filter(
                                                                (l) =>
                                                                    l.key !==
                                                                    line.key,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </Button>
                                                {(errors[
                                                    `lines.${i}.discount_percent`
                                                ] ||
                                                    errors[
                                                        `lines.${i}.pack_id`
                                                    ]) && (
                                                    <p className="text-xs text-destructive sm:col-span-6">
                                                        {errors[
                                                            `lines.${i}.discount_percent`
                                                        ] ??
                                                            errors[
                                                                `lines.${i}.pack_id`
                                                            ]}
                                                    </p>
                                                )}
                                            </div>
                                        ),
                                    )}
                                    <div className="flex items-center justify-between">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                setLines([
                                                    ...lines,
                                                    blank(nextKey),
                                                ]);
                                                setNextKey(nextKey + 1);
                                            }}
                                        >
                                            <Plus
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Add line
                                        </Button>
                                        <p className="text-lg font-semibold tabular-nums">
                                            {money(total)}
                                        </p>
                                    </div>
                                    {errors.lines && (
                                        <p className="text-sm text-destructive">
                                            {errors.lines}
                                        </p>
                                    )}
                                </fieldset>

                                <div className="grid gap-1.5">
                                    <Label htmlFor="notes">Notes</Label>
                                    <Input id="notes" name="notes" />
                                </div>

                                {errors.status && (
                                    <p className="text-sm text-destructive">
                                        {errors.status}
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-3">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                        onClick={(e) => submitAs(e, false)}
                                    >
                                        Save quotation
                                    </Button>
                                    <Button
                                        type="submit"
                                        disabled={processing}
                                        onClick={(e) => submitAs(e, true)}
                                    >
                                        Confirm as order
                                    </Button>
                                    <Button variant="ghost" asChild>
                                        <Link href={index()}>Cancel</Link>
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
