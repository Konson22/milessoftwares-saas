import { Form, Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useMoney } from '@/lib/use-money';
import { voidMethod } from '@/routes/sales';

type Props = {
    sale: {
        id: string;
        number: string;
        status: string;
        product: string;
        nozzle: string;
        attendant: string;
        litres: string;
        totalMinor: number;
        priceMinor: number;
        costMinor: number;
        voidReason: string | null;
        payments: {
            method: string;
            kind: string;
            amountMinor: number;
            reference: string | null;
        }[];
    };
    canVoid: boolean;
};

export default function SaleShow({ sale, canVoid }: Props) {
    const money = useMoney();

    return (
        <>
            <Head title={sale.number} />
            <PageHeader
                title={sale.number}
                description={`${sale.litres} L ${sale.product} at ${sale.nozzle}, sold by ${sale.attendant}.`}
            />
            <Panel>
                <PanelBody className="grid gap-3 text-sm">
                    <p>
                        {money(sale.totalMinor)} · cost {money(sale.costMinor)}
                    </p>
                    <StatusBadge
                        tone={sale.status === 'posted' ? 'success' : 'danger'}
                    >
                        {sale.status}
                    </StatusBadge>
                    <ul>
                        {sale.payments.map((payment, index) => (
                            <li key={`${payment.kind}-${index}`}>
                                {payment.kind}: {money(payment.amountMinor)}
                            </li>
                        ))}
                    </ul>
                    {sale.voidReason && <p>{sale.voidReason}</p>}
                    {canVoid && (
                        <Form
                            {...voidMethod.form(sale.id)}
                            className="flex gap-2"
                        >
                            <Input
                                name="reason"
                                placeholder="Reason"
                                aria-label="Void reason"
                                required
                            />
                            <Button type="submit" variant="outline">
                                Void
                            </Button>
                        </Form>
                    )}
                    {sale.status === 'posted' && (
                        <p className="text-muted-foreground">
                            A sale paid with more than one non-credit method
                            cannot be voided here.
                        </p>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
