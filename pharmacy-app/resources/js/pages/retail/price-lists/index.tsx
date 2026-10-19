import { Form, Head, Link } from '@inertiajs/react';
import { Tags } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { show, store } from '@/routes/retail/price-lists';

type Props = {
    priceLists: {
        id: string;
        name: string;
        active: boolean;
        prices: number;
        customers: number;
    }[];
};

/**
 * Price lists customers buy at on quotations (wholesale, trade). Items a
 * list does not price sell at the shop's own price.
 */
export default function PriceLists({ priceLists }: Props) {
    return (
        <>
            <Head title="Price lists" />
            <PageHeader
                title="Price lists"
                description="What customers on each list pay on quotations and orders."
            />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...store.form()}
                        resetOnSuccess
                        className="flex flex-wrap items-start gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Input
                                    name="name"
                                    required
                                    placeholder="New list, e.g. Wholesale"
                                    aria-label="Price list name"
                                    className="w-64"
                                    aria-invalid={
                                        errors.name ? true : undefined
                                    }
                                />
                                <Button type="submit" disabled={processing}>
                                    Add price list
                                </Button>
                                {errors.name && (
                                    <p className="w-full text-sm text-destructive">
                                        {errors.name}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelToolbar>

                {priceLists.length === 0 ? (
                    <EmptyState icon={Tags} title="No price lists yet">
                        Without one, customers buy at the shop's own prices.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Price list</TableHead>
                                <TableHead className="text-right">
                                    Prices set
                                </TableHead>
                                <TableHead className="text-right">
                                    Customers
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {priceLists.map((list) => (
                                <TableRow key={list.id}>
                                    <TableCell>
                                        <Link
                                            href={show(list.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {list.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {list.prices}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {list.customers}
                                    </TableCell>
                                    <TableCell>
                                        {!list.active && (
                                            <StatusBadge tone="neutral">
                                                Off
                                            </StatusBadge>
                                        )}
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
