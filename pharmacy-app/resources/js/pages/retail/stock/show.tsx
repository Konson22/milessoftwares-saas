import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
import {
    index as disposals,
    store as storeDisposal,
} from '@/routes/retail/disposals';
import { move as moveStock } from '@/routes/retail/locations';
import {
    adjust,
    hold,
    index,
    opening,
    release,
} from '@/routes/retail/stock';

type Batch = {
    id: string;
    batchNumber: string;
    expiresOn: string | null;
    expired: boolean;
    quantity: number;
    unitCostMinor: number;
    status: 'available' | 'quarantined' | 'recalled';
    holdReason: string | null;
    locations: { id: string; name: string; quantity: number }[];
    notPutAway: number;
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
        item: {
        id: string;
        label: string;
        unit: string;
        reorderLevel: number;
        /** Its batches carry an expiry date. */
        tracksExpiry: boolean;
    };
    batches: Batch[];
    movements: Movement[];
    pendingDisposals: {
        id: string;
        number: string;
        batchNumber: string;
        quantity: number;
        reason: string;
        requestedBy: string;
    }[];
    /** The branch's shelves in use; none means goods are not placed. */
    locations: { id: string; name: string }[];
    reasons: { value: string; label: string }[];
    disposalReasons: { value: string; label: string }[];
    canAdjust: boolean;
    canHold: boolean;
};

const TYPE_LABELS: Record<string, string> = {
    opening: 'Opening stock',
    receipt: 'Received',
    sale: 'Sold',
    sale_void: 'Sale voided',
    sale_return: 'Customer return',
    supplier_return: 'Returned to supplier',
    adjustment: 'Adjusted',
    disposal: 'Written off',
    transfer_out: 'Sent to another branch',
    transfer_in: 'Transfer received',
};

/**
 * One product's stock in the branch: its batches (lots received together),
 * counts and corrections, and what moved it.
 */
export default function RetailStockShow({
    item: product,
    batches,
    movements,
    pendingDisposals,
    locations,
    reasons,
    disposalReasons,
    canAdjust,
    canHold,
}: Props) {
    const { branch, currency } = usePage().props;
    const [writeOffBatch, setWriteOffBatch] = useState(
        batches.find((batch) => batch.quantity > 0)?.id ?? '',
    );
    const [holdBatch, setHoldBatch] = useState(
        batches.find(
            (batch) => batch.quantity > 0 && batch.status !== 'recalled',
        )?.id ?? '',
    );
    const [moveBatch, setMoveBatch] = useState(
        batches.find((batch) => batch.quantity > 0)?.id ?? '',
    );
    const movable = batches.find((batch) => batch.id === moveBatch);
    const columns = (product.tracksExpiry ? 4 : 3) + (canAdjust ? 1 : 0);

    return (
        <>
            <Head title={product.label} />
            <PageHeader
                title={product.label}
                description={`Reorder at ${product.reorderLevel} ${product.unit}`}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Stock in {branch?.name}</Link>
                    </Button>
                }
            />

            <Panel className="mb-3">
                <PanelHeader title="Batches" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Batch</TableHead>
                            {product.tracksExpiry && (
                                <TableHead>Expires</TableHead>
                            )}
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
                            <TableEmpty colSpan={columns}>
                                No stock in this branch.
                            </TableEmpty>
                        ) : (
                            batches.map((batch) => (
                                <TableRow key={batch.id}>
                                    <TableCell className="font-medium">
                                        {batch.batchNumber}
                                        {batch.status !== 'available' && (
                                            <span className="block text-xs font-normal">
                                                <StatusBadge
                                                    tone={
                                                        batch.status ===
                                                        'recalled'
                                                            ? 'danger'
                                                            : 'warning'
                                                    }
                                                >
                                                    {batch.status}
                                                </StatusBadge>{' '}
                                                <span className="text-muted-foreground">
                                                    {batch.holdReason}
                                                </span>
                                                {canHold &&
                                                    batch.status ===
                                                        'quarantined' && (
                                                        <Button
                                                            type="button"
                                                            variant="link"
                                                            size="sm"
                                                            className="h-auto px-1"
                                                            onClick={() =>
                                                                router.post(
                                                                    release.url(
                                                                        batch.id,
                                                                    ),
                                                                    {},
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            Release
                                                        </Button>
                                                    )}
                                            </span>
                                        )}
                                    </TableCell>
                                    {product.tracksExpiry && (
                                        <TableCell className="tabular-nums">
                                            {batch.expiresOn ?? '—'}{' '}
                                            {batch.expired && (
                                                <StatusBadge tone="neutral">
                                                    Expired
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-right tabular-nums">
                                        {batch.quantity}
                                        {locations.length > 0 &&
                                            batch.quantity > 0 && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {[
                                                        ...batch.locations.map(
                                                            (place) =>
                                                                `${place.name} ${place.quantity}`,
                                                        ),
                                                        ...(batch.notPutAway > 0
                                                            ? [
                                                                  `not put away ${batch.notPutAway}`,
                                                              ]
                                                            : []),
                                                    ].join(' · ')}
                                                </span>
                                            )}
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
                                                options={{
                                                    preserveScroll: true,
                                                }}
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

            {canHold && holdBatch !== '' && (
                <Panel className="mb-3 max-w-3xl">
                    <PanelHeader
                        title="Hold a batch back"
                        description="Held goods are not sold. A quarantined batch (being checked) can be released; a recalled one goes back to the supplier or is written off."
                    />
                    <PanelBody>
                        <Form
                            {...hold.form(holdBatch)}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-start gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <select
                                        value={holdBatch}
                                        onChange={(e) =>
                                            setHoldBatch(e.target.value)
                                        }
                                        aria-label="Batch to hold"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {batches
                                            .filter(
                                                (batch) =>
                                                    batch.quantity > 0 &&
                                                    batch.status !==
                                                        'recalled',
                                            )
                                            .map((batch) => (
                                                <option
                                                    key={batch.id}
                                                    value={batch.id}
                                                >
                                                    {batch.batchNumber} (
                                                    {batch.quantity} on hand)
                                                </option>
                                            ))}
                                    </select>
                                    <select
                                        name="status"
                                        aria-label="Hold"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        <option value="quarantined">
                                            Quarantine
                                        </option>
                                        <option value="recalled">Recall</option>
                                    </select>
                                    <Input
                                        name="reason"
                                        required
                                        placeholder="Why? (e.g. recall notice)"
                                        aria-label="Reason for the hold"
                                        className="w-64"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Hold back from sale
                                    </Button>
                                    {(errors.reason || errors.hold) && (
                                        <p className="w-full text-sm text-destructive">
                                            {errors.reason ?? errors.hold}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {canAdjust && locations.length > 0 && movable && (
                <Panel className="mb-3 max-w-3xl">
                    <PanelHeader
                        title="Put away or move"
                        description="Which shelf the goods are on; what is on hand does not change."
                    />
                    <PanelBody>
                        <Form
                            {...moveStock.form(moveBatch)}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-start gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <select
                                        value={moveBatch}
                                        onChange={(e) =>
                                            setMoveBatch(e.target.value)
                                        }
                                        aria-label="Batch to move"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {batches
                                            .filter(
                                                (batch) => batch.quantity > 0,
                                            )
                                            .map((batch) => (
                                                <option
                                                    key={batch.id}
                                                    value={batch.id}
                                                >
                                                    {batch.batchNumber}
                                                </option>
                                            ))}
                                    </select>
                                    <select
                                        name="from_location_id"
                                        aria-label="From"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {movable.notPutAway > 0 && (
                                            <option value="">
                                                From not put away (
                                                {movable.notPutAway})
                                            </option>
                                        )}
                                        {movable.locations.map((place) => (
                                            <option
                                                key={place.id}
                                                value={place.id}
                                            >
                                                From {place.name} (
                                                {place.quantity})
                                            </option>
                                        ))}
                                    </select>
                                    <select
                                        name="to_location_id"
                                        aria-label="To"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {locations.map((location) => (
                                            <option
                                                key={location.id}
                                                value={location.id}
                                            >
                                                To {location.name}
                                            </option>
                                        ))}
                                        <option value="">
                                            To not put away
                                        </option>
                                    </select>
                                    <Input
                                        name="quantity"
                                        type="number"
                                        min={1}
                                        required
                                        placeholder="How many"
                                        aria-label="Quantity to move"
                                        className="w-28"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Move
                                    </Button>
                                    {(errors.quantity ||
                                        errors.to_location_id) && (
                                        <p className="w-full text-sm text-destructive">
                                            {errors.quantity ??
                                                errors.to_location_id}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            {canAdjust && writeOffBatch !== '' && (
                <Panel className="mb-3 max-w-3xl">
                    <PanelHeader
                        title="Write off stock"
                        description="Damaged, expired or recalled goods leave stock once someone else approves. Count differences are adjustments above."
                    />
                    <PanelBody>
                        <Form
                            {...storeDisposal.form(writeOffBatch)}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="flex flex-wrap items-start gap-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <select
                                        value={writeOffBatch}
                                        onChange={(e) =>
                                            setWriteOffBatch(e.target.value)
                                        }
                                        aria-label="Batch to write off"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {batches
                                            .filter(
                                                (batch) => batch.quantity > 0,
                                            )
                                            .map((batch) => (
                                                <option
                                                    key={batch.id}
                                                    value={batch.id}
                                                >
                                                    {batch.batchNumber} (
                                                    {batch.quantity} on hand)
                                                </option>
                                            ))}
                                    </select>
                                    <Input
                                        name="quantity"
                                        type="number"
                                        min={1}
                                        required
                                        placeholder={`Quantity (${product.unit})`}
                                        aria-label="Quantity to write off"
                                        className="w-36"
                                    />
                                    <select
                                        name="reason"
                                        aria-label="Reason for the write-off"
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {disposalReasons.map((reason) => (
                                            <option
                                                key={reason.value}
                                                value={reason.value}
                                            >
                                                {reason.label}
                                            </option>
                                        ))}
                                    </select>
                                    <Input
                                        name="note"
                                        placeholder="Note"
                                        aria-label="Write-off note"
                                        className="w-48"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Ask for approval
                                    </Button>
                                    {(errors.quantity || errors.note) && (
                                        <p className="w-full text-sm text-destructive">
                                            {errors.quantity ?? errors.note}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                        {pendingDisposals.length > 0 && (
                            <div className="mt-4 text-sm">
                                <p className="font-medium">
                                    Waiting for approval
                                </p>
                                <ul className="mt-1 grid gap-1">
                                    {pendingDisposals.map((disposal) => (
                                        <li key={disposal.id}>
                                            {disposal.number}:{' '}
                                            {disposal.quantity} of batch{' '}
                                            {disposal.batchNumber} (
                                            {disposal.reason}), asked by{' '}
                                            {disposal.requestedBy}
                                        </li>
                                    ))}
                                </ul>
                                <Link
                                    href={disposals()}
                                    className="mt-1 inline-block underline"
                                >
                                    All write-offs
                                </Link>
                            </div>
                        )}
                    </PanelBody>
                </Panel>
            )}

            {canAdjust && (
                <Panel className="mb-3 max-w-3xl">
                    <PanelHeader
                        title="Add opening stock"
                        description="Stock already on the shelf, or found in a count."
                    />
                    <PanelBody>
                        <Form
                            {...opening.form(product.id)}
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    {(
                                        [
                                            [
                                                'batch_number',
                                                'Batch or lot',
                                                'text',
                                            ],
                                            [
                                                'expires_on',
                                                'Expires on',
                                                'date',
                                            ],
                                            [
                                                'quantity',
                                                `Quantity (${product.unit})`,
                                                'number',
                                            ],
                                            ['unit_cost', 'Unit cost', 'text'],
                                        ] as const
                                    )
                                        .filter(
                                            ([name]) =>
                                                name !== 'expires_on' ||
                                                product.tracksExpiry,
                                        )
                                        .map(([name, label, type]) => (
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
