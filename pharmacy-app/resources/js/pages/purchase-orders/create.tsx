import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/purchase-orders';

type Props = {
    suppliers: { id: string; name: string }[];
    medicines: { id: string; label: string; unit: string }[];
};

const selectClass = 'h-9 w-full rounded-md border bg-transparent px-3 text-sm';

export default function CreatePurchaseOrder({ suppliers, medicines }: Props) {
    const [lines, setLines] = useState([0]);
    const [nextKey, setNextKey] = useState(1);

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
                                                className="grid items-start gap-2 sm:grid-cols-[1fr_8rem_8rem_auto]"
                                            >
                                                <div>
                                                    <select
                                                        name={`lines[${i}][medicine_id]`}
                                                        aria-label={`Medicine, line ${i + 1}`}
                                                        className={selectClass}
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
                                                        `lines.${i}.medicine_id`
                                                    ] && (
                                                        <p className="text-xs text-destructive">
                                                            {
                                                                errors[
                                                                    `lines.${i}.medicine_id`
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
                                                />
                                                <Input
                                                    name={`lines[${i}][unit_cost]`}
                                                    inputMode="decimal"
                                                    required
                                                    placeholder="Unit cost"
                                                    aria-label={`Unit cost, line ${i + 1}`}
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
