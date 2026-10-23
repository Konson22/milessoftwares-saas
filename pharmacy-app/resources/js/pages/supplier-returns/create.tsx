import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
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
import { formatMoney } from '@/lib/money';
import { store } from '@/routes/supplier-returns';

type Props = {
    receipt: {
        id: string;
        number: string;
        supplier: string;
        receivedOn: string;
        lines: {
            id: string;
            item: string;
            batchNumber: string;
            expiresOn: string | null;
            received: number;
            returnable: number;
            unit: string;
            unitCostMinor: number;
        }[];
    };
    today: string;
};

/**
 * What goes back to the supplier from one delivery, and why.
 */
export default function CreateSupplierReturn({ receipt, today }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const [quantities, setQuantities] = useState<Record<string, string>>({});
    const total = receipt.lines.reduce(
        (sum, line) =>
            sum +
            Math.max(0, Math.floor(Number(quantities[line.id]) || 0)) *
                line.unitCostMinor,
        0,
    );

    return (
        <>
            <Head title={`Return from ${receipt.number}`} />
            <PageHeader
                title={`Return goods from ${receipt.number}`}
                description={`${receipt.supplier} · received ${receipt.receivedOn}. Returned goods leave stock at what they cost, and Finance takes them off what is owed.`}
            />
            <Panel>
                <Form {...store.form(receipt.id)}>
                    {({ errors, processing }) => (
                        <>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Medicine</TableHead>
                                        <TableHead>Batch</TableHead>
                                        <TableHead>Expires</TableHead>
                                        <TableHead className="text-right">
                                            Received
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Can go back
                                        </TableHead>
                                        <TableHead className="w-32 text-right">
                                            Return
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {receipt.lines.map((line) => (
                                        <TableRow key={line.id}>
                                            <TableCell>
                                                {line.item}
                                            </TableCell>
                                            <TableCell>
                                                {line.batchNumber}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {line.expiresOn ?? '—'}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.received} {line.unit}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {line.returnable}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    name={`quantities[${line.id}]`}
                                                    type="number"
                                                    min={0}
                                                    max={line.returnable}
                                                    disabled={
                                                        line.returnable === 0
                                                    }
                                                    value={
                                                        quantities[line.id] ??
                                                        ''
                                                    }
                                                    onChange={(event) =>
                                                        setQuantities(
                                                            (current) => ({
                                                                ...current,
                                                                [line.id]:
                                                                    event.target
                                                                        .value,
                                                            }),
                                                        )
                                                    }
                                                    className="text-right tabular-nums"
                                                    aria-label={`Return ${line.item}, batch ${line.batchNumber}`}
                                                />
                                                {errors[
                                                    `quantities.${line.id}`
                                                ] && (
                                                    <p className="mt-1 text-xs text-destructive">
                                                        {
                                                            errors[
                                                                `quantities.${line.id}`
                                                            ]
                                                        }
                                                    </p>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                            <PanelBody className="grid gap-3 border-t sm:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="returned_on">
                                        Returned on
                                    </Label>
                                    <Input
                                        id="returned_on"
                                        name="returned_on"
                                        type="date"
                                        defaultValue={today}
                                        required
                                    />
                                    {errors.returned_on && (
                                        <p className="text-sm text-destructive">
                                            {errors.returned_on}
                                        </p>
                                    )}
                                </div>
                                <div className="grid gap-1.5 sm:col-span-3">
                                    <Label htmlFor="reason">Reason</Label>
                                    <Input
                                        id="reason"
                                        name="reason"
                                        required
                                        maxLength={255}
                                        placeholder="Damaged in transit, short expiry, wrong item…"
                                    />
                                    {errors.reason && (
                                        <p className="text-sm text-destructive">
                                            {errors.reason}
                                        </p>
                                    )}
                                </div>
                                {errors.quantities && (
                                    <p className="text-sm text-destructive sm:col-span-4">
                                        {errors.quantities}
                                    </p>
                                )}
                                <div className="flex items-center gap-4 sm:col-span-4">
                                    <Button
                                        type="submit"
                                        disabled={processing || total === 0}
                                    >
                                        Return goods
                                    </Button>
                                    <span className="text-sm">
                                        Credit due from supplier:{' '}
                                        <span className="font-semibold tabular-nums">
                                            {money(total)}
                                        </span>
                                    </span>
                                </div>
                            </PanelBody>
                        </>
                    )}
                </Form>
            </Panel>
        </>
    );
}
