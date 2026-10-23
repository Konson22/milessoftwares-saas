import { Form } from '@inertiajs/react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { useState } from 'react';
import type { RouteFormDefinition } from '@/wayfinder';

export type CashMovement = {
    id: string;
    direction: 'in' | 'out';
    counterpart: 'safe' | 'bank' | 'expense';
    /** "Banked", "Paid out", "To the safe"… */
    label: string;
    amountMinor: number;
    reason: string;
    recordedBy: string;
    recordedAt: string;
};

/** What a movement is: which way, and where from or to. */
const KINDS = [
    { value: 'out:safe', label: 'Out to the safe' },
    { value: 'out:bank', label: 'Out, banked' },
    { value: 'out:expense', label: 'Out, paid for an expense' },
    { value: 'in:safe', label: 'In from the safe' },
    { value: 'in:bank', label: 'In from the bank' },
] as const;

/**
 * Cash put into or taken out of a shift other than by selling: to or from
 * the safe (nothing for the books), banked or from the bank, or paid out
 * for an expense (both go to Finance). Shown even to a cashier counting
 * blind, since they are the ones who record it.
 */
export function TillCashMovements({
    movements,
    form,
    money,
}: {
    movements: CashMovement[];
    /** Where a new movement goes; none once the shift is closed. */
    form: RouteFormDefinition<'post'> | null;
    money: (minor: number) => string;
}) {
    const [kind, setKind] = useState<(typeof KINDS)[number]['value']>(
        'out:safe',
    );
    const [direction, counterpart] = kind.split(':');

    if (movements.length === 0 && form === null) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader
                title="Cash in and out"
                description="It changes what the drawer should hold. Banked cash and expenses paid also go to the books."
            />
            <PanelBody>
                {movements.length > 0 ? (
                    <ul className="divide-y text-sm">
                        {movements.map((movement) => (
                            <li
                                key={movement.id}
                                className="flex items-baseline justify-between gap-4 py-2"
                            >
                                <div>
                                    <p>{movement.reason}</p>
                                    <p className="text-muted-foreground">
                                        {movement.label} · {movement.recordedBy},{' '}
                                        {new Date(
                                            movement.recordedAt,
                                        ).toLocaleTimeString()}
                                    </p>
                                </div>
                                <span className="tabular-nums">
                                    {movement.direction === 'in' ? '+' : '−'}
                                    {money(movement.amountMinor)}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        No cash put in or taken out yet.
                    </p>
                )}

                {form && (
                    <Form
                        {...form}
                        resetOnSuccess
                        className="mt-3 grid gap-3 border-t pt-4 sm:grid-cols-[auto_8rem_1fr_auto] sm:items-end"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="movement_kind">Cash</Label>
                                    <select
                                        id="movement_kind"
                                        value={kind}
                                        onChange={(event) =>
                                            setKind(
                                                event.target
                                                    .value as typeof kind,
                                            )
                                        }
                                        className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                    >
                                        {KINDS.map((option) => (
                                            <option
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </option>
                                        ))}
                                    </select>
                                    <input
                                        type="hidden"
                                        name="direction"
                                        value={direction}
                                    />
                                    <input
                                        type="hidden"
                                        name="counterpart"
                                        value={counterpart}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="amount">Amount</Label>
                                    <Input
                                        id="amount"
                                        name="amount"
                                        inputMode="decimal"
                                        required
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="reason">Reason</Label>
                                    <Input
                                        id="reason"
                                        name="reason"
                                        placeholder="e.g. Drop to the safe"
                                        maxLength={255}
                                        required
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Record
                                </Button>
                                {(errors.direction ||
                                    errors.counterpart ||
                                    errors.amount ||
                                    errors.reason) && (
                                    <p className="text-sm text-destructive sm:col-span-4">
                                        {errors.direction ??
                                            errors.counterpart ??
                                            errors.amount ??
                                            errors.reason}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}
