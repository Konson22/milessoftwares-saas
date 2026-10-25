import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { index, store, update } from '@/routes/menu';

type MenuItem = {
    id: string;
    kind: 'dish' | 'drink';
    name: string;
    section: string | null;
    unit: string;
    barcode: string | null;
    price: string;
    reorderLevel: number;
    active: boolean;
    /** A drink with stock stays a drink. */
    hasStock: boolean;
};

type Props = {
    item: MenuItem | null;
    sections: string[];
};

function Field({
    id,
    label,
    error,
    hint,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && (
                <p id={`${id}-error`} className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </div>
    );
}

/**
 * Adding or changing a dish or a drink on the menu.
 */
export default function MenuItemForm({ item, sections }: Props) {
    const action = item ? update.form(item.id) : store.form();
    const [kind, setKind] = useState<'dish' | 'drink'>(item?.kind ?? 'dish');
    const drink = kind === 'drink';

    return (
        <>
            <Head title={item ? item.name : 'Add to the menu'} />
            <PageHeader
                title={item ? item.name : 'Add to the menu'}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Menu</Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <fieldset className="grid gap-2 sm:col-span-2">
                                    <legend className="mb-1 text-sm font-medium">
                                        Kind
                                    </legend>
                                    {(
                                        [
                                            [
                                                'dish',
                                                'Dish',
                                                'Made to order in the kitchen; keeps no stock.',
                                            ],
                                            [
                                                'drink',
                                                'Drink',
                                                'Bought and sold as it is (a soda, water); kept in stock.',
                                            ],
                                        ] as const
                                    ).map(([value, label, hint]) => (
                                        <label
                                            key={value}
                                            className="flex items-start gap-2 text-sm"
                                        >
                                            <input
                                                type="radio"
                                                name="kind"
                                                value={value}
                                                checked={kind === value}
                                                disabled={
                                                    item?.hasStock &&
                                                    value === 'dish'
                                                }
                                                onChange={() => setKind(value)}
                                                className="mt-1"
                                            />
                                            <span>
                                                <span className="font-medium">
                                                    {label}
                                                </span>
                                                <span className="block text-muted-foreground">
                                                    {hint}
                                                </span>
                                            </span>
                                        </label>
                                    ))}
                                    {errors.kind && (
                                        <p className="text-sm text-destructive">
                                            {errors.kind}
                                        </p>
                                    )}
                                </fieldset>

                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={item?.name}
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="section"
                                    label="Section of the menu"
                                    error={errors.section}
                                    hint="Starters, Mains, Drinks…"
                                >
                                    <Input
                                        id="section"
                                        name="section"
                                        list="menu-sections"
                                        defaultValue={item?.section ?? ''}
                                    />
                                    <datalist id="menu-sections">
                                        {sections.map((section) => (
                                            <option
                                                key={section}
                                                value={section}
                                            />
                                        ))}
                                    </datalist>
                                </Field>
                                <Field
                                    id="price"
                                    label="Price"
                                    error={errors.price}
                                >
                                    <Input
                                        id="price"
                                        name="price"
                                        required
                                        inputMode="decimal"
                                        defaultValue={item?.price}
                                        aria-invalid={
                                            errors.price ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="unit"
                                    label="Sold by the"
                                    error={errors.unit}
                                    hint={
                                        drink
                                            ? 'bottle, can, glass'
                                            : 'plate, bowl, cup'
                                    }
                                >
                                    <Input
                                        id="unit"
                                        name="unit"
                                        defaultValue={item?.unit ?? ''}
                                        placeholder={drink ? 'bottle' : 'plate'}
                                    />
                                </Field>
                                {drink && (
                                    <>
                                        <Field
                                            id="barcode"
                                            label="Barcode"
                                            error={errors.barcode}
                                        >
                                            <Input
                                                id="barcode"
                                                name="barcode"
                                                defaultValue={
                                                    item?.barcode ?? ''
                                                }
                                            />
                                        </Field>
                                        <Field
                                            id="reorder_level"
                                            label="Reorder at"
                                            error={errors.reorder_level}
                                            hint="Shown as running low at or below this many."
                                        >
                                            <Input
                                                id="reorder_level"
                                                name="reorder_level"
                                                type="number"
                                                min={0}
                                                defaultValue={
                                                    item?.reorderLevel ?? 0
                                                }
                                            />
                                        </Field>
                                    </>
                                )}
                                <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                    <input
                                        type="hidden"
                                        name="active"
                                        value="0"
                                    />
                                    <input
                                        type="checkbox"
                                        name="active"
                                        value="1"
                                        defaultChecked={item?.active ?? true}
                                    />
                                    On the menu
                                </label>
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {item ? 'Save' : 'Add to the menu'}
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
