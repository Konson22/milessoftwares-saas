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
import { uuid } from '@/lib/uuid';
import { store } from '@/routes/retail/sales';
import { show as showTill, store as openTill } from '@/routes/retail/tills';

type Product = {
    id: string;
    label: string;
    barcode: string | null;
    unit: string;
    priceMinor: number;
    available: number;
    category: string | null;
    /** Running here today: taken off by itself. */
    promotion: { name: string; percentOff: number } | null;
    /** Each unit sold names its serial number. */
    tracksSerials: boolean;
    packs: { id: string; name: string; units: number; priceMinor: number }[];
};

type Props = {
    /** The shared counter's name for the items this industry sells. */
    items: Product[];
    paymentMethods: { value: string; label: string }[];
    /** 0 when this member may not give discounts. */
    maxDiscountPercent: number;
    /** The seller's open shift here. */
    till: { id: string; number: string } | null;
    /** Account customers, when this member may sell on account here. */
    customers: {
        id: string;
        number: string;
        name: string;
        availableMinor: number;
    }[];
    /** While a loyalty program runs: its terms, and who collects points. */
    loyalty: {
        spendPerPointMinor: number;
        pointValueMinor: number;
        members: { id: string; number: string; name: string; points: number }[];
    } | null;
};

/** Quantity counts packs when a pack is chosen, units otherwise. */
type BasketLine = {
    product: Product;
    packId: string | null;
    quantity: number;
    discount: number;
    /** One per unit, for products that track serials. */
    serials: string[];
};

const packOf = (line: BasketLine) =>
    line.product.packs.find((pack) => pack.id === line.packId) ?? null;

const maxQuantity = (line: BasketLine) =>
    Math.floor(line.product.available / (packOf(line)?.units ?? 1));

/** Units the line takes off the shelf: packs count all their units. */
const lineUnits = (line: BasketLine) =>
    line.quantity * (packOf(line)?.units ?? 1);

/**
 * What is left on the shelf once the basket is sold: red when nothing is,
 * amber once the basket has taken some.
 */
function StockBadge({
    available,
    inBasket,
    unit,
}: {
    available: number;
    inBasket: number;
    unit: string;
}) {
    const left = Math.max(available - inBasket, 0);

    return (
        <StatusBadge
            tone={left === 0 ? 'danger' : inBasket > 0 ? 'warning' : 'success'}
            className="tabular-nums"
        >
            {inBasket > 0
                ? `${left} of ${available} ${unit} left`
                : `${available} ${unit} in stock`}
        </StatusBadge>
    );
}

/** The promotion's percentage, or the seller's when that is bigger (as the server prices it). */
const percentOff = (line: BasketLine) =>
    Math.max(line.discount, line.product.promotion?.percentOff ?? 0);

const lineTotal = (line: BasketLine) => {
    const gross =
        line.quantity * (packOf(line)?.priceMinor ?? line.product.priceMinor);

    return gross - Math.round((gross * percentOff(line)) / 100);
};

/**
 * The shop counter: scan barcodes (or search), build the basket, take
 * payment. A scanner types the barcode and presses Enter, which adds the
 * product straight away. Prices, discounts and stock are checked again on
 * the server. Each basket is sent
 * with its own key, so a double click or a retry records one sale.
 */
export default function RetailCounter({
    items: products,
    paymentMethods,
    maxDiscountPercent,
    till,
    customers,
    loyalty,
}: Props) {
    const { currency, branch } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const decimals = money(0).includes('.') ? 2 : 0;

    const [search, setSearch] = useState('');
    const [notFound, setNotFound] = useState<string | null>(null);
    const [basket, setBasket] = useState<BasketLine[]>([]);
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
    const [tendered, setTendered] = useState('');
    const [customerId, setCustomerId] = useState('');
    const [memberId, setMemberId] = useState('');
    const [redeem, setRedeem] = useState('');
    const [submissionId, setSubmissionId] = useState(() => uuid());

    const matches = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (term === '') {
            return [];
        }

        return products
            .filter(
                (m) =>
                    m.label.toLowerCase().includes(term) ||
                    (m.category?.toLowerCase().includes(term) ?? false) ||
                    m.barcode === search.trim(),
            )
            .slice(0, 8);
    }, [products, search]);

    const inBasket = (id: string) => {
        const line = basket.find((l) => l.product.id === id);

        return line ? lineUnits(line) : 0;
    };

    const subtotal = basket.reduce((sum, line) => sum + lineTotal(line), 0);
    const account = method === 'account';
    // On account the customer is the one the sale goes on; otherwise whoever is named for points.
    const member =
        loyalty?.members.find(
            (m) => m.id === (account ? customerId : memberId),
        ) ?? null;
    const maxRedeem =
        member && loyalty
            ? Math.min(
                  member.points,
                  Math.floor(subtotal / loyalty.pointValueMinor),
              )
            : 0;
    const redeemPoints = Math.max(
        0,
        Math.min(Number.parseInt(redeem || '0', 10) || 0, maxRedeem),
    );
    const total = subtotal - redeemPoints * (loyalty?.pointValueMinor ?? 0);
    const earns =
        member && loyalty ? Math.floor(total / loyalty.spendPerPointMinor) : 0;
    const tenderedMinor = Math.round(
        Number.parseFloat(tendered || '0') * 10 ** decimals,
    );
    const customer = customers.find((c) => c.id === customerId) ?? null;
    const overLimit =
        account && customer !== null && total > customer.availableMinor;
    const change =
        method === 'cash' && tendered !== '' ? tenderedMinor - total : null;

    const add = (product: Product) => {
        setBasket((lines) => {
            const existing = lines.find(
                (line) => line.product.id === product.id,
            );
            if (existing) {
                return lines.map((line) =>
                    line.product.id === product.id
                        ? {
                              ...line,
                              quantity: Math.min(
                                  line.quantity + 1,
                                  maxQuantity(line),
                              ),
                          }
                        : line,
                );
            }

            return [
                ...lines,
                {
                    product,
                    packId: null,
                    quantity: 1,
                    discount: 0,
                    serials: [],
                },
            ];
        });
        setSearch('');
    };

    /**
     * Enter in the search box: an exact barcode adds its product (a
     * scanner sends Enter after the code), otherwise the only match does.
     */
    const scan = () => {
        const code = search.trim();
        if (code === '') {
            return;
        }

        const product =
            products.find((p) => p.barcode === code) ??
            (matches.length === 1 ? matches[0] : undefined);
        if (!product) {
            setNotFound(
                matches.length > 1
                    ? 'More than one product matches: choose one below.'
                    : `Nothing matches “${code}”.`,
            );

            return;
        }

        if (product.available - inBasket(product.id) <= 0) {
            setNotFound(`${product.label} is out of stock here.`);
            setSearch('');

            return;
        }

        add(product);
    };

    const setQuantity = (id: string, quantity: number) =>
        setBasket((lines) =>
            lines.map((line) =>
                line.product.id === id
                    ? {
                          ...line,
                          quantity: Math.max(
                              1,
                              Math.min(quantity, maxQuantity(line)),
                          ),
                      }
                    : line,
            ),
        );

    const setPack = (id: string, packId: string | null) =>
        setBasket((lines) =>
            lines.map((line) =>
                line.product.id === id
                    ? { ...line, packId, quantity: 1 }
                    : line,
            ),
        );

    const setSerial = (id: string, index: number, serial: string) =>
        setBasket((lines) =>
            lines.map((line) => {
                if (line.product.id !== id) {
                    return line;
                }
                const serials = [...line.serials];
                serials[index] = serial;

                return { ...line, serials };
            }),
        );

    const setDiscount = (id: string, discount: number) =>
        setBasket((lines) =>
            lines.map((line) =>
                line.product.id === id
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
                    <PanelHeader
                        title="Start your shift"
                        description="Count the cash in the drawer before taking money."
                    />
                    <PanelBody>
                        <Form
                            {...openTill.form()}
                            className="flex flex-wrap items-end gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="opening_float">
                                            Starting cash in the drawer
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
                                        Start shift
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
                        <Link href={showTill(till.id)}>
                            Shift {till.number}
                        </Link>
                    </Button>
                }
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_22rem]">
                <Panel>
                    <PanelHeader title="Scan or find products" />
                    <PanelBody>
                        <Label htmlFor="search">Barcode or name</Label>
                        <Input
                            id="search"
                            value={search}
                            onChange={(event) => {
                                setSearch(event.target.value);
                                setNotFound(null);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.preventDefault();
                                    scan();
                                }
                            }}
                            placeholder="Scan a barcode, or type a name and press Enter"
                            autoFocus
                            autoComplete="off"
                            className="mt-1.5"
                        />
                        {notFound && (
                            <p
                                role="alert"
                                className="mt-1.5 text-sm text-destructive"
                            >
                                {notFound}
                            </p>
                        )}
                        {matches.length > 0 && (
                            <ul className="mt-2 divide-y rounded-md border">
                                {matches.map((product) => {
                                    const taken = inBasket(product.id);
                                    const blocked =
                                        product.available - taken <= 0;

                                    return (
                                        <li key={product.id}>
                                            <button
                                                type="button"
                                                disabled={blocked}
                                                onClick={() => add(product)}
                                                className="flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2 text-left text-sm hover:bg-muted/50 disabled:cursor-not-allowed disabled:opacity-60"
                                            >
                                                <span className="font-medium">
                                                    {product.label}
                                                </span>
                                                {product.category && (
                                                    <span className="text-muted-foreground">
                                                        {product.category}
                                                    </span>
                                                )}
                                                <span className="ml-auto flex items-center gap-2 tabular-nums">
                                                    {product.promotion && (
                                                        <StatusBadge tone="info">
                                                            {
                                                                product
                                                                    .promotion
                                                                    .percentOff
                                                            }
                                                            % off
                                                        </StatusBadge>
                                                    )}
                                                    {money(product.priceMinor)}
                                                    <StockBadge
                                                        available={
                                                            product.available
                                                        }
                                                        inBasket={taken}
                                                        unit={product.unit}
                                                    />
                                                </span>
                                            </button>
                                        </li>
                                    );
                                })}
                            </ul>
                        )}

                        <h2 className="mt-4 text-sm font-semibold">Basket</h2>
                        {basket.length === 0 ? (
                            <p className="mt-2 text-sm text-muted-foreground">
                                Nothing yet: scan a barcode above.
                            </p>
                        ) : (
                            <ul className="mt-2 divide-y rounded-md border">
                                {basket.map((line) => (
                                    <li
                                        key={line.product.id}
                                        className="flex flex-wrap items-center gap-3 px-4 py-2 text-sm"
                                    >
                                        {line.product.tracksSerials && (
                                            <div className="order-last flex w-full flex-wrap gap-2">
                                                {Array.from(
                                                    { length: lineUnits(line) },
                                                    (_, k) => (
                                                        <Input
                                                            key={k}
                                                            value={
                                                                line.serials[
                                                                    k
                                                                ] ?? ''
                                                            }
                                                            onChange={(event) =>
                                                                setSerial(
                                                                    line.product
                                                                        .id,
                                                                    k,
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            placeholder={`Serial ${k + 1}`}
                                                            aria-label={`Serial number ${k + 1} of ${line.product.label}`}
                                                            className="h-8 w-44 font-mono"
                                                        />
                                                    ),
                                                )}
                                            </div>
                                        )}
                                        <span className="font-medium">
                                            {line.product.label}
                                        </span>
                                        <StockBadge
                                            available={line.product.available}
                                            inBasket={lineUnits(line)}
                                            unit={line.product.unit}
                                        />
                                        {line.product.packs.length > 0 && (
                                            <select
                                                value={line.packId ?? ''}
                                                onChange={(event) =>
                                                    setPack(
                                                        line.product.id,
                                                        event.target.value ||
                                                            null,
                                                    )
                                                }
                                                aria-label={`Sell ${line.product.label} by`}
                                                className="h-8 rounded-md border bg-transparent px-2"
                                            >
                                                <option value="">
                                                    {line.product.unit} ·{' '}
                                                    {money(
                                                        line.product.priceMinor,
                                                    )}
                                                </option>
                                                {line.product.packs.map(
                                                    (pack) => (
                                                        <option
                                                            key={pack.id}
                                                            value={pack.id}
                                                            disabled={
                                                                pack.units >
                                                                line.product
                                                                    .available
                                                            }
                                                        >
                                                            {pack.name} of{' '}
                                                            {pack.units} ·{' '}
                                                            {money(
                                                                pack.priceMinor,
                                                            )}
                                                        </option>
                                                    ),
                                                )}
                                            </select>
                                        )}
                                        <div className="ml-auto flex items-center gap-1">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                aria-label={`One less ${line.product.label}`}
                                                onClick={() =>
                                                    setQuantity(
                                                        line.product.id,
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
                                                max={maxQuantity(line)}
                                                value={line.quantity}
                                                onChange={(event) =>
                                                    setQuantity(
                                                        line.product.id,
                                                        Number(
                                                            event.target.value,
                                                        ),
                                                    )
                                                }
                                                aria-label={`Quantity of ${line.product.label}`}
                                                className="h-8 w-20 text-center"
                                            />
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                className="size-8"
                                                aria-label={`One more ${line.product.label}`}
                                                onClick={() =>
                                                    setQuantity(
                                                        line.product.id,
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
                                                            line.product.id,
                                                            Number(
                                                                event.target
                                                                    .value,
                                                            ),
                                                        )
                                                    }
                                                    aria-label={`Discount on ${line.product.label}, percent`}
                                                    className="h-8 w-16 text-center"
                                                />
                                                % off
                                            </label>
                                        )}
                                        <span className="w-28 text-right tabular-nums">
                                            {money(lineTotal(line))}
                                            {line.product.promotion &&
                                                percentOff(line) ===
                                                    line.product.promotion
                                                        .percentOff && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {
                                                            line.product
                                                                .promotion.name
                                                        }
                                                    </span>
                                                )}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-8"
                                            aria-label={`Remove ${line.product.label}`}
                                            onClick={() =>
                                                setBasket(
                                                    basket.filter(
                                                        (l) =>
                                                            l.product.id !==
                                                            line.product.id,
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
                        setCustomerId('');
                        setMemberId('');
                        setRedeem('');
                        setSubmissionId(uuid());
                    }}
                    className="grid content-start gap-4 rounded-lg border bg-card p-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="submission_id"
                                value={submissionId}
                            />
                            {basket.map((line, i) => (
                                <span key={line.product.id}>
                                    <input
                                        type="hidden"
                                        name={`items[${i}][item_id]`}
                                        value={line.product.id}
                                    />
                                    <input
                                        type="hidden"
                                        name={`items[${i}][quantity]`}
                                        value={line.quantity}
                                    />
                                    {line.packId && (
                                        <input
                                            type="hidden"
                                            name={`items[${i}][pack_id]`}
                                            value={line.packId}
                                        />
                                    )}
                                    {line.discount > 0 && (
                                        <input
                                            type="hidden"
                                            name={`items[${i}][discount_percent]`}
                                            value={line.discount}
                                        />
                                    )}
                                    {line.product.tracksSerials &&
                                        line.serials
                                            .slice(0, lineUnits(line))
                                            .map((serial, k) => (
                                                <input
                                                    key={k}
                                                    type="hidden"
                                                    name={`items[${i}][serials][${k}]`}
                                                    value={serial}
                                                />
                                            ))}
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
                                    {customers.length > 0 && (
                                        <option value="account">
                                            On account
                                        </option>
                                    )}
                                </select>
                                {errors.payment_method && (
                                    <p className="text-sm text-destructive">
                                        {errors.payment_method}
                                    </p>
                                )}
                            </div>

                            {account ? (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="customer_id">
                                        Customer
                                    </Label>
                                    <select
                                        id="customer_id"
                                        name="customer_id"
                                        required
                                        value={customerId}
                                        onChange={(event) =>
                                            setCustomerId(event.target.value)
                                        }
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                        aria-invalid={
                                            errors.customer_id || overLimit
                                                ? true
                                                : undefined
                                        }
                                    >
                                        <option value="">Choose…</option>
                                        {customers.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name} ({option.number})
                                                · can buy{' '}
                                                {money(option.availableMinor)}
                                            </option>
                                        ))}
                                    </select>
                                    {overLimit && (
                                        <p className="text-sm text-destructive">
                                            Over {customer.name}'s credit: they
                                            can buy only{' '}
                                            {money(customer.availableMinor)}.
                                        </p>
                                    )}
                                    {errors.customer_id && (
                                        <p className="text-sm text-destructive">
                                            {errors.customer_id}
                                        </p>
                                    )}
                                </div>
                            ) : method === 'cash' ? (
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

                            {!account && loyalty && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="loyalty_customer">
                                        Collects points (optional)
                                    </Label>
                                    <select
                                        id="loyalty_customer"
                                        name="customer_id"
                                        value={memberId}
                                        onChange={(event) => {
                                            setMemberId(event.target.value);
                                            setRedeem('');
                                        }}
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                    >
                                        <option value="">Nobody</option>
                                        {loyalty.members.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name} ({option.number})
                                                · {option.points} points
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            {member && loyalty && (
                                <div className="grid gap-1.5">
                                    {maxRedeem > 0 && (
                                        <>
                                            <Label htmlFor="redeem_points">
                                                Points to spend (up to{' '}
                                                {maxRedeem},{' '}
                                                {money(loyalty.pointValueMinor)}{' '}
                                                each)
                                            </Label>
                                            <Input
                                                id="redeem_points"
                                                name="redeem_points"
                                                type="number"
                                                min={0}
                                                max={maxRedeem}
                                                value={redeem}
                                                onChange={(event) =>
                                                    setRedeem(
                                                        event.target.value,
                                                    )
                                                }
                                                className="w-32"
                                            />
                                        </>
                                    )}
                                    <p className="text-sm text-muted-foreground">
                                        {redeemPoints > 0 &&
                                            `${money(redeemPoints * loyalty.pointValueMinor)} off. `}
                                        Earns {earns} points.
                                    </p>
                                    {errors.redeem_points && (
                                        <p className="text-sm text-destructive">
                                            {errors.redeem_points}
                                        </p>
                                    )}
                                </div>
                            )}

                            {!account && !member && (
                                <div className="grid gap-1.5">
                                    <Label htmlFor="customer_name">
                                        Customer (optional)
                                    </Label>
                                    <Input
                                        id="customer_name"
                                        name="customer_name"
                                    />
                                </div>
                            )}

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
                                disabled={
                                    processing ||
                                    basket.length === 0 ||
                                    (account && customer === null) ||
                                    overLimit
                                }
                            >
                                {account ? 'Put on account' : 'Complete sale'}
                            </Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}
