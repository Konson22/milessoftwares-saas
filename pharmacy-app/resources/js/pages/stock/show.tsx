import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
            <Link
                href={index()}
                className="text-sm text-muted-foreground hover:underline"
            >
                ← Stock in {branch?.name}
            </Link>
            <h1 className="mt-2 text-2xl font-semibold">
                {medicine.label}{' '}
                {medicine.controlled && (
                    <Badge variant="destructive">Controlled</Badge>
                )}
            </h1>

            <h2 className="mt-8 text-lg font-semibold">Batches</h2>
            <div className="mt-3 overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            <th className="px-4 py-2 font-medium">Batch</th>
                            <th className="px-4 py-2 font-medium">Expires</th>
                            <th className="px-4 py-2 text-right font-medium">
                                On hand
                            </th>
                            <th className="px-4 py-2 text-right font-medium">
                                Unit cost
                            </th>
                            {canAdjust && (
                                <th className="px-4 py-2 font-medium">
                                    Adjust
                                </th>
                            )}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {batches.length === 0 && (
                            <tr>
                                <td
                                    colSpan={5}
                                    className="px-4 py-6 text-center text-muted-foreground"
                                >
                                    No stock in this branch.
                                </td>
                            </tr>
                        )}
                        {batches.map((batch) => (
                            <tr key={batch.id}>
                                <td className="px-4 py-2 font-medium">
                                    {batch.batchNumber}
                                </td>
                                <td className="px-4 py-2 tabular-nums">
                                    {batch.expiresOn}{' '}
                                    {batch.expired && (
                                        <Badge variant="outline">Expired</Badge>
                                    )}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {batch.quantity}
                                </td>
                                <td className="px-4 py-2 text-right tabular-nums">
                                    {formatMoney(
                                        batch.unitCostMinor,
                                        currency ?? 'USD',
                                    )}
                                </td>
                                {canAdjust && (
                                    <td className="px-4 py-2">
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
                                                                {errors.change}
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
                                                                {errors.note}
                                                            </p>
                                                        )}
                                                    </div>
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={processing}
                                                    >
                                                        Apply
                                                    </Button>
                                                </>
                                            )}
                                        </Form>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {canAdjust && (
                <>
                    <h2 className="mt-8 text-lg font-semibold">
                        Add opening stock
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Stock already on the shelf, or found in a count. Goods
                        from suppliers are received through purchasing.
                    </p>
                    <Form
                        {...opening.form(medicine.id)}
                        resetOnSuccess
                        className="mt-3 grid max-w-3xl gap-3 sm:grid-cols-4"
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
                                        ['expires_on', 'Expires on', 'date'],
                                        [
                                            'quantity',
                                            `Quantity (${medicine.unit})`,
                                            'number',
                                        ],
                                        ['unit_cost', 'Unit cost', 'text'],
                                    ] as const
                                ).map(([name, label, type]) => (
                                    <div key={name} className="grid gap-1.5">
                                        <Label htmlFor={name}>{label}</Label>
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
                                                errors[name] ? true : undefined
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
                                    <Button type="submit" disabled={processing}>
                                        Add stock
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </>
            )}

            <h2 className="mt-8 text-lg font-semibold">Recent movements</h2>
            <ul className="mt-3 divide-y rounded-md border text-sm">
                {movements.length === 0 && (
                    <li className="px-4 py-3 text-muted-foreground">
                        Nothing yet.
                    </li>
                )}
                {movements.map((movement) => (
                    <li
                        key={movement.id}
                        className="flex flex-wrap gap-x-4 px-4 py-2"
                    >
                        <span className="text-muted-foreground tabular-nums">
                            {new Date(movement.at).toLocaleString()}
                        </span>
                        <span>
                            {TYPE_LABELS[movement.type] ?? movement.type}
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
                        {movement.reason && <span>{movement.reason}</span>}
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
        </>
    );
}
