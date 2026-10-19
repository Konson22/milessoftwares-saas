import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { index, update } from '@/routes/retail/price-lists';
import { update as updatePrices } from '@/routes/retail/price-lists/prices';

type Props = {
    priceList: { id: string; name: string; active: boolean };
    items: {
        id: string;
        name: string;
        unit: string;
        category: string | null;
        rows: {
            packId: string | null;
            label: string;
            retailMinor: number;
            priceMinor: number | null;
        }[];
    }[];
};

/**
 * A price list's prices, by the unit and by the pack, next to the shop's
 * own. An empty price takes the item off the list.
 */
export default function ShowPriceList({ priceList, items }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const decimals = money(0).includes('.') ? 2 : 0;
    const major = (minor: number | null) =>
        minor === null ? '' : (minor / 10 ** decimals).toFixed(decimals);
    const [search, setSearch] = useState('');
    const term = search.trim().toLowerCase();
    const shown = (item: Props['items'][number]) =>
        term === '' ||
        item.name.toLowerCase().includes(term) ||
        (item.category?.toLowerCase().includes(term) ?? false);

    // One row per unit or pack, numbered across the whole list.
    const rows = items.flatMap((item) =>
        item.rows.map((price, i) => ({ item, price, first: i === 0 })),
    );

    return (
        <>
            <Head title={priceList.name} />
            <PageHeader
                title={priceList.name}
                description="Leave a price empty to sell at the shop's own price."
                actions={
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={index()}>← Price lists</Link>
                    </Button>
                }
            />

            <Panel className="mb-3 max-w-2xl">
                <PanelBody>
                    <Form
                        {...update.form(priceList.id)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Input
                                    name="name"
                                    defaultValue={priceList.name}
                                    required
                                    aria-label="Name"
                                    className="w-64"
                                />
                                <input type="hidden" name="active" value="0" />
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="active"
                                        value="1"
                                        defaultChecked={priceList.active}
                                    />
                                    In use on new quotations
                                </label>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Save
                                </Button>
                                {errors.name && (
                                    <p className="w-full text-sm text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>

            <Panel>
                <Form
                    {...updatePrices.form(priceList.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <PanelToolbar className="flex flex-wrap items-center justify-between gap-3">
                                <SearchField
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Product or category"
                                    aria-label="Find a product"
                                    className="max-w-sm"
                                />
                                <Button type="submit" disabled={processing}>
                                    Save prices
                                </Button>
                            </PanelToolbar>
                            {Object.keys(errors).length > 0 && (
                                <p className="px-4 pt-3 text-sm text-destructive">
                                    {Object.values(errors)[0]}
                                </p>
                            )}
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Product</TableHead>
                                        <TableHead>Sold as</TableHead>
                                        <TableHead className="text-right">
                                            Shop price
                                        </TableHead>
                                        <TableHead className="w-40 text-right">
                                            List price
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map(({ item, price, first }, row) => (
                                        <TableRow
                                            key={`${item.id}:${price.packId ?? 'unit'}`}
                                            className={
                                                shown(item)
                                                    ? undefined
                                                    : 'hidden'
                                            }
                                        >
                                            <TableCell className="font-medium">
                                                {first && item.name}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {price.label}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {money(price.retailMinor)}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <input
                                                    type="hidden"
                                                    name={`prices[${row}][item_id]`}
                                                    value={item.id}
                                                />
                                                <input
                                                    type="hidden"
                                                    name={`prices[${row}][pack_id]`}
                                                    value={price.packId ?? ''}
                                                />
                                                <Input
                                                    name={`prices[${row}][price]`}
                                                    inputMode="decimal"
                                                    defaultValue={major(
                                                        price.priceMinor,
                                                    )}
                                                    aria-label={`${item.name}, ${price.label}`}
                                                    className="ml-auto h-8 w-32 text-right"
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </>
                    )}
                </Form>
            </Panel>
        </>
    );
}
