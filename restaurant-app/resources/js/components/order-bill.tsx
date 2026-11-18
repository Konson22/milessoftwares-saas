import { Form, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney, parseMoney } from '@/lib/money';
import { show as showBill } from '@/routes/bills';
import { pay } from '@/routes/orders';
import { index as tills } from '@/routes/tills';

export type OrderBill = {
    /** Still to pay, by item. */
    lines: {
        item_id: string;
        name: string;
        quantity: number;
        unit_price_minor: number;
        total_minor: number;
    }[];
    /** Still to pay, line by line (for paying some of them). */
    lines_open: {
        id: string;
        name: string;
        quantity: number;
        note: string | null;
        total_minor: number;
    }[];
    /** What is left to pay now. */
    total_minor: number;
    subtotal_minor: number;
    paid_minor: number;
    shares: {
        parts: number;
        paid: number;
        paid_minor: number;
        next_minor: number;
    } | null;
};

export type PaidBill = {
    id: string;
    number: string;
    totalMinor: number;
    discountMinor: number;
    part: number | null;
    parts: number | null;
    paymentMethod: string;
    status: string;
};

type Mode = 'whole' | 'items' | 'shares';

const SELECT = 'h-9 rounded-md border border-input bg-background px-2 text-sm';

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

/**
 * The order's bill: what is still to pay, the bills paid so far, and taking
 * payment, for everything, some of the lines, or an equal share, with a
 * discount for those allowed to give one.
 */
export function OrderBillPanel({
    orderId,
    open,
    bill,
    bills,
    paymentMethods,
    tillOpen,
    canPay,
    canDiscount,
    hasNew,
    cancelReason,
}: {
    orderId: string;
    open: boolean;
    bill: OrderBill;
    bills: PaidBill[];
    paymentMethods: { value: string; label: string }[];
    tillOpen: boolean;
    canPay: boolean;
    canDiscount: boolean;
    hasNew: boolean;
    cancelReason: string | null;
}) {
    const { currency } = usePage().props;
    const code = currency ?? 'USD';
    const money = (minor: number) => formatMoney(minor, code);
    const running = bill.shares;
    const [mode, setMode] = useState<Mode>(running ? 'shares' : 'whole');
    const [parts, setParts] = useState(String(running?.parts ?? 2));
    const [picked, setPicked] = useState<string[]>([]);
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');
    const [discounting, setDiscounting] = useState(false);
    const [discountType, setDiscountType] = useState<'amount' | 'percent'>(
        'percent',
    );
    const [discount, setDiscount] = useState('');

    const count = Math.min(Math.max(Number.parseInt(parts, 10) || 2, 2), 20);
    const amount =
        mode === 'items'
            ? bill.lines_open
                  .filter((line) => picked.includes(line.id))
                  .reduce((sum, line) => sum + line.total_minor, 0)
            : mode === 'shares'
              ? running
                  ? running.next_minor
                  : Math.floor(bill.total_minor / count)
              : bill.total_minor;
    const off = !discounting
        ? 0
        : discountType === 'percent'
          ? Math.round((amount * (Number.parseInt(discount, 10) || 0)) / 100)
          : Math.min(parseMoney(discount, code) ?? 0, amount);
    const toTake = Math.max(amount - off, 0);
    const kept = bills.filter((b) => b.status === 'paid');

    const choose = (next: Mode) => {
        setMode(next);
        setPicked([]);
    };

    return (
        <Panel>
            <PanelHeader title="Bill" />
            <PanelBody>
                <table className="w-full text-sm">
                    <tbody className="divide-y">
                        {bill.lines.map((line) => (
                            <tr key={line.item_id}>
                                <td className="py-1">
                                    {line.quantity} × {line.name}
                                </td>
                                <td className="py-1 text-right tabular-nums">
                                    {money(line.total_minor)}
                                </td>
                            </tr>
                        ))}
                        {running && (
                            <tr className="text-muted-foreground">
                                <td className="py-1">
                                    {running.paid} of {running.parts} shares
                                    paid
                                </td>
                                <td className="py-1 text-right tabular-nums">
                                    − {money(running.paid_minor)}
                                </td>
                            </tr>
                        )}
                    </tbody>
                    <tfoot className="border-t font-semibold">
                        <tr>
                            <td className="py-1.5">
                                {open
                                    ? kept.length > 0
                                        ? 'Still to pay'
                                        : 'Total'
                                    : 'Paid'}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">
                                {money(
                                    open ? bill.total_minor : bill.paid_minor,
                                )}
                            </td>
                        </tr>
                    </tfoot>
                </table>

                {bills.length > 0 && (
                    <ul className="mt-3 grid gap-1 text-sm">
                        {bills.map((paid) => (
                            <li
                                key={paid.id}
                                className="flex items-center justify-between gap-2"
                            >
                                <Link
                                    href={showBill(paid.id)}
                                    className={`hover:underline ${paid.status === 'voided' ? 'text-muted-foreground line-through' : 'font-medium'}`}
                                >
                                    Bill {paid.number}
                                    {paid.parts &&
                                        ` · share ${paid.part} of ${paid.parts}`}
                                </Link>
                                <span className="text-muted-foreground tabular-nums">
                                    {paid.paymentMethod} ·{' '}
                                    {money(paid.totalMinor)}
                                    {paid.discountMinor > 0 && ' (discounted)'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {cancelReason && (
                    <p className="mt-3 text-sm text-muted-foreground">
                        Cancelled: {cancelReason}
                    </p>
                )}

                {open && canPay && bill.lines.length > 0 && (
                    <Form
                        {...pay.form(orderId)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            setPicked([]);
                            setDiscount('');
                            setDiscounting(false);
                        }}
                        className="mt-4 grid gap-3 border-t pt-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                {!tillOpen && (
                                    <p className="text-xs text-muted-foreground">
                                        Your till is not open:{' '}
                                        <Link
                                            href={tills()}
                                            className="underline"
                                        >
                                            start a shift
                                        </Link>{' '}
                                        before taking payment.
                                    </p>
                                )}

                                <div
                                    role="radiogroup"
                                    aria-label="How the bill is paid"
                                    className="flex flex-wrap gap-1"
                                >
                                    {(
                                        [
                                            [
                                                'whole',
                                                running
                                                    ? 'The rest'
                                                    : 'Whole bill',
                                            ],
                                            ['items', 'By items'],
                                            ['shares', 'Equal shares'],
                                        ] as const
                                    ).map(([value, label]) => (
                                        <Button
                                            key={value}
                                            type="button"
                                            size="sm"
                                            role="radio"
                                            aria-checked={mode === value}
                                            variant={
                                                mode === value
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            // Shares under way: only the next share, or the rest.
                                            disabled={
                                                value === 'items' &&
                                                running !== null
                                            }
                                            onClick={() => choose(value)}
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>

                                {mode === 'items' && (
                                    <fieldset className="grid gap-1.5">
                                        <legend className="mb-1 text-sm font-medium">
                                            What this part pays for
                                        </legend>
                                        {bill.lines_open.map((line) => (
                                            <label
                                                key={line.id}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="line_ids[]"
                                                    value={line.id}
                                                    checked={picked.includes(
                                                        line.id,
                                                    )}
                                                    onChange={(e) =>
                                                        setPicked(
                                                            e.target.checked
                                                                ? [
                                                                      ...picked,
                                                                      line.id,
                                                                  ]
                                                                : picked.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          line.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                                <span className="flex-1">
                                                    {line.quantity} ×{' '}
                                                    {line.name}
                                                    {line.note && (
                                                        <span className="text-muted-foreground">
                                                            {' '}
                                                            · {line.note}
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="tabular-nums">
                                                    {money(line.total_minor)}
                                                </span>
                                            </label>
                                        ))}
                                        <FieldError message={errors.line_ids} />
                                    </fieldset>
                                )}

                                {mode === 'shares' &&
                                    (running ? (
                                        <>
                                            <input
                                                type="hidden"
                                                name="parts"
                                                value={running.parts}
                                            />
                                            <p className="text-sm">
                                                Share {running.paid + 1} of{' '}
                                                {running.parts}
                                            </p>
                                        </>
                                    ) : (
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="parts">
                                                Split between
                                            </Label>
                                            <Input
                                                id="parts"
                                                name="parts"
                                                type="number"
                                                min={2}
                                                max={20}
                                                value={parts}
                                                onChange={(e) =>
                                                    setParts(e.target.value)
                                                }
                                                className="w-24"
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {money(
                                                    Math.floor(
                                                        bill.total_minor /
                                                            count,
                                                    ),
                                                )}{' '}
                                                each; the last share takes what
                                                is left.
                                            </p>
                                            <FieldError
                                                message={errors.parts}
                                            />
                                        </div>
                                    ))}

                                {canDiscount &&
                                    (discounting ? (
                                        <div className="grid gap-2 rounded-md border p-3">
                                            <div className="flex gap-2">
                                                <select
                                                    name="discount_type"
                                                    aria-label="Discount as"
                                                    value={discountType}
                                                    onChange={(e) =>
                                                        setDiscountType(
                                                            e.target.value as
                                                                | 'amount'
                                                                | 'percent',
                                                        )
                                                    }
                                                    className={SELECT}
                                                >
                                                    <option value="percent">
                                                        % off
                                                    </option>
                                                    <option value="amount">
                                                        Amount off
                                                    </option>
                                                </select>
                                                <Input
                                                    name="discount"
                                                    aria-label="Discount"
                                                    inputMode="decimal"
                                                    value={discount}
                                                    onChange={(e) =>
                                                        setDiscount(
                                                            e.target.value,
                                                        )
                                                    }
                                                    placeholder={
                                                        discountType ===
                                                        'percent'
                                                            ? '10'
                                                            : '5.00'
                                                    }
                                                />
                                            </div>
                                            <FieldError
                                                message={errors.discount}
                                            />
                                            <Input
                                                name="discount_reason"
                                                aria-label="Reason for the discount"
                                                placeholder="Why? (regular, complaint…)"
                                                maxLength={255}
                                            />
                                            <FieldError
                                                message={errors.discount_reason}
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="justify-self-start"
                                                onClick={() => {
                                                    setDiscounting(false);
                                                    setDiscount('');
                                                }}
                                            >
                                                No discount
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="justify-self-start"
                                            onClick={() => setDiscounting(true)}
                                        >
                                            Give a discount
                                        </Button>
                                    ))}

                                <div className="grid gap-1.5">
                                    <Label htmlFor="payment_method">
                                        Paid by
                                    </Label>
                                    <select
                                        id="payment_method"
                                        name="payment_method"
                                        value={method}
                                        onChange={(e) =>
                                            setMethod(e.target.value)
                                        }
                                        className={SELECT}
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
                                            placeholder="Exact amount if empty"
                                        />
                                        <FieldError
                                            message={errors.amount_tendered}
                                        />
                                    </div>
                                ) : (
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="payment_reference">
                                            Reference
                                        </Label>
                                        <Input
                                            id="payment_reference"
                                            name="payment_reference"
                                            placeholder="Transaction number"
                                        />
                                    </div>
                                )}
                                <FieldError message={errors.payment_method} />

                                {off > 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {money(amount)} less {money(off)}{' '}
                                        discount
                                    </p>
                                )}
                                <Button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        hasNew ||
                                        (mode === 'items' &&
                                            picked.length === 0)
                                    }
                                >
                                    Take {money(toTake)}
                                </Button>
                                {hasNew && (
                                    <p className="text-xs text-muted-foreground">
                                        Send the new lines to the kitchen, or
                                        take them off, first.
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}
