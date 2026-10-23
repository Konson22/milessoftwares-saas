import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
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
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { show as stockShow } from '@/routes/retail/stock';
import { cancel, index, receive } from '@/routes/retail/transfers';

type Props = {
    transfer: {
        id: string;
        number: string;
        from: string;
        to: string;
        status: 'in_transit' | 'received' | 'cancelled';
        note: string | null;
        sentBy: string;
        sentAt: string;
        receivedBy: string | null;
        receivedAt: string | null;
        cancelledBy: string | null;
        cancelledAt: string | null;
        lines: {
            id: string;
            itemId: string;
            item: string;
            batchNumber: string;
            expiresOn: string | null;
            quantity: number;
            costMinor: number;
        }[];
    };
    canReceive: boolean;
    canCancel: boolean;
    locations: { id: string; name: string }[];
};

const when = (iso: string) => new Date(iso).toLocaleString();

/**
 * One transfer: what was sent, and receiving or cancelling it while it is
 * on the road (printable as a delivery note).
 */
export default function Transfer({
    transfer,
    canReceive,
    canCancel,
    locations,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const total = transfer.lines.reduce((sum, l) => sum + l.costMinor, 0);

    return (
        <>
            <Head title={`Transfer ${transfer.number}`} />
            <PageHeader
                title={`Transfer ${transfer.number}`}
                description={`${transfer.from} → ${transfer.to}`}
                badges={
                    <StatusBadge tone={statusTone(transfer.status)}>
                        {transfer.status.replace('_', ' ')}
                    </StatusBadge>
                }
                actions={
                    <div className="flex gap-2 print:hidden">
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Transfers</Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => window.print()}
                        >
                            Print
                        </Button>
                    </div>
                }
            />

            <Panel className="mb-3">
                <PanelBody className="grid gap-1 text-sm">
                    <p>
                        Sent by {transfer.sentBy}, {when(transfer.sentAt)}
                        {transfer.note && ` · “${transfer.note}”`}
                    </p>
                    {transfer.receivedBy && transfer.receivedAt && (
                        <p>
                            Received by {transfer.receivedBy},{' '}
                            {when(transfer.receivedAt)}
                        </p>
                    )}
                    {transfer.cancelledBy && transfer.cancelledAt && (
                        <p>
                            Cancelled by {transfer.cancelledBy},{' '}
                            {when(transfer.cancelledAt)}
                        </p>
                    )}
                </PanelBody>
            </Panel>

            <Panel className="mb-3">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Product</TableHead>
                            <TableHead>Batch</TableHead>
                            <TableHead>Expires</TableHead>
                            <TableHead className="text-right">Units</TableHead>
                            <TableHead className="text-right">
                                At cost
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transfer.lines.map((line) => (
                            <TableRow key={line.id}>
                                <TableCell>
                                    <Link
                                        href={stockShow(line.itemId)}
                                        className="font-medium hover:underline"
                                    >
                                        {line.item}
                                    </Link>
                                </TableCell>
                                <TableCell>{line.batchNumber}</TableCell>
                                <TableCell className="tabular-nums">
                                    {line.expiresOn ?? '—'}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {line.quantity}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {money(line.costMinor)}
                                </TableCell>
                            </TableRow>
                        ))}
                        <TableRow>
                            <TableCell colSpan={4} className="font-medium">
                                Total
                            </TableCell>
                            <TableCell className="text-right font-medium tabular-nums">
                                {money(total)}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </Panel>

            {(canReceive || canCancel) && (
                <Panel className="print:hidden">
                    <PanelBody className="flex flex-wrap items-end gap-3">
                        {canReceive && (
                            <Form
                                {...receive.form(transfer.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        {locations.length > 0 && (
                                            <div className="grid gap-1.5">
                                                <label
                                                    htmlFor="location_id"
                                                    className="text-sm font-medium"
                                                >
                                                    Put away at
                                                </label>
                                                <select
                                                    id="location_id"
                                                    name="location_id"
                                                    className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                                >
                                                    <option value="">
                                                        Not put away
                                                    </option>
                                                    {locations.map(
                                                        (location) => (
                                                            <option
                                                                key={
                                                                    location.id
                                                                }
                                                                value={
                                                                    location.id
                                                                }
                                                            >
                                                                {location.name}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                        )}
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Receive into stock
                                        </Button>
                                        {(errors.transfer ||
                                            errors.location_id ||
                                            errors.expires_on) && (
                                            <p className="w-full text-sm text-destructive">
                                                {errors.transfer ??
                                                    errors.location_id ??
                                                    errors.expires_on}
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                        )}
                        {canCancel && (
                            <Form
                                {...cancel.form(transfer.id)}
                                onBefore={() =>
                                    confirm(
                                        'Cancel this transfer and put the stock back?',
                                    )
                                }
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Cancel transfer
                                        </Button>
                                        {errors.transfer && (
                                            <p className="text-sm text-destructive">
                                                {errors.transfer}
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
