import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { index, store, update } from '@/routes/medicines';

type Medicine = {
    id: string;
    name: string;
    genericName: string;
    form: string;
    strength: string | null;
    unit: string;
    barcode: string | null;
    category: string | null;
    storage: string;
    prescriptionOnly: boolean;
    controlled: boolean;
    sellPrice: string;
    reorderLevel: number;
    active: boolean;
    packs: { name: string; units: number; sellPrice: string }[];
};

type Props = {
    medicine: Medicine | null;
    forms: { value: string; label: string }[];
    storageConditions: { value: string; label: string }[];
    categories: string[];
};

function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error && (
                <p id={`${id}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

export default function MedicineForm({
    medicine,
    forms,
    storageConditions,
    categories,
}: Props) {
    const action = medicine ? update.form(medicine.id) : store.form();
    const [packs, setPacks] = useState(
        (medicine?.packs ?? []).map((pack, key) => ({ key, ...pack })),
    );
    const [nextKey, setNextKey] = useState(packs.length);

    return (
        <>
            <Head title={medicine ? `Edit ${medicine.name}` : 'Add medicine'} />
            <PageHeader
                title={medicine ? `Edit ${medicine.name}` : 'Add medicine'}
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={medicine?.name}
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="generic_name"
                                    label="Generic name"
                                    error={errors.generic_name}
                                >
                                    <Input
                                        id="generic_name"
                                        name="generic_name"
                                        required
                                        defaultValue={medicine?.genericName}
                                        aria-invalid={
                                            errors.generic_name
                                                ? true
                                                : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="form"
                                    label="Form"
                                    error={errors.form}
                                >
                                    <select
                                        id="form"
                                        name="form"
                                        defaultValue={
                                            medicine?.form ?? 'tablet'
                                        }
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                    >
                                        {forms.map((form) => (
                                            <option
                                                key={form.value}
                                                value={form.value}
                                            >
                                                {form.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    id="strength"
                                    label="Strength (e.g. 500 mg)"
                                    error={errors.strength}
                                >
                                    <Input
                                        id="strength"
                                        name="strength"
                                        defaultValue={medicine?.strength ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="unit"
                                    label="Unit of stock (tablet, bottle, tube)"
                                    error={errors.unit}
                                >
                                    <Input
                                        id="unit"
                                        name="unit"
                                        required
                                        defaultValue={
                                            medicine?.unit ?? 'tablet'
                                        }
                                    />
                                </Field>
                                <Field
                                    id="barcode"
                                    label="Barcode"
                                    error={errors.barcode}
                                >
                                    <Input
                                        id="barcode"
                                        name="barcode"
                                        defaultValue={medicine?.barcode ?? ''}
                                        aria-invalid={
                                            errors.barcode ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="category"
                                    label="Category (e.g. Antibiotics)"
                                    error={errors.category}
                                >
                                    <Input
                                        id="category"
                                        name="category"
                                        list="categories"
                                        defaultValue={medicine?.category ?? ''}
                                    />
                                    <datalist id="categories">
                                        {categories.map((category) => (
                                            <option
                                                key={category}
                                                value={category}
                                            />
                                        ))}
                                    </datalist>
                                </Field>
                                <Field
                                    id="storage"
                                    label="Storage"
                                    error={errors.storage}
                                >
                                    <select
                                        id="storage"
                                        name="storage"
                                        defaultValue={
                                            medicine?.storage ?? 'room'
                                        }
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                    >
                                        {storageConditions.map((storage) => (
                                            <option
                                                key={storage.value}
                                                value={storage.value}
                                            >
                                                {storage.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    id="sell_price"
                                    label="Selling price per unit"
                                    error={errors.sell_price}
                                >
                                    <Input
                                        id="sell_price"
                                        name="sell_price"
                                        inputMode="decimal"
                                        required
                                        defaultValue={medicine?.sellPrice}
                                        aria-invalid={
                                            errors.sell_price ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="reorder_level"
                                    label="Reorder level"
                                    error={errors.reorder_level}
                                >
                                    <Input
                                        id="reorder_level"
                                        name="reorder_level"
                                        type="number"
                                        min={0}
                                        required
                                        defaultValue={
                                            medicine?.reorderLevel ?? 0
                                        }
                                    />
                                </Field>

                                <fieldset className="grid gap-2 sm:col-span-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Packs
                                    </legend>
                                    <p className="text-xs text-muted-foreground">
                                        Stock is always counted in the unit
                                        above; orders, deliveries and the
                                        counter can count packs (a box of 30).
                                        Leave the pack price empty to charge its
                                        units at the unit price.
                                    </p>
                                    {packs.map((pack, i) => (
                                        <div
                                            key={pack.key}
                                            className="grid items-start gap-2 sm:grid-cols-[1fr_7rem_8rem_auto]"
                                        >
                                            <div>
                                                <Input
                                                    name={`packs[${i}][name]`}
                                                    required
                                                    defaultValue={pack.name}
                                                    placeholder="box, strip, carton"
                                                    aria-label={`Pack name, pack ${i + 1}`}
                                                />
                                                {errors[`packs.${i}.name`] && (
                                                    <p className="text-xs text-destructive">
                                                        {
                                                            errors[
                                                                `packs.${i}.name`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <Input
                                                    name={`packs[${i}][units]`}
                                                    type="number"
                                                    min={2}
                                                    required
                                                    defaultValue={
                                                        pack.units || undefined
                                                    }
                                                    placeholder="Units in it"
                                                    aria-label={`Units in pack ${i + 1}`}
                                                />
                                                {errors[`packs.${i}.units`] && (
                                                    <p className="text-xs text-destructive">
                                                        {
                                                            errors[
                                                                `packs.${i}.units`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <div>
                                                <Input
                                                    name={`packs[${i}][sell_price]`}
                                                    inputMode="decimal"
                                                    defaultValue={
                                                        pack.sellPrice
                                                    }
                                                    placeholder="Pack price"
                                                    aria-label={`Selling price of pack ${i + 1}`}
                                                />
                                                {errors[
                                                    `packs.${i}.sell_price`
                                                ] && (
                                                    <p className="text-xs text-destructive">
                                                        {
                                                            errors[
                                                                `packs.${i}.sell_price`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </div>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                aria-label={`Remove pack ${i + 1}`}
                                                onClick={() =>
                                                    setPacks(
                                                        packs.filter(
                                                            (p) =>
                                                                p.key !==
                                                                pack.key,
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
                                            setPacks([
                                                ...packs,
                                                {
                                                    key: nextKey,
                                                    name: '',
                                                    units: 0,
                                                    sellPrice: '',
                                                },
                                            ]);
                                            setNextKey(nextKey + 1);
                                        }}
                                    >
                                        <Plus className="size-4" aria-hidden />
                                        Add pack
                                    </Button>
                                </fieldset>

                                <fieldset className="grid gap-2 sm:col-span-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Dispensing
                                    </legend>
                                    <input
                                        type="hidden"
                                        name="prescription_only"
                                        value="0"
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="prescription_only"
                                            value="1"
                                            defaultChecked={
                                                medicine?.prescriptionOnly
                                            }
                                        />
                                        Prescription only
                                    </label>
                                    <input
                                        type="hidden"
                                        name="controlled"
                                        value="0"
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="controlled"
                                            value="1"
                                            defaultChecked={
                                                medicine?.controlled
                                            }
                                        />
                                        Controlled drug (always prescription
                                        only, kept in the controlled drugs
                                        register)
                                    </label>
                                    <input
                                        type="hidden"
                                        name="active"
                                        value="0"
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={
                                                medicine?.active ?? true
                                            }
                                        />
                                        Active (can be stocked and sold)
                                    </label>
                                </fieldset>

                                <div className="flex gap-3 sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        Save
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
