import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/receipts';

type OrderLine = {
    id: string;
    medicineId: string;
    medicine: string;
    remaining: number;
    unitCost: string;
};

type Props = {
    suppliers: { id: string; name: string }[];
    medicines: { id: string; label: string; unit: string }[];
    order: {
        id: string;
        number: string;
        supplierId: string;
        lines: OrderLine[];
    } | null;
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
    medicines,
    order,
    today,
}: Props) {
    const [lines, setLines] = useState<Line[]>(
        order
            ? order.lines.map((orderLine, key) => ({ key, orderLine }))
            : [{ key: 0, orderLine: null }],
    );
    const [nextKey, setNextKey] = useState(lines.length);

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
                                                                name={`lines[${i}][medicine_id]`}
                                                                value={
                                                                    line
                                                                        .orderLine
                                                                        .medicineId
                                                                }
                                                            />
                                                            <p className="text-sm font-medium">
                                                                {
                                                                    line
                                                                        .orderLine
                                                                        .medicine
                                                                }
                                                            </p>
                                                            <p className="text-xs text-muted-foreground">
                                                                {
                                                                    line
                                                                        .orderLine
                                                                        .remaining
                                                                }{' '}
                                                                still expected
                                                            </p>
                                                        </>
                                                    ) : (
                                                        <select
                                                            name={`lines[${i}][medicine_id]`}
                                                            aria-label={`Medicine, line ${i + 1}`}
                                                            className={
                                                                selectClass
                                                            }
                                                        >
                                                            {medicines.map(
                                                                (medicine) => (
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
                                                    )}
                                                    {(error('medicine_id') ||
                                                        error(
                                                            'purchase_order_line_id',
                                                        )) && (
                                                        <p className="text-xs text-destructive">
                                                            {error(
                                                                'medicine_id',
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
                                                    <Input
                                                        name={`lines[${i}][expires_on]`}
                                                        type="date"
                                                        required
                                                        aria-label={`Expiry date, line ${i + 1}`}
                                                    />
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
                                                        placeholder="Unit cost"
                                                        aria-label={`Unit cost, line ${i + 1}`}
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
