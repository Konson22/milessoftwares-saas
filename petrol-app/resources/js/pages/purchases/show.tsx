import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { receive } from '@/routes/purchases';

type Props = {
    order: {
        id: string;
        number: string;
        supplier: string;
        status: string;
        orderedOn: string;
        lines: {
            id: string;
            product: string;
            productId: string;
            ordered: string;
            received: string;
            remaining: string;
            unitCostMinor: number;
        }[];
    };
    receipts: {
        id: string;
        number: string;
        measured: string;
        declared: string;
    }[];
    tanks: { id: string; code: string; fuel_product_id: string }[];
    canManage: boolean;
};

export default function PurchaseShow({
    order,
    receipts,
    tanks,
    canManage,
}: Props) {
    const money = useMoney();

    return (
        <>
            <Head title={order.number} />
            <PageHeader
                title={order.number}
                description={`${order.supplier} · ${order.status} · ${order.orderedOn}`}
            />
            {order.lines.map((line) => (
                <Panel key={line.id} className="mb-4">
                    <PanelHeader
                        title={line.product}
                        description={`Ordered ${line.ordered} L, received ${line.received} L, remaining ${line.remaining} L at ${money(line.unitCostMinor)}.`}
                    />
                    <PanelBody>
                        {canManage && (
                            <Form
                                {...receive.form(order.id)}
                                className="grid gap-3 md:grid-cols-3"
                            >
                                <input
                                    type="hidden"
                                    name="purchase_line_id"
                                    value={line.id}
                                />
                                <Select
                                    name="tank_id"
                                    aria-label="Tank"
                                    required
                                    placeholder="Tank"
                                    options={tanks
                                        .filter(
                                            (tank) =>
                                                tank.fuel_product_id ===
                                                line.productId,
                                        )
                                        .map((tank) => ({
                                            value: tank.id,
                                            label: tank.code,
                                        }))}
                                />
                                <Input
                                    name="declared"
                                    placeholder="Declared litres"
                                    aria-label="Declared litres"
                                    inputMode="decimal"
                                    required
                                />
                                <Input
                                    name="measured"
                                    placeholder="Measured litres"
                                    aria-label="Measured litres"
                                    inputMode="decimal"
                                    required
                                />
                                <Input
                                    name="unit_cost"
                                    placeholder="Cost per litre"
                                    aria-label="Cost"
                                    inputMode="decimal"
                                    defaultValue={(
                                        line.unitCostMinor / 100
                                    ).toFixed(2)}
                                    required
                                />
                                <Input
                                    name="tanker"
                                    placeholder="Tanker"
                                    aria-label="Tanker"
                                />
                                <Button type="submit">Receive</Button>
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            ))}
            <Panel>
                <PanelHeader title="Receipts" />
                <PanelBody>
                    <ul className="text-sm">
                        {receipts.map((receipt) => (
                            <li key={receipt.id}>
                                {receipt.number}: measured {receipt.measured} L,
                                declared {receipt.declared} L
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
