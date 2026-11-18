import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney, parseMoney, toMajor } from '@/lib/money';
import { index, store } from '@/routes/stock/deliveries';

type Drink = {
    id: string;
    name: string;
    unit: string;
    onHand: number;
    averageCostMinor: number;
};

type Props = { drinks: Drink[]; suppliers: string[] };

type Line = { item_id: string; quantity: string; unit_cost: string };

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

const SELECT =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm';

/**
 * Receiving a supplier's delivery: who brought it, and each drink with how
 * many came and what one cost.
 */
export default function DeliveryCreate({ drinks, suppliers }: Props) {
    const { currency } = usePage().props;
    const code = currency ?? 'USD';
    const money = (minor: number) => formatMoney(minor, code);
    const form = useForm<{
        supplier_name: string;
        supplier_reference: string;
        note: string;
        lines: Line[];
    }>({
        supplier_name: '',
        supplier_reference: '',
        note: '',
        lines: [{ item_id: '', quantity: '', unit_cost: '' }],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const lines = form.data.lines;
    const byId = new Map(drinks.map((drink) => [drink.id, drink]));
    const total = lines.reduce(
        (sum, line) =>
            sum +
            (Number.parseInt(line.quantity, 10) || 0) *
                (parseMoney(line.unit_cost, code) ?? 0),
        0,
    );

    const setLine = (at: number, patch: Partial<Line>) =>
        form.setData(
            'lines',
            lines.map((line, i) => (i === at ? { ...line, ...patch } : line)),
        );
    const choose = (at: number, itemId: string) => {
        const drink = byId.get(itemId);
        setLine(at, {
            item_id: itemId,
            // The cost so far is the likely price; it can be changed.
            unit_cost:
                lines[at].unit_cost === '' &&
                drink &&
                drink.averageCostMinor > 0
                    ? toMajor(drink.averageCostMinor, code)
                    : lines[at].unit_cost,
        });
    };

    return (
        <>
            <Head title="Receive a delivery" />
            <PageHeader
                title="Receive a delivery"
                description="Count what came in: stock goes up at what it cost, and Finance records what is owed to the supplier."
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Deliveries</Link>
                    </Button>
                }
            />

            <form
                className="grid gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(store());
                }}
            >
                <Panel>
                    <PanelHeader title="Supplier" />
                    <PanelBody className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor="supplier_name">Supplier</Label>
                            <Input
                                id="supplier_name"
                                list="suppliers"
                                value={form.data.supplier_name}
                                onChange={(e) =>
                                    form.setData(
                                        'supplier_name',
                                        e.target.value,
                                    )
                                }
                                required
                            />
                            <datalist id="suppliers">
                                {suppliers.map((name) => (
                                    <option key={name} value={name} />
                                ))}
                            </datalist>
                            <FieldError message={errors.supplier_name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="supplier_reference">
                                Their invoice or delivery note
                            </Label>
                            <Input
                                id="supplier_reference"
                                value={form.data.supplier_reference}
                                onChange={(e) =>
                                    form.setData(
                                        'supplier_reference',
                                        e.target.value,
                                    )
                                }
                            />
                            <FieldError message={errors.supplier_reference} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="note">Note</Label>
                            <Input
                                id="note"
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                        </div>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Drinks delivered"
                        description={`Total ${money(total)}`}
                    />
                    <PanelBody className="grid gap-3">
                        {lines.map((line, at) => {
                            const drink = byId.get(line.item_id);
                            return (
                                <div
                                    key={at}
                                    className="grid items-end gap-2 sm:grid-cols-[1fr_8rem_9rem_auto]"
                                >
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`item-${at}`}>
                                            Drink
                                        </Label>
                                        <select
                                            id={`item-${at}`}
                                            className={SELECT}
                                            value={line.item_id}
                                            onChange={(e) =>
                                                choose(at, e.target.value)
                                            }
                                            required
                                        >
                                            <option value="">Choose…</option>
                                            {drinks.map((d) => (
                                                <option
                                                    key={d.id}
                                                    value={d.id}
                                                    disabled={lines.some(
                                                        (other, i) =>
                                                            i !== at &&
                                                            other.item_id ===
                                                                d.id,
                                                    )}
                                                >
                                                    {d.name} ({d.onHand}{' '}
                                                    {d.unit} on hand)
                                                </option>
                                            ))}
                                        </select>
                                        <FieldError
                                            message={
                                                errors[`lines.${at}.item_id`]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`quantity-${at}`}>
                                            How many
                                            {drink ? ` (${drink.unit})` : ''}
                                        </Label>
                                        <Input
                                            id={`quantity-${at}`}
                                            type="number"
                                            min={1}
                                            value={line.quantity}
                                            onChange={(e) =>
                                                setLine(at, {
                                                    quantity: e.target.value,
                                                })
                                            }
                                            required
                                        />
                                        <FieldError
                                            message={
                                                errors[`lines.${at}.quantity`]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor={`cost-${at}`}>
                                            Cost of one
                                        </Label>
                                        <Input
                                            id={`cost-${at}`}
                                            inputMode="decimal"
                                            value={line.unit_cost}
                                            onChange={(e) =>
                                                setLine(at, {
                                                    unit_cost: e.target.value,
                                                })
                                            }
                                            required
                                        />
                                        <FieldError
                                            message={
                                                errors[`lines.${at}.unit_cost`]
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        aria-label="Take this drink off"
                                        disabled={lines.length === 1}
                                        onClick={() =>
                                            form.setData(
                                                'lines',
                                                lines.filter(
                                                    (_, i) => i !== at,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            );
                        })}
                        <FieldError message={errors.lines} />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                disabled={lines.length >= drinks.length}
                                onClick={() =>
                                    form.setData('lines', [
                                        ...lines,
                                        {
                                            item_id: '',
                                            quantity: '',
                                            unit_cost: '',
                                        },
                                    ])
                                }
                            >
                                <Plus /> Another drink
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Receive {money(total)}
                            </Button>
                        </div>
                    </PanelBody>
                </Panel>
            </form>
        </>
    );
}
