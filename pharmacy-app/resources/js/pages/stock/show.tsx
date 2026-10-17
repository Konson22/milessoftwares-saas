import { Form, Head, Link, usePage } from '@inertiajs/react';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatMoney } from '@/lib/money';
import { adjust, index, opening } from '@/routes/stock';

type Batch = {
    id: string;
    batchNumber: string;
    expiresOn: string;
    expired: boolean;
    quantity: number;
    unitCostMinor: number;
};

type Movement = {
    id: string;
    at: string;
    type: string;
    quantity: number;
    batchNumber: string;
    reason: string | null;
    note: string | null;
    by: string;
};

type Props = {
    medicine: {
        id: string;
        label: string;
        unit: string;
        controlled: boolean;
        reorderLevel: number;
    };
    batches: Batch[];
    movements: Movement[];
    reasons: { value: string; label: string }[];
    canAdjust: boolean;
};

const TYPE_LABELS: Record<string, string> = {
    opening: 'Opening stock',
    receipt: 'Received',
    sale: 'Sold',
    sale_void: 'Sale voided',
    sale_return: 'Customer return',
    supplier_return: 'Returned to supplier',
    adjustment: 'Adjusted',
};

export default function StockShow({
    medicine,
    batches,
    movements,
    reasons,
    canAdjust,
}: Props) {
    const { branch, currency } = usePage().props;

    return (
        <>
            <Head title={medicine.label} />
            <PageHeader
                title={medicine.label}
                badges={
                    medicine.controlled ? (
                        <StatusBadge tone="danger">Controlled</StatusBadge>
                    ) : undefined
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Stock in {branch?.name}</Link>
                    </Button>
                }
            />

            <Panel className="mb-5">
                <PanelHeader title="Batches" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Batch</TableHead>
                            <TableHead>Expires</TableHead>
                            <TableHead className="text-right">
                                On hand
                            </TableHead>
                            <TableHead className="text-right">
                                Unit cost
                            </TableHead>
                            {canAdjust && <TableHead>Adjust</TableHead>}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {batches.length === 0 ? (
                            <TableEmpty colSpan={canAdjust ? 5 : 4}>
                                No stock in this branch.
                            </TableEmpty>
                        ) : (
                            batches.map((batch) => (
                                <TableRow key={batch.id}>
                                    <TableCell className="font-medium">
                                        {batch.batchNumber}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {batch.expiresOn}{' '}
                                        {batch.expired && (
                                            <StatusBadge tone="neutral">
                                                Expired
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {batch.quantity}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatMoney(
                                            batch.unitCostMinor,
                                            currency ?? 'USD',
                                        )}
                                    </TableCell>
                                    {canAdjust && (
                                        <TableCell>
                                            <Form
                                                {...adjust.form(batch.id)}
                                                resetOnSuccess
                                                className="flex flex-wrap items-start gap-2"
                                            >
                                                {({ errors, processing }) => (
                                                    <>
                                                        <div>
                                                            <Input
                                                                name="change"
                                                                type="number"
                                                                required
                                                                placeholder="-2 or 5"
                                                                aria-label={`Change for batch ${batch.batchNumber}`}
                                                                className="h-8 w-24"
                                                            />
                                                            {errors.change && (
                                                                <p className="text-xs text-destructive">
                                                                    {
                                                                        errors.change
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                        <select
                                                            name="reason"
                                                            aria-label="Reason"
                                                            className="h-8 rounded-md border bg-transparent px-2"
                                                        >
                                                            {reasons.map(
                                                                (reason) => (
                                                                    <option
                                                                        key={
                                                                            reason.value
                                                                        }
                                                                        value={
                                                                            reason.value
                                                                        }
                                                                    >
                                                                        {
                                                                            reason.label
                                                                        }
                                                                    </option>
                                                                ),
                                                            )}
                                                        </select>
                                                        <div>
                                                            <Input
                                                                name="note"
                                                                placeholder="Note"
                                                                aria-label="Note"
                                                                className="h-8 w-40"
                                                            />
                                                            {errors.note && (
                                                                <p className="text-xs text-destructive">
                                                                    {
                                                                        errors.note
                                                                    }
                                                                </p>
                                                            )}
                                                        </div>
                                                        <Button
                                                            type="submit"
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={
                                                                processing
                                                            }
                                                        >
                                                            Apply
                                                        </Button>
                                                    </>
                                                )}
                                            </Form>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </Panel>

            {canAdjust && (
                <Panel className="mb-5 max-w-3xl">
                    <PanelHeader
                        title="Add opening stock"
                        description="Stock already on the shelf, or found in a count. Goods from suppliers are received through purchasing."
                    />
                    <PanelBody>
                        <Form
                            {...opening.form(medicine.id)}
                            resetOnSuccess
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {(
                                        [
                                            [
                                                'batch_number',
                                                'Batch number',
                                                'text',
                                            ],
                                            [
                                                'expires_on',
                                                'Expires on',
                                                'date',
                                            ],
                                            [
                                                'quantity',
                                                `Quantity (${medicine.unit})`,
                                                'number',
                                            ],
                                            ['unit_cost', 'Unit cost', 'text'],
                                        ] as const
                                    ).map(([name, label, type]) => (
                                        <div
                                            key={name}
                                            className="grid gap-1.5"
                                        >
                                            <Label htmlFor={name}>
                                                {label}
                                            </Label>
                                            <Input
                                                id={name}
                                                name={name}
                                                type={type}
                                                required
                                                inputMode={
                                                    name === 'unit_cost'
                                                        ? 'decimal'
                                                        : undefined
                                                }
                                                aria-invalid={
                                                    errors[name]
                                                        ? true
                                                        : undefined
                                                }
                                            />
                                            {errors[name] && (
                                                <p className="text-sm text-destructive">
                                                    {errors[name]}
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add stock
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelHeader title="Recent movements" />
                {movements.length === 0 ? (
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            Nothing yet.
                        </p>
                    </PanelBody>
                ) : (
                    <ul className="divide-y text-sm">
                        {movements.map((movement) => (
                            <li
                                key={movement.id}
                                className="flex flex-wrap gap-x-4 px-4 py-2.5"
                            >
                                <span className="text-muted-foreground tabular-nums">
                                    {new Date(movement.at).toLocaleString()}
                                </span>
                                <span>
                                    {TYPE_LABELS[movement.type] ??
                                        movement.type}
                                </span>
                                <span
                                    className={
                                        movement.quantity < 0
                                            ? 'text-destructive tabular-nums'
                                            : 'tabular-nums'
                                    }
                                >
                                    {movement.quantity > 0 ? '+' : ''}
                                    {movement.quantity}
                                </span>
                                <span>batch {movement.batchNumber}</span>
                                {movement.reason && (
                                    <span>{movement.reason}</span>
                                )}
                                {movement.note && (
                                    <span className="text-muted-foreground">
                                        “{movement.note}”
                                    </span>
                                )}
                                <span className="ml-auto text-muted-foreground">
                                    {movement.by}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Panel>
        </>
    );
}
