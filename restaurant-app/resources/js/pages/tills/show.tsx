import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Label } from '@miles/ui/components/ui/label';
import { formatMoney } from '@/lib/money';
import { statusTone } from '@/lib/status';
import { close, index } from '@/routes/tills';
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
};

/**
 * One cash shift: what went through it and, while open, the count that
 * closes it. A count that differs from what the drawer should hold needs a
 * reason.
 */
export default function ShowShift({ till, summary, methods }: Props) {
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
                                {...close.form(till.id)}
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
        </>
    );
}
