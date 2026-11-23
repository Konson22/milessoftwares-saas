import { Head, router } from '@inertiajs/react';
import { ShoppingCart } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SelectField } from '@/components/fields';
import { OrdersTable } from '@/components/orders-table';
import type { OrderRow } from '@/components/orders-table';
import { status as statusLabel } from '@/lib/procurement';
import { index } from '@/routes/orders';

type Props = {
    status: string;
    orders: OrderRow[];
    statuses: string[];
};

export default function Orders({ status, orders, statuses }: Props) {
    return (
        <>
            <Head title="Purchase orders" />
            <PageHeader title="Purchase orders" />
            <Panel>
                <PanelToolbar className="flex items-end gap-4">
                    <SelectField
                        label="Status"
                        value={status}
                        onChange={(value) =>
                            router.get(
                                index().url,
                                { status: value || undefined },
                                { preserveState: true, replace: true },
                            )
                        }
                        options={statuses.map((value) => ({
                            value,
                            label: statusLabel(value).label,
                        }))}
                        empty="Any status"
                        className="w-48"
                    />
                </PanelToolbar>
                {orders.length === 0 ? (
                    <EmptyState icon={ShoppingCart} title="No purchase orders">
                        Orders come from an approved award, or directly from an
                        approved requisition within the direct purchase limit.
                    </EmptyState>
                ) : (
                    <OrdersTable orders={orders} />
                )}
            </Panel>
        </>
    );
}
