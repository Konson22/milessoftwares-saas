import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { close, index } from '@/routes/retail/tills';
import type { ShiftRules, Till } from './index';

type Props = {
    till: Till;
    summary: {
        salesCount: number;
        /** Empty when the cashier counts blind. */
        takenMinor: Record<string, number>;
        /** Payments from account customers, by method; empty when blind. */
        collectedMinor: Record<string, number>;
        collectionsCount: number;
        /** Null when the cashier counts blind. */
        cashRefundsMinor: number | null;
        refundsCount: number;
        /** Null when the cashier counts blind. */
        expectedCashMinor: number | null;
    };
    methods: { value: string; label: string }[];
    shifts: ShiftRules;
    /** This member may count and close the shift now. */
    canClose: boolean;
};

const STATUS_LABELS: Record<string, string> = {
    open: 'Open',
    closed: 'Closed',
};

/**
 * One cash shift: what went through it and, while open, the count that
 * closes it. A count that differs from what the drawer should hold needs a
 * reason, unless the cashier counts blind (then the manager sees the
 * difference).
 */
export default function ShowShift({
    till,
    summary,
    methods,
    shifts,
    canClose,
}: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const expected =
        till.status === 'closed' && till.expectedCashMinor !== null
            ? till.expectedCashMinor
            : summary.expectedCashMinor;

    return (
        <>
            <Head title={`Shift ${till.number}`} />
            <PageHeader
                title={`Shift ${till.number}`}
                description={`${till.openedBy}, started ${new Date(till.openedAt).toLocaleString()}`}
                badges={
                    <StatusBadge tone={statusTone(till.status)}>
                        {STATUS_LABELS[till.status] ?? till.status}
                    </StatusBadge>
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Shifts</Link>
                    </Button>
                }
            />

            <div className="grid gap-3 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="Taken" />
                    <PanelBody>
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            <dt className="text-muted-foreground">Sales</dt>
                            <dd className="text-right tabular-nums">
                                {summary.salesCount}
                            </dd>
                            <dt className="text-muted-foreground">
                                Money given back
                            </dt>
                            <dd className="text-right tabular-nums">
                                {summary.refundsCount}
                            </dd>
                            {methods
                                .filter(
                                    (method) =>
                                        summary.takenMinor[method.value] !==
                                        undefined,
                                )
                                .map((method) => (
                                    <div
                                        key={method.value}
                                        className="contents"
                                    >
                                        <dt className="text-muted-foreground">
                                            {method.label}
                                        </dt>
                                        <dd className="text-right tabular-nums">
                                            {money(
                                                summary.takenMinor[
                                                    method.value
                                                ] ?? 0,
                                            )}
                                        </dd>
                                    </div>
                                ))}
                            {summary.collectionsCount > 0 && (
                                <>
                                    <dt className="mt-2 font-medium">
                                        From account customers (
                                        {summary.collectionsCount})
                                    </dt>
                                    <dd />
                                    {methods
                                        .filter(
                                            (method) =>
                                                summary.collectedMinor[
                                                    method.value
                                                ] !== undefined,
                                        )
                                        .map((method) => (
                                            <div
                                                key={method.value}
                                                className="contents"
                                            >
                                                <dt className="text-muted-foreground">
                                                    {method.label}
                                                </dt>
                                                <dd className="text-right tabular-nums">
                                                    {money(
                                                        summary.collectedMinor[
                                                            method.value
                                                        ] ?? 0,
                                                    )}
                                                </dd>
                                            </div>
                                        ))}
                                </>
                            )}
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Cash in the drawer"
                        description={
                            shifts.blind && till.status === 'open'
                                ? 'Count the cash without the expected figure; your manager sees any difference.'
                                : undefined
                        }
                    />
                    <PanelBody>
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            <dt className="text-muted-foreground">
                                Starting cash
                            </dt>
                            <dd className="text-right tabular-nums">
                                {money(till.openingFloatMinor)}
                            </dd>
                            {expected !== null &&
                                summary.cashRefundsMinor !== null && (
                                    <>
                                        <dt className="text-muted-foreground">
                                            Cash taken
                                        </dt>
                                        <dd className="text-right tabular-nums">
                                            {money(
                                                summary.takenMinor.cash ?? 0,
                                            )}
                                        </dd>
                                        {(summary.collectedMinor.cash ?? 0) >
                                            0 && (
                                            <>
                                                <dt className="text-muted-foreground">
                                                    Cash from account customers
                                                </dt>
                                                <dd className="text-right tabular-nums">
                                                    {money(
                                                        summary.collectedMinor
                                                            .cash ?? 0,
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                        <dt className="text-muted-foreground">
                                            Cash given back (
                                            {summary.refundsCount})
                                        </dt>
                                        <dd className="text-right tabular-nums">
                                            −{money(summary.cashRefundsMinor)}
                                        </dd>
                                        <dt className="font-medium">
                                            Should hold
                                        </dt>
                                        <dd className="text-right font-medium tabular-nums">
                                            {money(expected)}
                                        </dd>
                                    </>
                                )}
                            {till.status === 'closed' && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Counted by {till.closedBy}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {money(till.countedCashMinor ?? 0)}
                                    </dd>
                                    {till.differenceMinor !== null && (
                                        <>
                                            <dt className="font-medium">
                                                Over / short
                                            </dt>
                                            <dd
                                                className={`text-right font-medium tabular-nums ${till.differenceMinor ? 'text-destructive' : ''}`}
                                            >
                                                {money(till.differenceMinor)}
                                            </dd>
                                        </>
                                    )}
                                </>
                            )}
                        </dl>
                        {till.closingNote && (
                            <p className="mt-3 text-sm">
                                <span className="text-muted-foreground">
                                    Note:{' '}
                                </span>
                                {till.closingNote}
                            </p>
                        )}

                        {till.status === 'open' && !canClose && (
                            <p className="mt-3 border-t pt-4 text-sm text-muted-foreground">
                                A manager counts the cash and closes shifts at
                                this branch.
                            </p>
                        )}

                        {canClose && (
                            <Form
                                {...close.form(till.id)}
                                className="mt-3 grid gap-3 border-t pt-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="counted_cash">
                                                Cash counted in the drawer
                                            </Label>
                                            <Input
                                                id="counted_cash"
                                                name="counted_cash"
                                                inputMode="decimal"
                                                required
                                            />
                                            {errors.counted_cash && (
                                                <p className="text-sm text-destructive">
                                                    {errors.counted_cash}
                                                </p>
                                            )}
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="closing_note">
                                                {shifts.blind
                                                    ? 'Note (optional)'
                                                    : 'Note (needed when the count differs)'}
                                            </Label>
                                            <Input
                                                id="closing_note"
                                                name="closing_note"
                                            />
                                            {errors.closing_note && (
                                                <p className="text-sm text-destructive">
                                                    {errors.closing_note}
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Close shift
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            </div>
        </>
    );
}
