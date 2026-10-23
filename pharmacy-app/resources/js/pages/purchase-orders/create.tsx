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
import { index, store } from '@/routes/purchase-orders';

type Props = {
    suppliers: { id: string; name: string }[];
    items: BuyableItem[];
};

type Entered = { pack: string; cost: string; quantity: string };

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

export default function CreatePurchaseOrder({ suppliers, items: medicines }: Props) {
    const [lines, setLines] = useState([0]);
    const [nextKey, setNextKey] = useState(1);
    // The medicine chosen on each line (by line key), for its packs.
    const [chosen, setChosen] = useState<Record<number, string>>({});
    const itemOf = (key: number) =>
        medicines.find((m) => m.id === (chosen[key] ?? medicines[0]?.id));
    // What has been typed on each line (by line key), for its cost breakdown.
    const [entered, setEntered] = useState<Record<number, Entered>>({});
    const enteredOf = (key: number): Entered =>
        entered[key] ?? { pack: '', cost: '', quantity: '' };
    const enter = (key: number, change: Partial<Entered>) =>
        setEntered({ ...entered, [key]: { ...enteredOf(key), ...change } });
    const packOf = (key: number) =>
        itemOf(key)?.packs.find((p) => p.id === enteredOf(key).pack) ?? null;

    return (
        <>
            <Head title="New purchase order" />
            <PageHeader title="New purchase order" />

            {suppliers.length === 0 ? (
                <Panel className="max-w-4xl">
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            Add a supplier first.
                        </p>
                    </PanelBody>
                </Panel>
            ) : (
                <Panel className="max-w-4xl">
                    <PanelBody>
                        <Form {...store.form()} className="grid gap-4">
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-3">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="supplier_id">
                                                Supplier
                                            </Label>
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
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="expected_on">
                                                Expected on
                                            </Label>
                                            <Input
                                                id="expected_on"
                                                name="expected_on"
                                                type="date"
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="notes">Notes</Label>
                                            <Input id="notes" name="notes" />
                                        </div>
                                    </div>

                                    <fieldset className="grid gap-2">
                                        <legend className="mb-1 text-sm font-medium">
                                            Lines
                                        </legend>
                                        {lines.map((key, i) => (
                                            <div
                                                key={key}
                                                className="grid items-start gap-2 sm:grid-cols-[1fr_9rem_7rem_8rem_auto]"
                                            >
                                                <div>
                                                    <select
                                                        name={`lines[${i}][item_id]`}
                                                        aria-label={`Medicine, line ${i + 1}`}
                                                        className={selectClass}
                                                        onChange={(e) => {
                                                            setChosen({
                                                                ...chosen,
                                                                [key]: e.target
                                                                    .value,
                                                            });
                                                            // The pack list remounts on the unit.
                                                            enter(key, {
                                                                pack: '',
                                                            });
                                                        }}
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
                                                <div>
                                                    <PackSelect
                                                        name={`lines[${i}][pack_id]`}
                                                        line={i + 1}
                                                        item={itemOf(key)}
                                                        onChange={(pack) =>
                                                            enter(key, { pack })
                                                        }
                                                    />
                                                    {errors[
                                                        `lines.${i}.pack_id`
                                                    ] && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                errors[
                                                                    `lines.${i}.pack_id`
                                                                ]
                                                            }
                                                        </p>
                                                    )}
                                                </div>
                                                <Input
                                                    name={`lines[${i}][quantity]`}
                                                    type="number"
                                                    min={1}
                                                    required
                                                    placeholder="Quantity"
                                                    aria-label={`Quantity, line ${i + 1}`}
                                                    onChange={(e) =>
                                                        enter(key, {
                                                            quantity:
                                                                e.target.value,
                                                        })
                                                    }
                                                />
                                                <Input
                                                    name={`lines[${i}][unit_cost]`}
                                                    inputMode="decimal"
                                                    required
                                                    placeholder="Cost each"
                                                    aria-label={`Cost each, line ${i + 1}`}
                                                    onChange={(e) =>
                                                        enter(key, {
                                                            cost: e.target
                                                                .value,
                                                        })
                                                    }
                                                />
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
                                                                (k) =>
                                                                    k !== key,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Trash2
                                                        className="size-4"
                                                        aria-hidden
                                                    />
                                                </Button>
                                                <div className="sm:col-span-5">
                                                    <CostBreakdown
                                                        item={itemOf(key)}
                                                        pack={packOf(key)}
                                                        cost={
                                                            enteredOf(key).cost
                                                        }
                                                        quantity={
                                                            enteredOf(key)
                                                                .quantity
                                                        }
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="justify-self-start"
                                            onClick={() => {
                                                setLines([...lines, nextKey]);
                                                setNextKey(nextKey + 1);
                                            }}
                                        >
                                            <Plus
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Add line
                                        </Button>
                                        {errors.lines && (
                                            <p className="text-sm text-destructive">
                                                {errors.lines}
                                            </p>
                                        )}
                                    </fieldset>

                                    <div className="flex gap-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save draft
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
            )}
        </>
    );
}
