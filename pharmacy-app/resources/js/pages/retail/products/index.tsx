import { Form, Head, Link } from '@inertiajs/react';
import { Tags } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { PriceBadges } from '@/components/price-badges';
import { create, edit, index } from '@/routes/retail/products';

type Product = {
    id: string;
    name: string;
    unit: string;
    barcode: string | null;
    category: string | null;
    tracksExpiry: boolean;
    sellPriceMinor: number;
    /** null: never received, so no buying price yet. */
    lastUnitCostMinor: number | null;
    reorderLevel: number;
    active: boolean;
};

type Props = {
    products: Product[];
    filters: { search: string; category: string; inactive: boolean };
    categories: string[];
    canManage: boolean;
};

/**
 * The shop's catalogue, shared by every branch.
 */
export default function Products({
    products,
    filters,
    categories,
    canManage,
}: Props) {
    return (
        <>
            <Head title="Products" />
            <PageHeader
                title="Products"
                actions={
                    canManage ? (
                        <Button asChild>
                            <Link href={create()}>Add product</Link>
                        </Button>
                    ) : undefined
                }
            />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-3"
                    >
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Name or barcode"
                            aria-label="Search products"
                            className="max-w-sm"
                        />
                        {categories.length > 0 && (
                            <select
                                name="category"
                                defaultValue={filters.category}
                                aria-label="Category"
                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                            >
                                <option value="">All categories</option>
                                {categories.map((category) => (
                                    <option key={category} value={category}>
                                        {category}
                                    </option>
                                ))}
                            </select>
                        )}
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                name="inactive"
                                value="1"
                                defaultChecked={filters.inactive}
                            />
                            Include inactive
                        </label>
                        <Button type="submit" variant="outline">
                            Search
                        </Button>
                    </Form>
                </PanelToolbar>

                {products.length === 0 ? (
                    <EmptyState
                        icon={Tags}
                        title="No products yet"
                        action={
                            canManage ? (
                                <Button asChild>
                                    <Link href={create()}>Add product</Link>
                                </Button>
                            ) : undefined
                        }
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Product</TableHead>
                                <TableHead>Barcode</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead>Prices</TableHead>
                                <TableHead>Flags</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {products.map((product) => (
                                <TableRow key={product.id}>
                                    <TableCell>
                                        {canManage ? (
                                            <Link
                                                href={edit(product.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {product.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {product.name}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {product.barcode ?? '–'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {product.category ?? '–'}
                                    </TableCell>
                                    <TableCell>{product.unit}</TableCell>
                                    <TableCell>
                                        <PriceBadges
                                            sellMinor={product.sellPriceMinor}
                                            buyMinor={product.lastUnitCostMinor}
                                            unit={product.unit}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap gap-1">
                                            {product.tracksExpiry && (
                                                <StatusBadge tone="info">
                                                    Dated
                                                </StatusBadge>
                                            )}
                                            {!product.active && (
                                                <StatusBadge tone="neutral">
                                                    Inactive
                                                </StatusBadge>
                                            )}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
