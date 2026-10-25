import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Minus, Plus, Send, Trash2, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { floor } from '@/routes';
import { cancel, move, pay, send } from '@/routes/orders';
import {
    destroy as removeLine,
    store as addLines,
    voidMethod as voidLine,
} from '@/routes/orders/lines';
import { show as showBill } from '@/routes/bills';
import { index as tills } from '@/routes/tills';

type Line = {
    id: string;
    name: string;
    kind: 'dish' | 'drink';
    quantity: number;
    note: string | null;
    status: 'new' | 'preparing' | 'ready' | 'served' | 'voided';
    ticket: string | null;
    voidReason: string | null;
    voidedBy: string | null;
};

type MenuItem = {
    id: string;
    name: string;
    kind: 'dish' | 'drink';
    section: string | null;
    priceMinor: number;
};

type Props = {
    order: {
        id: string;
        number: string;
        service: 'dine_in' | 'takeaway';
        table: { id: string; name: string } | null;
        guests: number | null;
        customerName: string | null;
        status: 'open' | 'paid' | 'cancelled';
        openedAt: string;
        openedBy: string;
        cancelReason: string | null;
        bill: {
            id: string;
            number: string;
            totalMinor: number;
            status: string;
        } | null;
        lines: Line[];
    };
    bill: {
        lines: {
            item_id: string;
            name: string;
            quantity: number;
            unit_price_minor: number;
            total_minor: number;
        }[];
        total_minor: number;
    };
    menu: MenuItem[];
    freeTables: { id: string; name: string }[];
    paymentMethods: { value: string; label: string }[];
    tillOpen: boolean;
    can: { void: boolean; pay: boolean };
};

type Draft = { key: number; item: MenuItem; quantity: number; note: string };

const STATUS_LABELS: Record<Line['status'], string> = {
    new: 'Not sent',
    preparing: 'Cooking',
    ready: 'Ready',
    served: 'Served',
    voided: 'Voided',
};

/**
 * One order: the menu to add from, what the kitchen has, and the bill.
 * New dishes are gathered here first, added to the order together, then
 * sent to the kitchen on one ticket.
 */
export default function ShowOrder({
    order,
    bill,
    menu,
    freeTables,
    paymentMethods,
    tillOpen,
    can,
}: Props) {
    const { currency, errors } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const open = order.status === 'open';
    const [search, setSearch] = useState('');
    const [section, setSection] = useState<string | null>(null);
    const [drafts, setDrafts] = useState<Draft[]>([]);
    const [nextKey, setNextKey] = useState(0);
    const [adding, setAdding] = useState(false);
    const [voiding, setVoiding] = useState<string | null>(null);
    const [method, setMethod] = useState(paymentMethods[0]?.value ?? 'cash');

    const sections = useMemo(
        () => [...new Set(menu.map((item) => item.section ?? 'Other'))],
        [menu],
    );
    const shown = menu.filter(
        (item) =>
            (section === null || (item.section ?? 'Other') === section) &&
            (search.trim() === '' ||
                item.name.toLowerCase().includes(search.trim().toLowerCase())),
    );
    const hasNew = order.lines.some((line) => line.status === 'new');
    const draftTotal = drafts.reduce(
        (sum, draft) => sum + draft.quantity * draft.item.priceMinor,
        0,
    );

    const pick = (item: MenuItem) => {
        const same = drafts.find(
            (draft) => draft.item.id === item.id && draft.note === '',
        );
        if (same) {
            setDrafts(
                drafts.map((draft) =>
                    draft === same
                        ? { ...draft, quantity: draft.quantity + 1 }
                        : draft,
                ),
            );
        } else {
            setDrafts([
                ...drafts,
                { key: nextKey, item, quantity: 1, note: '' },
            ]);
            setNextKey(nextKey + 1);
        }
    };
    const change = (key: number, patch: Partial<Draft>) =>
        setDrafts(
            drafts
                .map((draft) =>
                    draft.key === key ? { ...draft, ...patch } : draft,
                )
                .filter((draft) => draft.quantity > 0),
        );
    const addDrafts = (thenSend: boolean) =>
        router.post(
            addLines.url(order.id),
            {
                lines: drafts.map((draft) => ({
                    item_id: draft.item.id,
                    quantity: draft.quantity,
                    note: draft.note || null,
                })),
            },
            {
                preserveScroll: true,
                onStart: () => setAdding(true),
                onFinish: () => setAdding(false),
                onSuccess: () => {
                    setDrafts([]);
                    if (thenSend) {
                        router.post(
                            send.url(order.id),
                            {},
                            { preserveScroll: true },
                        );
                    }
                },
            },
        );

    const where = order.table
        ? `Table ${order.table.name}`
        : `Takeaway${order.customerName ? ` · ${order.customerName}` : ''}`;

    return (
        <>
            <Head title={`${where} · ${order.number}`} />
            <PageHeader
                title={where}
                description={`${order.number} · opened ${new Date(order.openedAt).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} by ${order.openedBy}${order.guests ? ` · ${order.guests} guests` : ''}`}
                badges={
                    <StatusBadge tone={statusTone(order.status)}>
                        {order.status}
                    </StatusBadge>
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={floor()}>← Floor</Link>
                    </Button>
                }
            />

            <div className="grid gap-4 lg:grid-cols-5">
                {open && (
                    <div className="space-y-4 lg:col-span-3">
                        <Panel>
                            <PanelBody className="space-y-3">
                                <SearchField
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Find a dish or drink"
                                    aria-label="Find on the menu"
                                />
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant={
                                            section === null
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() => setSection(null)}
                                    >
                                        All
                                    </Button>
                                    {sections.map((name) => (
                                        <Button
                                            key={name}
                                            size="sm"
                                            variant={
                                                section === name
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() => setSection(name)}
                                        >
                                            {name}
                                        </Button>
                                    ))}
                                </div>
                                {shown.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Nothing on the menu matches.
                                    </p>
                                ) : (
                                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-4">
                                        {shown.map((item) => (
                                            <button
                                                key={item.id}
                                                type="button"
                                                onClick={() => pick(item)}
                                                className="rounded-md border p-2.5 text-left transition hover:bg-muted active:scale-[0.98]"
                                            >
                                                <span className="block text-sm font-medium">
                                                    {item.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {money(item.priceMinor)}
                                                </span>
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </PanelBody>
                        </Panel>

                        {drafts.length > 0 && (
                            <Panel>
                                <PanelHeader
                                    title="Adding"
                                    description="Notes go to the kitchen with the dish."
                                />
                                <ul className="divide-y">
                                    {drafts.map((draft) => (
                                        <li
                                            key={draft.key}
                                            className="flex flex-wrap items-center gap-2 px-4 py-2"
                                        >
                                            <span className="min-w-32 flex-1 text-sm font-medium">
                                                {draft.item.name}
                                            </span>
                                            <div className="flex items-center gap-1">
                                                <Button
                                                    size="icon"
                                                    variant="outline"
                                                    className="size-7"
                                                    aria-label={`One fewer ${draft.item.name}`}
                                                    onClick={() =>
                                                        change(draft.key, {
                                                            quantity:
                                                                draft.quantity -
                                                                1,
                                                        })
                                                    }
                                                >
                                                    <Minus
                                                        className="size-3"
                                                        aria-hidden
                                                    />
                                                </Button>
                                                <span className="w-6 text-center tabular-nums">
                                                    {draft.quantity}
                                                </span>
                                                <Button
                                                    size="icon"
                                                    variant="outline"
                                                    className="size-7"
                                                    aria-label={`One more ${draft.item.name}`}
                                                    onClick={() =>
                                                        change(draft.key, {
                                                            quantity:
                                                                draft.quantity +
                                                                1,
                                                        })
                                                    }
                                                >
                                                    <Plus
                                                        className="size-3"
                                                        aria-hidden
                                                    />
                                                </Button>
                                            </div>
                                            {draft.item.kind === 'dish' && (
                                                <Input
                                                    value={draft.note}
                                                    onChange={(event) =>
                                                        change(draft.key, {
                                                            note: event.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder="Note: no onions…"
                                                    aria-label={`Note for ${draft.item.name}`}
                                                    maxLength={160}
                                                    className="h-8 w-48"
                                                />
                                            )}
                                            <span className="w-24 text-right text-sm tabular-nums">
                                                {money(
                                                    draft.quantity *
                                                        draft.item.priceMinor,
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <PanelBody className="flex flex-wrap items-center justify-end gap-2 border-t">
                                    {errors.lines && (
                                        <p className="mr-auto text-sm text-destructive">
                                            {errors.lines}
                                        </p>
                                    )}
                                    <span className="mr-2 text-sm tabular-nums">
                                        {money(draftTotal)}
                                    </span>
                                    <Button
                                        variant="ghost"
                                        onClick={() => setDrafts([])}
                                        disabled={adding}
                                    >
                                        Clear
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => addDrafts(false)}
                                        disabled={adding}
                                    >
                                        Add to order
                                    </Button>
                                    <Button
                                        onClick={() => addDrafts(true)}
                                        disabled={adding}
                                    >
                                        <Send className="size-4" aria-hidden />
                                        Add and send to kitchen
                                    </Button>
                                </PanelBody>
                            </Panel>
                        )}
                    </div>
                )}

                <div
                    className={`space-y-4 ${open ? 'lg:col-span-2' : 'lg:col-span-3'}`}
                >
                    <Panel>
                        <PanelHeader
                            title="Order"
                            actions={
                                open && hasNew ? (
                                    <Form
                                        {...send.form(order.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                <Send
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                                Send to kitchen
                                            </Button>
                                        )}
                                    </Form>
                                ) : undefined
                            }
                        />
                        {order.lines.length === 0 ? (
                            <PanelBody>
                                <p className="text-sm text-muted-foreground">
                                    Nothing ordered yet: pick from the menu.
                                </p>
                            </PanelBody>
                        ) : (
                            <ul className="divide-y">
                                {order.lines.map((line) => (
                                    <li key={line.id} className="px-4 py-2">
                                        <div className="flex items-start gap-2">
                                            <div
                                                className={`flex-1 text-sm ${line.status === 'voided' ? 'text-muted-foreground line-through' : ''}`}
                                            >
                                                <span className="font-medium tabular-nums">
                                                    {line.quantity} ×
                                                </span>{' '}
                                                {line.name}
                                                {line.note && (
                                                    <span className="block text-xs text-amber-700 dark:text-amber-400">
                                                        {line.note}
                                                    </span>
                                                )}
                                            </div>
                                            <StatusBadge
                                                tone={
                                                    line.status === 'voided'
                                                        ? 'danger'
                                                        : statusTone(
                                                              line.status,
                                                          )
                                                }
                                            >
                                                {STATUS_LABELS[line.status]}
                                            </StatusBadge>
                                            {open && line.status === 'new' && (
                                                <Form
                                                    {...removeLine.form([
                                                        order.id,
                                                        line.id,
                                                    ])}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({ processing }) => (
                                                        <Button
                                                            type="submit"
                                                            size="icon"
                                                            variant="ghost"
                                                            className="size-7"
                                                            aria-label={`Take ${line.name} off`}
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            <Trash2
                                                                className="size-4"
                                                                aria-hidden
                                                            />
                                                        </Button>
                                                    )}
                                                </Form>
                                            )}
                                            {open &&
                                                can.void &&
                                                line.status !== 'new' &&
                                                line.status !== 'voided' && (
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        className="size-7"
                                                        aria-label={`Void ${line.name}`}
                                                        onClick={() =>
                                                            setVoiding(
                                                                voiding ===
                                                                    line.id
                                                                    ? null
                                                                    : line.id,
                                                            )
                                                        }
                                                    >
                                                        <X
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                    </Button>
                                                )}
                                        </div>
                                        {line.status === 'voided' &&
                                            line.voidReason && (
                                                <p className="text-xs text-muted-foreground">
                                                    Voided by {line.voidedBy}:{' '}
                                                    {line.voidReason}
                                                </p>
                                            )}
                                        {voiding === line.id && (
                                            <Form
                                                {...voidLine.form([
                                                    order.id,
                                                    line.id,
                                                ])}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                onSuccess={() =>
                                                    setVoiding(null)
                                                }
                                                className="mt-2 flex gap-2"
                                            >
                                                {({
                                                    processing,
                                                    errors: formErrors,
                                                }) => (
                                                    <>
                                                        <Input
                                                            name="reason"
                                                            required
                                                            placeholder="Why? (sent back, spilt…)"
                                                            aria-label="Reason for voiding"
                                                            className="h-8"
                                                        />
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="destructive"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Void
                                                        </Button>
                                                        {formErrors.reason && (
                                                            <p className="text-xs text-destructive">
                                                                {
                                                                    formErrors.reason
                                                                }
                                                            </p>
                                                        )}
                                                    </>
                                                )}
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {errors.line && (
                            <PanelBody>
                                <p className="text-sm text-destructive">
                                    {errors.line}
                                </p>
                            </PanelBody>
                        )}
                    </Panel>

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
                                </tbody>
                                <tfoot className="border-t font-semibold">
                                    <tr>
                                        <td className="py-1.5">Total</td>
                                        <td className="py-1.5 text-right tabular-nums">
                                            {money(
                                                order.bill?.totalMinor ??
                                                    bill.total_minor,
                                            )}
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>

                            {order.bill && (
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={showBill(order.bill.id)}>
                                            Bill {order.bill.number}
                                            {order.bill.status === 'voided' &&
                                                ' (voided)'}
                                        </Link>
                                    </Button>
                                </div>
                            )}
                            {order.status === 'cancelled' && (
                                <p className="mt-3 text-sm text-muted-foreground">
                                    Cancelled: {order.cancelReason}
                                </p>
                            )}

                            {open && can.pay && bill.lines.length > 0 && (
                                <Form
                                    {...pay.form(order.id)}
                                    className="mt-4 grid gap-3 border-t pt-4"
                                >
                                    {({ processing, errors: payErrors }) => (
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
                                            <div className="grid gap-1.5">
                                                <Label htmlFor="payment_method">
                                                    Paid by
                                                </Label>
                                                <select
                                                    id="payment_method"
                                                    name="payment_method"
                                                    value={method}
                                                    onChange={(event) =>
                                                        setMethod(
                                                            event.target.value,
                                                        )
                                                    }
                                                    className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                                >
                                                    {paymentMethods.map(
                                                        (option) => (
                                                            <option
                                                                key={
                                                                    option.value
                                                                }
                                                                value={
                                                                    option.value
                                                                }
                                                            >
                                                                {option.label}
                                                            </option>
                                                        ),
                                                    )}
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
                                                    {payErrors.amount_tendered && (
                                                        <p className="text-sm text-destructive">
                                                            {
                                                                payErrors.amount_tendered
                                                            }
                                                        </p>
                                                    )}
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
                                            {(payErrors.payment_method ||
                                                payErrors.till) && (
                                                <p className="text-sm text-destructive">
                                                    {payErrors.payment_method ??
                                                        payErrors.till}
                                                </p>
                                            )}
                                            <Button
                                                type="submit"
                                                disabled={processing || hasNew}
                                            >
                                                Take payment ·{' '}
                                                {money(bill.total_minor)}
                                            </Button>
                                            {hasNew && (
                                                <p className="text-xs text-muted-foreground">
                                                    Send the new lines to the
                                                    kitchen, or take them off,
                                                    first.
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            )}
                        </PanelBody>
                    </Panel>

                    {open && (
                        <Panel>
                            <PanelBody className="space-y-3">
                                {order.service === 'dine_in' &&
                                    freeTables.length > 0 && (
                                        <Form
                                            {...move.form(order.id)}
                                            options={{ preserveScroll: true }}
                                            className="flex flex-wrap items-center gap-2"
                                        >
                                            {({
                                                processing,
                                                errors: moveErrors,
                                            }) => (
                                                <>
                                                    <select
                                                        name="dining_table_id"
                                                        aria-label="Move to table"
                                                        className="h-8 rounded-md border bg-transparent px-2 text-sm"
                                                    >
                                                        {freeTables.map(
                                                            (table) => (
                                                                <option
                                                                    key={
                                                                        table.id
                                                                    }
                                                                    value={
                                                                        table.id
                                                                    }
                                                                >
                                                                    {table.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </select>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        Move to this table
                                                    </Button>
                                                    {moveErrors.dining_table_id && (
                                                        <p className="w-full text-xs text-destructive">
                                                            {
                                                                moveErrors.dining_table_id
                                                            }
                                                        </p>
                                                    )}
                                                </>
                                            )}
                                        </Form>
                                    )}
                                <Form
                                    {...cancel.form(order.id)}
                                    className="flex flex-wrap items-center gap-2"
                                >
                                    {({ processing, errors: cancelErrors }) => (
                                        <>
                                            <Input
                                                name="reason"
                                                required
                                                placeholder="Why cancel? (guests left…)"
                                                aria-label="Reason for cancelling"
                                                className="h-8 flex-1"
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Cancel order
                                            </Button>
                                            {cancelErrors.reason && (
                                                <p className="w-full text-xs text-destructive">
                                                    {cancelErrors.reason}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}
