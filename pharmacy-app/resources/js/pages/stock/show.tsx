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
import { index as disposals, store as storeDisposal } from '@/routes/disposals';
import { move as moveStock } from '@/routes/locations';
import { adjust, hold, index, opening, release } from '@/routes/stock';

type Batch = {
    id: string;
    batchNumber: string;
    expiresOn: string;
    expired: boolean;
    quantity: number;
    unitCostMinor: number;
    status: 'available' | 'quarantined' | 'recalled';
    holdReason: string | null;
    heldAt: string | null;
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
    medicine: {
        id: string;
        label: string;
        unit: string;
        controlled: boolean;
        reorderLevel: number;
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
    /** The branch's locations in use; none means stock is not placed. */
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
    disposal: 'Disposed of',
};

export default function StockShow({
    medicine,
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
    const [moveBatch, setMoveBatch] = useState(
        batches.find((batch) => batch.quantity > 0)?.id ?? '',
    );
    const movable = batches.find((batch) => batch.id === moveBatch);
    const [holdBatch, setHoldBatch] = useState(
        batches.find((batch) => batch.status !== 'recalled')?.id ?? '',
    );
    const [disposeBatch, setDisposeBatch] = useState(
        batches.find((batch) => batch.quantity > 0)?.id ?? '',
    );

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
                                        {batch.status !== 'available' && (
                                            <span className="block text-xs">
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

            {(canHold || canAdjust) && batches.length > 0 && (
                <div className="mb-5 grid gap-5 lg:grid-cols-2">
                    {canHold && holdBatch !== '' && (
                        <Panel>
                            <PanelHeader
                                title="Hold a batch back"
                                description="Quarantined or recalled stock is not sold. A quarantined batch can be released; a recalled one goes back to the supplier or is disposed of."
                            />
                            <PanelBody>
                                <Form
                                    {...hold.form(holdBatch)}
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <select
                                                value={holdBatch}
                                                onChange={(e) =>
                                                    setHoldBatch(e.target.value)
                                                }
                                                aria-label="Batch to hold"
                                                className="h-9 rounded-md border bg-transparent px-2"
                                            >
                                                {batches
                                                    .filter(
                                                        (batch) =>
                                                            batch.status !==
                                                            'recalled',
                                                    )
                                                    .map((batch) => (
                                                        <option
                                                            key={batch.id}
                                                            value={batch.id}
                                                        >
                                                            {batch.batchNumber}{' '}
                                                            (exp{' '}
                                                            {batch.expiresOn})
                                                        </option>
                                                    ))}
                                            </select>
                                            <select
                                                name="status"
                                                aria-label="Hold"
                                                className="h-9 rounded-md border bg-transparent px-2"
                                            >
                                                <option value="quarantined">
                                                    Quarantine
                                                </option>
                                                <option value="recalled">
                                                    Recall
                                                </option>
                                            </select>
                                            <Input
                                                name="reason"
                                                required
                                                placeholder="Why? (e.g. recall notice number)"
                                                aria-label="Reason for the hold"
                                            />
                                            {(errors.reason || errors.hold) && (
                                                <p className="text-sm text-destructive">
                                                    {errors.reason ??
                                                        errors.hold}
                                                </p>
                                            )}
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Hold back from sale
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}

                    {canAdjust && disposeBatch !== '' && (
                        <Panel>
                            <PanelHeader
                                title="Dispose of stock"
                                description="Damaged, expired or recalled stock is destroyed once someone else approves."
                            />
                            <PanelBody>
                                <Form
                                    {...storeDisposal.form(disposeBatch)}
                                    resetOnSuccess
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <select
                                                value={disposeBatch}
                                                onChange={(e) =>
                                                    setDisposeBatch(
                                                        e.target.value,
                                                    )
                                                }
                                                aria-label="Batch to dispose of"
                                                className="h-9 rounded-md border bg-transparent px-2"
                                            >
                                                {batches
                                                    .filter(
                                                        (batch) =>
                                                            batch.quantity > 0,
                                                    )
                                                    .map((batch) => (
                                                        <option
                                                            key={batch.id}
                                                            value={batch.id}
                                                        >
                                                            {batch.batchNumber}{' '}
                                                            ({batch.quantity} on
                                                            hand)
                                                        </option>
                                                    ))}
                                            </select>
                                            <div className="flex gap-2">
                                                <Input
                                                    name="quantity"
                                                    type="number"
                                                    min={1}
                                                    required
                                                    placeholder={`Quantity (${medicine.unit})`}
                                                    aria-label="Quantity to dispose of"
                                                    className="w-36"
                                                />
                                                <select
                                                    name="reason"
                                                    aria-label="Reason for disposal"
                                                    className="h-9 flex-1 rounded-md border bg-transparent px-2"
                                                >
                                                    {disposalReasons.map(
                                                        (reason) => (
                                                            <option
                                                                key={
                                                                    reason.value
                                                                }
                                                                value={
                                                                    reason.value
                                                                }
                                                            >
                                                                {reason.label}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </div>
                                            <Input
                                                name="note"
                                                placeholder="Note"
                                                aria-label="Disposal note"
                                            />
                                            {(errors.quantity ||
                                                errors.note) && (
                                                <p className="text-sm text-destructive">
                                                    {errors.quantity ??
                                                        errors.note}
                                                </p>
                                            )}
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Ask for approval
                                            </Button>
                                        </>
                                    )}
                                </Form>
                                {pendingDisposals.length > 0 && (
                                    <div className="mt-4 text-sm">
                                        <p className="font-medium">
                                            Waiting for approval
                                        </p>
                                        <ul className="mt-1 grid gap-1">
                                            {pendingDisposals.map(
                                                (disposal) => (
                                                    <li key={disposal.id}>
                                                        {disposal.number}:{' '}
                                                        {disposal.quantity} of
                                                        batch{' '}
                                                        {disposal.batchNumber} (
                                                        {disposal.reason}),
                                                        asked by{' '}
                                                        {disposal.requestedBy}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                        <Link
                                            href={disposals()}
                                            className="mt-1 inline-block underline"
                                        >
                                            All disposals
                                        </Link>
                                    </div>
                                )}
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            )}

            {canAdjust && locations.length > 0 && movable && (
                <Panel className="mb-5 max-w-3xl">
                    <PanelHeader
                        title="Put away or move"
                        description="Where the units are kept; what is on hand does not change."
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
                                    {locations.length > 0 && (
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="location_id">
                                                Put it at
                                            </Label>
                                            <select
                                                id="location_id"
                                                name="location_id"
                                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                            >
                                                <option value="">
                                                    Not put away yet
                                                </option>
                                                {locations.map((location) => (
                                                    <option
                                                        key={location.id}
                                                        value={location.id}
                                                    >
                                                        {location.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    )}
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
