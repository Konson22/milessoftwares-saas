import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { PackSelect } from '@/components/pack-select';
import type { BuyableItem } from '@/components/pack-select';
import { CostBreakdown } from '@/components/price-badges';
import { index, store } from '@/routes/retail/receipts';

type OrderLine = {
    id: string;
    itemId: string;
    item: string;
    remaining: number;
    pack: string | null;
    packName: string | null;
    unitsPerPack: number;
    unitCost: string;
};

type Entered = { pack: string; cost: string; quantity: string };

type Props = {
    suppliers: { id: string; name: string }[];
    items: BuyableItem[];
    order: {
        id: string;
        number: string;
        supplierId: string;
        lines: OrderLine[];
    } | null;
    locations: { id: string; name: string }[];
    today: string;
};

type Line = {
    key: number;
    orderLine: OrderLine | null;
};

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

/**
 * A delivery into the branch: each line becomes stock in a batch. Against
 * an order, lines start from what is still expected.
 */
export default function ReceiveGoods({
    suppliers,
    items: medicines,
    order,
    locations,
    today,
}: Props) {
    const [lines, setLines] = useState<Line[]>(
        order
            ? order.lines.map((orderLine, key) => ({ key, orderLine }))
            : [{ key: 0, orderLine: null }],
    );
    const [nextKey, setNextKey] = useState(lines.length);
    // The product chosen on each free line (by line key), for its packs.
    const [chosen, setChosen] = useState<Record<number, string>>({});
    const itemOf = (key: number) =>
        medicines.find((m) => m.id === (chosen[key] ?? medicines[0]?.id));
    // What has been typed on each line (by line key), for its cost breakdown;
    // order lines start from the order's quantity and cost.
    const [entered, setEntered] = useState<Record<number, Entered>>(() =>
        Object.fromEntries(
            lines
                .filter((line) => line.orderLine)
                .map((line) => [
                    line.key,
                    {
                        pack: '',
                        cost: line.orderLine!.unitCost,
                        quantity: String(line.orderLine!.remaining),
                    },
                ]),
        ),
    );
    const enteredOf = (key: number): Entered =>
        entered[key] ?? { pack: '', cost: '', quantity: '' };
    const enter = (key: number, change: Partial<Entered>) =>
        setEntered({ ...entered, [key]: { ...enteredOf(key), ...change } });
    // The item and pack a line is for: the order's, or as chosen.
    const breakdownOf = (line: Line) => {
        if (line.orderLine) {
            const { itemId, packName, unitsPerPack } = line.orderLine;

            return {
                item: medicines.find((m) => m.id === itemId),
                pack:
                    packName === null
                        ? null
                        : { name: packName, units: unitsPerPack },
            };
        }

        const item = itemOf(line.key);

        return {
            item,
            pack:
                item?.packs.find((p) => p.id === enteredOf(line.key).pack) ??
                null,
        };
    };

    return (
        <>
            <Head title="Receive goods" />
            <PageHeader
                title={
                    order
                        ? `Receive goods for ${order.number}`
                        : 'Receive goods'
                }
            />

            <Panel className="max-w-5xl">
                <PanelBody>
                    <Form {...store.form()} className="grid gap-4">
                        {({ errors, processing }) => (
                            <>
                                {order && (
                                    <input
                                        type="hidden"
                                        name="purchase_order_id"
                                        value={order.id}
                                    />
                                )}
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="supplier_id">
                                            Supplier
                                        </Label>
                                        {order ? (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="supplier_id"
                                                    value={order.supplierId}
                                                />
                                                <p className="text-sm">
                                                    {
                                                        suppliers.find(
                                                            (s) =>
                                                                s.id ===
                                                                order.supplierId,
                                                        )?.name
                                                    }
                                                </p>
                                            </>
                                        ) : (
                                            <select
                                                id="supplier_id"
                                                name="supplier_id"
                                                className={selectClass}
                                            >
                                                {suppliers.map((supplier) => (
                                                    <option
                                                        key={supplier.id}
                                                        value={supplier.id}
                                                    >
                                                        {supplier.name}
                                                    </option>
                                                ))}
                                            </select>
                                        )}
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="supplier_reference">
                                            Supplier invoice / delivery note
                                        </Label>
                                        <Input
                                            id="supplier_reference"
                                            name="supplier_reference"
                                        />
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="received_on">
                                            Received on
                                        </Label>
                                        <Input
                                            id="received_on"
                                            name="received_on"
                                            type="date"
                                            defaultValue={today}
                                            max={today}
                                            required
                                        />
                                    </div>
                                </div>
                                {errors.purchase_order_id && (
                                    <p className="text-sm text-destructive">
                                        {errors.purchase_order_id}
                                    </p>
                                )}
                                {errors.expires_on && (
                                    <p className="text-sm text-destructive">
                                        {errors.expires_on}
                                    </p>
                                )}

                                <fieldset className="grid gap-3">
                                    <legend className="mb-1 text-sm font-medium">
                                        What arrived
                                    </legend>
                                    {lines.map((line, i) => {
                                        const error = (field: string) =>
                                            errors[`lines.${i}.${field}`];

                                        return (
                                            <div
                                                key={line.key}
                                                className="grid items-start gap-2 rounded-md border p-3 sm:grid-cols-[1fr_8rem_9rem_7rem_7rem_auto]"
                                            >
                                                <div>
                                                    {line.orderLine ? (
                                                        <>
                                                            <input
                                                                type="hidden"
                                                                name={`lines[${i}][purchase_order_line_id]`}
                                                                value={
                                                                    line
                                                                        .orderLine
                                                                        .id
                                                                }
                                                            />
                                                            <input
                                                                type="hidden"
                                                                name={`lines[${i}][item_id]`}
                                                                value={
                                                                    line
                                                                        .orderLine
                                                                        .itemId
                                                                }
                                                            />
                                                            <p className="text-sm font-medium">
                                                                {
                                                                    line
                                                                        .orderLine
                                                                        .item
                                                                }
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    line
                                                                        .orderLine
                                                                        .remaining
                                                                }
                                                                {line.orderLine
                                                                    .pack &&
                                                                    ` × ${line.orderLine.pack}`}{' '}
                                                                still expected
                                                            </p>
                                                        </>
                                                    ) : (
                                                        <div className="grid gap-2">
                                                            <select
                                                                name={`lines[${i}][item_id]`}
                                                                aria-label={`Product, line ${i + 1}`}
                                                                className={
                                                                    selectClass
                                                                }
                                                                onChange={(
                                                                    e,
                                                                ) => {
                                                                    setChosen({
                                                                        ...chosen,
                                                                        [line.key]:
                                                                            e
                                                                                .target
                                                                                .value,
                                                                    });
                                                                    // The pack list remounts on the unit.
                                                                    enter(
                                                                        line.key,
                                                                        {
                                                                            pack: '',
                                                                        },
                                                                    );
                                                                }}
                                                            >
                                                                {medicines.map(
                                                                    (
                                                                        medicine,
                                                                    ) => (
                                                                        <option
                                                                            key={
                                                                                medicine.id
                                                                            }
                                                                            value={
                                                                                medicine.id
                                                                            }
                                                                        >
                                                                            {
                                                                                medicine.label
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                            <PackSelect
                                                                name={`lines[${i}][pack_id]`}
                                                                line={i + 1}
                                                                item={itemOf(
                                                                    line.key,
                                                                )}
                                                                onChange={(
                                                                    pack,
                                                                ) =>
                                                                    enter(
                                                                        line.key,
                                                                        {
                                                                            pack,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                    )}
                                                    {locations.length > 0 && (
                                                        <select
                                                            name={`lines[${i}][location_id]`}
                                                            aria-label={`Put away at, line ${i + 1}`}
                                                            className={`${selectClass} mt-2`}
                                                        >
                                                            <option value="">
                                                                Not put away
                                                            </option>
                                                            {locations.map(
                                                                (location) => (
                                                                    <option
                                                                        key={
                                                                            location.id
                                                                        }
                                                                        value={
                                                                            location.id
                                                                        }
                                                                    >
                                                                        {
                                                                            location.name
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                    )}
                                                    {(error('location_id') ||
                                                        error('item_id') ||
                                                        error('pack_id') ||
                                                        error(
                                                            'purchase_order_line_id',
                                                        )) && (
                                                        <p className="text-xs text-destructive">
                                                            {error(
                                                                'location_id',
                                                            ) ??
                                                                error(
                                                                    'item_id',
                                                                ) ??
                                                                error(
                                                                    'pack_id',
                                                                ) ??
                                                                error(
                                                                    'purchase_order_line_id',
                                                                )}
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Input
                                                        name={`lines[${i}][batch_number]`}
                                                        required
                                                        placeholder="Batch"
                                                        aria-label={`Batch number, line ${i + 1}`}
                                                    />
                                                    {error('batch_number') && (
                                                        <p className="text-xs text-destructive">
                                                            {error(
                                                                'batch_number',
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    {/* Only products that track expiry carry a date. */}
                                                    {(breakdownOf(line).item
                                                        ?.tracksExpiry ??
                                                    false) ? (
                                                        <Input
                                                            name={`lines[${i}][expires_on]`}
                                                            type="date"
                                                            required
                                                            aria-label={`Expiry date, line ${i + 1}`}
                                                        />
                                                    ) : (
                                                        <p className="flex h-9 items-center text-sm text-muted-foreground">
                                                            No expiry
                                                        </p>
                                                    )}
                                                    {error('expires_on') && (
                                                        <p className="text-xs text-destructive">
                                                            {error(
                                                                'expires_on',
                                                            )}
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Input
                                                        name={`lines[${i}][quantity]`}
                                                        type="number"
                                                        min={1}
                                                        max={
                                                            line.orderLine
                                                                ?.remaining
                                                        }
                                                        defaultValue={
                                                            line.orderLine
                                                                ?.remaining
                                                        }
                                                        required
                                                        placeholder="Qty"
                                                        aria-label={`Quantity, line ${i + 1}`}
                                                        onChange={(e) =>
                                                            enter(line.key, {
                                                                quantity:
                                                                    e.target
                                                                        .value,
                                                            })
                                                        }
                                                    />
                                                    {error('quantity') && (
                                                        <p className="text-xs text-destructive">
                                                            {error('quantity')}
                                                        </p>
                                                    )}
                                                </div>
                                                <div>
                                                    <Input
                                                        name={`lines[${i}][unit_cost]`}
                                                        inputMode="decimal"
                                                        defaultValue={
                                                            line.orderLine
                                                                ?.unitCost
                                                        }
                                                        required
                                                        placeholder="Cost each"
                                                        aria-label={`Cost each, line ${i + 1}`}
                                                        onChange={(e) =>
                                                            enter(line.key, {
                                                                cost: e.target
                                                                    .value,
                                                            })
                                                        }
                                                    />
                                                    {error('unit_cost') && (
                                                        <p className="text-xs text-destructive">
                                                            {error('unit_cost')}
                                                        </p>
                                                    )}
                                                </div>
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
                                                <div className="sm:col-span-6">
                                                    <CostBreakdown
                                                        {...breakdownOf(line)}
                                                        cost={
                                                            enteredOf(line.key)
                                                                .cost
                                                        }
                                                        quantity={
                                                            enteredOf(line.key)
                                                                .quantity
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        );
                                    })}
                                    {!order && (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="justify-self-start"
                                            onClick={() => {
                                                setLines([
                                                    ...lines,
                                                    {
                                                        key: nextKey,
                                                        orderLine: null,
                                                    },
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
                                    )}
                                </fieldset>

                                <div className="flex gap-3">
                                    <Button type="submit" disabled={processing}>
                                        Receive into stock
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
