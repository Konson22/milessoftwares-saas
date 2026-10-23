import { Form, Head, Link, router } from '@inertiajs/react';
import { ScanBarcode } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { show as showSale } from '@/routes/retail/sales';
import { index, store } from '@/routes/retail/serials';

type Props = {
    items: { id: string; label: string; inStock: number }[];
    serials: {
        id: string;
        serial: string;
        item: string;
        status: 'in_stock' | 'sold';
        recordedAt: string;
        sale: {
            id: string;
            number: string;
            customer: string | null;
            soldAt: string;
            here: boolean;
        } | null;
    }[];
    filters: { serial: string };
    canRecord: boolean;
};

/**
 * Serial numbers of products that track them: find one (a warranty, a
 * return), and record those of units as they arrive.
 */
export default function Serials({ items, serials, filters, canRecord }: Props) {
    const [search, setSearch] = useState(filters.serial);

    return (
        <>
            <Head title="Serial numbers" />
            <PageHeader
                title="Serial numbers"
                description="Each unit of a product that tracks serials is sold by its serial, and keeps the sale it left in."
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_22rem]">
                <Panel>
                    <form
                        className="flex flex-wrap gap-2 border-b px-4 py-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                index.url({ query: { serial: search } }),
                                {},
                                { preserveState: true },
                            );
                        }}
                    >
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Scan or type a serial number"
                            aria-label="Serial number"
                            className="h-8 max-w-xs"
                        />
                        <Button type="submit" size="sm" variant="outline">
                            Find
                        </Button>
                    </form>
                    {serials.length === 0 ? (
                        <EmptyState
                            icon={ScanBarcode}
                            title={
                                filters.serial
                                    ? 'No such serial number'
                                    : 'No serial numbers yet'
                            }
                        >
                            {items.length === 0
                                ? 'Turn on "Track serial numbers" on a product first.'
                                : 'Record the serials of units as they arrive.'}
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Serial</TableHead>
                                    <TableHead>Product</TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {serials.map((serial) => (
                                    <TableRow key={serial.id}>
                                        <TableCell className="font-mono">
                                            {serial.serial}
                                        </TableCell>
                                        <TableCell>{serial.item}</TableCell>
                                        <TableCell>
                                            {serial.sale === null ? (
                                                <StatusBadge tone="success">
                                                    In stock
                                                </StatusBadge>
                                            ) : (
                                                <span className="text-sm">
                                                    Sold{' '}
                                                    {new Date(
                                                        serial.sale.soldAt,
                                                    ).toLocaleDateString()}{' '}
                                                    on{' '}
                                                    {serial.sale.here ? (
                                                        <Link
                                                            href={showSale(
                                                                serial.sale.id,
                                                            )}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {serial.sale.number}
                                                        </Link>
                                                    ) : (
                                                        serial.sale.number
                                                    )}
                                                    {serial.sale.customer &&
                                                        `, to ${serial.sale.customer}`}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <div className="grid content-start gap-3">
                    {items.length > 0 && (
                        <Panel>
                            <PanelHeader title="In stock" />
                            <PanelBody className="grid gap-1 text-sm">
                                {items.map((item) => (
                                    <p
                                        key={item.id}
                                        className="flex justify-between"
                                    >
                                        <span>{item.label}</span>
                                        <span className="tabular-nums">
                                            {item.inStock}
                                        </span>
                                    </p>
                                ))}
                            </PanelBody>
                        </Panel>
                    )}

                    {canRecord && items.length > 0 && (
                        <Panel>
                            <PanelHeader title="Record serial numbers" />
                            <PanelBody>
                                <Form
                                    {...store.form()}
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="grid gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <select
                                                name="item_id"
                                                aria-label="Product"
                                                className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                            >
                                                {items.map((item) => (
                                                    <option
                                                        key={item.id}
                                                        value={item.id}
                                                    >
                                                        {item.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <Label htmlFor="serials">
                                                One per line (a scanner adds the
                                                line)
                                            </Label>
                                            <textarea
                                                id="serials"
                                                name="serials"
                                                rows={6}
                                                required
                                                className="rounded-md border bg-transparent px-3 py-2 font-mono text-sm"
                                            />
                                            {(errors.serials ||
                                                errors.item_id) && (
                                                <p className="text-sm text-destructive">
                                                    {errors.serials ??
                                                        errors.item_id}
                                                </p>
                                            )}
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Record
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}
