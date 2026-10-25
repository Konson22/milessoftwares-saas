import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { adjust, index, opening } from '@/routes/stock';

type Movement = {
    id: string;
    at: string;
    type: string;
    quantity: number;
    reason: string | null;
    note: string | null;
    by: string;
};

type Props = {
    item: {
        id: string;
        name: string;
        unit: string;
        reorderLevel: number;
        onHand: number;
        averageCostMinor: number;
    };
    movements: Movement[];
    reasons: { value: string; label: string }[];
    canAdjust: boolean;
};

const TYPE_LABELS: Record<string, string> = {
    opening: 'Added',
    sale: 'Billed',
    bill_void: 'Bill voided',
    count: 'Counted',
    write_off: 'Written off',
};

function FieldError({ message }: { message?: string }) {
    return message ? (
        <p className="text-sm text-destructive">{message}</p>
    ) : null;
}

/**
 * One drink's stock in the branch: adding what is there or was found,
 * counts and write-offs, and what moved it.
 */
export default function StockShow({
    item,
    movements,
    reasons,
    canAdjust,
}: Props) {
    const { branch, currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');

    return (
        <>
            <Head title={item.name} />
            <PageHeader
                title={item.name}
                description={`${item.onHand} ${item.unit} on hand at ${branch?.name ?? 'this branch'}, at ${money(item.averageCostMinor)} each · reorder at ${item.reorderLevel}`}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Drinks stock</Link>
                    </Button>
                }
            />

            {canAdjust && (
                <div className="mb-3 grid gap-3 lg:grid-cols-2">
                    <Panel>
                        <PanelHeader
                            title="Add stock"
                            description="Drinks already in the store room or the fridge, or found in a count, at what they cost."
                        />
                        <PanelBody>
                            <Form
                                {...opening.form(item.id)}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="quantity">
                                                Quantity ({item.unit})
                                            </Label>
                                            <Input
                                                id="quantity"
                                                name="quantity"
                                                type="number"
                                                min={1}
                                                required
                                            />
                                            <FieldError
                                                message={errors.quantity}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="unit_cost">
                                                Cost of one
                                            </Label>
                                            <Input
                                                id="unit_cost"
                                                name="unit_cost"
                                                inputMode="decimal"
                                                required
                                            />
                                            <FieldError
                                                message={errors.unit_cost}
                                            />
                                        </div>
                                        <div className="grid gap-1.5 sm:col-span-2">
                                            <Label htmlFor="opening_note">
                                                Note
                                            </Label>
                                            <Input
                                                id="opening_note"
                                                name="note"
                                            />
                                        </div>
                                        <div>
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

                    <Panel>
                        <PanelHeader
                            title="Count or write off"
                            description="How many more (5) or fewer (-2) there are than shown, and why."
                        />
                        <PanelBody>
                            <Form
                                {...adjust.form(item.id)}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="change">
                                                Change ({item.unit})
                                            </Label>
                                            <Input
                                                id="change"
                                                name="change"
                                                type="number"
                                                required
                                                placeholder="-2 or 5"
                                            />
                                            <FieldError
                                                message={errors.change}
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="reason">Why</Label>
                                            <select
                                                id="reason"
                                                name="reason"
                                                className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                            >
                                                {reasons.map((reason) => (
                                                    <option
                                                        key={reason.value}
                                                        value={reason.value}
                                                    >
                                                        {reason.label}
                                                    </option>
                                                ))}
                                            </select>
                                            <FieldError
                                                message={errors.reason}
                                            />
                                        </div>
                                        <div className="grid gap-1.5 sm:col-span-2">
                                            <Label htmlFor="adjust_note">
                                                Note
                                            </Label>
                                            <Input
                                                id="adjust_note"
                                                name="note"
                                            />
                                        </div>
                                        <div>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Apply
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                </div>
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
