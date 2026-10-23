import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { PriceBadges } from '@/components/price-badges';
import { formatMoney, parseMoney } from '@/lib/money';
import { index, store, update } from '@/routes/retail/products';

type Product = {
    id: string;
    name: string;
    unit: string;
    barcode: string | null;
    category: string | null;
    tracksExpiry: boolean;
    tracksSerials: boolean;
    sellPrice: string;
    /** null: never received, so no buying price yet. */
    lastUnitCostMinor: number | null;
    /** Stock has been added: expiry tracking can no longer change. */
    hasStock: boolean;
    reorderLevel: number;
    active: boolean;
    packs: { name: string; units: number; sellPrice: string }[];
};

type Props = {
    product: Product | null;
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

/**
 * What a pack sells for and costs, worked out per unit: its own price split
 * over its units (a box of 10 at 100 is 10 a unit), or its units at the
 * unit price; and the last unit buying price times its units.
 */
function PackPrices({
    name,
    units,
    unit,
    packSellMinor,
    unitSellMinor,
    lastCostMinor,
    money,
}: {
    name: string;
    units: number;
    unit: string;
    /** null: no pack price, so its units at the unit price. */
    packSellMinor: number | null;
    unitSellMinor: number | null;
    lastCostMinor: number | null;
    money: (minor: number) => string;
}) {
    if (units < 2) {
        return null;
    }

    const sellMinor =
        packSellMinor ??
        (unitSellMinor === null ? null : unitSellMinor * units);
    const costMinor = lastCostMinor === null ? null : lastCostMinor * units;
    const margin =
        sellMinor === null || costMinor === null ? null : sellMinor - costMinor;

    return (
        <span className="inline-flex flex-wrap items-center gap-1 tabular-nums sm:col-span-4">
            {packSellMinor !== null ? (
                <StatusBadge tone="success">
                    Sell {name} of {units} at {money(packSellMinor)} ={' '}
                    {money(Math.round(packSellMinor / units))} / {unit}
                </StatusBadge>
            ) : (
                sellMinor !== null && (
                    <StatusBadge tone="success">
                        Sell {name} {money(sellMinor)} ({units} ×{' '}
                        {money(unitSellMinor ?? 0)})
                    </StatusBadge>
                )
            )}
            {costMinor !== null && (
                <StatusBadge tone="info">
                    Last buy {money(costMinor)} / {name}
                </StatusBadge>
            )}
            {margin !== null && (
                <StatusBadge tone={margin > 0 ? 'success' : 'danger'}>
                    Margin {money(margin)} / {name}
                </StatusBadge>
            )}
        </span>
    );
}

/**
 * Adding or editing a product of the shop's catalogue.
 */
export default function ProductForm({ product, categories }: Props) {
    const action = product ? update.form(product.id) : store.form();
    const [packs, setPacks] = useState(
        (product?.packs ?? []).map((pack, key) => ({ key, ...pack })),
    );
    const [nextKey, setNextKey] = useState(packs.length);
    // The unit price and unit name as typed, for the price badges.
    const [sellPrice, setSellPrice] = useState(product?.sellPrice ?? '');
    const [unit, setUnit] = useState(product?.unit ?? 'piece');
    const { currency } = usePage().props;
    const code = currency ?? 'USD';
    const money = (minor: number) => formatMoney(minor, code);
    const unitSellMinor = parseMoney(sellPrice, code);
    const lastCostMinor = product?.lastUnitCostMinor ?? null;
    const setPack = (key: number, change: Partial<(typeof packs)[number]>) =>
        setPacks(packs.map((p) => (p.key === key ? { ...p, ...change } : p)));

    return (
        <>
            <Head title={product ? `Edit ${product.name}` : 'Add product'} />
            <PageHeader
                title={product ? `Edit ${product.name}` : 'Add product'}
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
                                        defaultValue={product?.name}
                                        aria-invalid={
                                            errors.name ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="barcode"
                                    label="Barcode (scan it here)"
                                    error={errors.barcode}
                                >
                                    <Input
                                        id="barcode"
                                        name="barcode"
                                        autoComplete="off"
                                        defaultValue={product?.barcode ?? ''}
                                        // A scanner's Enter must not submit the form.
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                e.preventDefault();
                                            }
                                        }}
                                        aria-invalid={
                                            errors.barcode ? true : undefined
                                        }
                                    />
                                </Field>
                                <Field
                                    id="unit"
                                    label="Unit of stock (piece, bottle, kg)"
                                    error={errors.unit}
                                >
                                    <Input
                                        id="unit"
                                        name="unit"
                                        required
                                        defaultValue={product?.unit ?? 'piece'}
                                        onChange={(e) =>
                                            setUnit(e.target.value)
                                        }
                                    />
                                </Field>
                                <Field
                                    id="category"
                                    label="Category (e.g. Beverages)"
                                    error={errors.category}
                                >
                                    <Input
                                        id="category"
                                        name="category"
                                        list="categories"
                                        defaultValue={product?.category ?? ''}
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
                                    id="sell_price"
                                    label="Selling price per unit"
                                    error={errors.sell_price}
                                >
                                    <Input
                                        id="sell_price"
                                        name="sell_price"
                                        inputMode="decimal"
                                        required
                                        defaultValue={product?.sellPrice}
                                        onChange={(e) =>
                                            setSellPrice(e.target.value)
                                        }
                                        aria-invalid={
                                            errors.sell_price ? true : undefined
                                        }
                                    />
                                    {unitSellMinor !== null && (
                                        <PriceBadges
                                            sellMinor={unitSellMinor}
                                            buyMinor={lastCostMinor}
                                            unit={unit || 'unit'}
                                            buyLabel="Last buy"
                                        />
                                    )}
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
                                            product?.reorderLevel ?? 0
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
                                        counter can count packs (a crate of 24).
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
                                                    onChange={(e) =>
                                                        setPack(pack.key, {
                                                            name: e.target
                                                                .value,
                                                        })
                                                    }
                                                    placeholder="pack, crate, carton"
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
                                                    onChange={(e) =>
                                                        setPack(pack.key, {
                                                            units:
                                                                Number.parseInt(
                                                                    e.target
                                                                        .value,
                                                                    10,
                                                                ) || 0,
                                                        })
                                                    }
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
                                                    onChange={(e) =>
                                                        setPack(pack.key, {
                                                            sellPrice:
                                                                e.target.value,
                                                        })
                                                    }
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
                                            <PackPrices
                                                name={pack.name || 'pack'}
                                                units={pack.units}
                                                unit={unit || 'unit'}
                                                packSellMinor={parseMoney(
                                                    pack.sellPrice,
                                                    code,
                                                )}
                                                unitSellMinor={unitSellMinor}
                                                lastCostMinor={lastCostMinor}
                                                money={money}
                                            />
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
                                        Settings
                                    </legend>
                                    <input
                                        type="hidden"
                                        name="tracks_expiry"
                                        value={
                                            product?.hasStock &&
                                            product.tracksExpiry
                                                ? '1'
                                                : '0'
                                        }
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="tracks_expiry"
                                            value="1"
                                            defaultChecked={
                                                product?.tracksExpiry ?? false
                                            }
                                            disabled={product?.hasStock}
                                        />
                                        Track expiry dates (food, cosmetics):
                                        stock is dated and sold oldest first
                                    </label>
                                    {product?.hasStock && (
                                        <p className="text-xs text-muted-foreground">
                                            Fixed now that the product has
                                            stock.
                                        </p>
                                    )}
                                    {errors.tracks_expiry && (
                                        <p className="text-sm text-destructive">
                                            {errors.tracks_expiry}
                                        </p>
                                    )}
                                    <input
                                        type="hidden"
                                        name="tracks_serials"
                                        value="0"
                                    />
                                    <label className="flex items-center gap-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="tracks_serials"
                                            value="1"
                                            defaultChecked={
                                                product?.tracksSerials ?? false
                                            }
                                        />
                                        Track serial numbers (phones,
                                        appliances): each unit sold names its
                                        serial
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
                                                product?.active ?? true
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
