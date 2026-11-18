import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { index as handovers, store as handOver } from '@/routes/handovers';
import { close, index } from '@/routes/tills';
import { HandoverBadge, HandoverReceipt, type Handover } from './handovers';
import { STATUS_LABELS, type Till } from './index';

type Props = {
    till: Till;
    summary: {
        billsCount: number;
        takenMinor: Record<string, number>;
        cashRefundsMinor: number;
        refundsCount: number;
        expectedCashMinor: number;
    };
    methods: { value: string; label: string }[];
    handover: Handover | null;
    canHandOver: boolean;
    suggestedHandover: string | null;
};

/**
 * One cash shift: what went through it and, while open, the count that
 * closes it. A count that differs from what the drawer should hold needs a
 * reason.
 */
export default function ShowShift({
    till,
    summary,
    methods,
    handover,
    canHandOver,
    suggestedHandover,
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
                            <dt className="text-muted-foreground">Bills</dt>
                            <dd className="text-right tabular-nums">
                                {summary.billsCount}
                            </dd>
                            {methods.map((method) => (
                                <div key={method.value} className="contents">
                                    <dt className="text-muted-foreground">
                                        {method.label}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {money(
                                            summary.takenMinor[method.value] ??
                                                0,
                                        )}
                                    </dd>
                                </div>
                            ))}
                            <dt className="text-muted-foreground">
                                Cash given back ({summary.refundsCount} bills
                                voided)
                            </dt>
                            <dd className="text-right tabular-nums">
                                −{money(summary.cashRefundsMinor)}
                            </dd>
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Cash drawer" />
                    <PanelBody>
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            <dt className="text-muted-foreground">
                                Starting cash
                            </dt>
                            <dd className="text-right tabular-nums">
                                {money(till.openingFloatMinor)}
                            </dd>
                            <dt className="font-medium">Should hold</dt>
                            <dd className="text-right font-medium tabular-nums">
                                {money(expected)}
                            </dd>
                            {till.countedCashMinor !== null && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Counted by {till.closedBy}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {money(till.countedCashMinor)}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Over / short
                                    </dt>
                                    <dd
                                        className={`text-right tabular-nums ${till.differenceMinor ? 'font-medium text-destructive' : ''}`}
                                    >
                                        {money(till.differenceMinor ?? 0)}
                                    </dd>
                                </>
                            )}
                        </dl>
                        {till.closingNote && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                “{till.closingNote}”
                            </p>
                        )}

                        {till.status === 'open' && (
                            <Form
                                {...close.form({ till: till.id })}
                                className="mt-4 grid gap-3 border-t pt-4"
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
                                                className="w-44"
                                            />
                                            {errors.counted_cash && (
                                                <p className="text-sm text-destructive">
                                                    {errors.counted_cash}
                                                </p>
                                            )}
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="closing_note">
                                                Note
                                            </Label>
                                            <Input
                                                id="closing_note"
                                                name="closing_note"
                                                placeholder="Needed when the count differs"
                                            />
                                            {errors.closing_note && (
                                                <p className="text-sm text-destructive">
                                                    {errors.closing_note}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Close the shift
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            {(handover || canHandOver) && (
                <Panel className="mt-3">
                    <PanelHeader
                        title="Cash handover"
                        description="The counted cash goes to a manager, who counts it again and receives it."
                    />
                    <PanelBody>
                        {handover ? (
                            <div className="grid gap-1 text-sm">
                                <p className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {handover.number}
                                    </span>
                                    <HandoverBadge status={handover.status} />
                                    <span>
                                        {money(handover.amountMinor)} handed
                                        over by {handover.handedBy},{' '}
                                        {new Date(
                                            handover.handedAt,
                                        ).toLocaleString()}
                                    </span>
                                </p>
                                {handover.note && (
                                    <p className="text-muted-foreground">
                                        “{handover.note}”
                                    </p>
                                )}
                                {handover.status === 'received' ? (
                                    <HandoverReceipt
                                        handover={handover}
                                        money={money}
                                    />
                                ) : (
                                    <p className="text-muted-foreground">
                                        Waiting for a manager to receive it.{' '}
                                        <Link
                                            href={handovers()}
                                            className="text-primary hover:underline"
                                        >
                                            Cash handovers
                                        </Link>
                                    </p>
                                )}
                            </div>
                        ) : (
                            <Form
                                {...handOver.form({ till: till.id })}
                                className="grid gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="amount">
                                                Cash handed over
                                            </Label>
                                            <Input
                                                id="amount"
                                                name="amount"
                                                inputMode="decimal"
                                                defaultValue={
                                                    suggestedHandover ?? ''
                                                }
                                                required
                                                className="w-44"
                                            />
                                            {errors.amount && (
                                                <p className="text-sm text-destructive">
                                                    {errors.amount}
                                                </p>
                                            )}
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="note">Note</Label>
                                            <Input
                                                id="note"
                                                name="note"
                                                placeholder="Needed when it is not the cash counted"
                                            />
                                            {errors.note && (
                                                <p className="text-sm text-destructive">
                                                    {errors.note}
                                                </p>
                                            )}
                                        </div>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Hand over the cash
                                            </Button>
                                        </div>
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
