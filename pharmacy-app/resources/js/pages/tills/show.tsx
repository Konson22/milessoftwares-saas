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
import type { Till } from './index';

type Props = {
    till: Till;
    summary: {
        salesCount: number;
        takenMinor: Record<string, number>;
        cashRefundsMinor: number;
        refundsCount: number;
        expectedCashMinor: number;
    };
    methods: { value: string; label: string }[];
};

/**
 * One till: what went through it and, while open, the count that closes
 * it. A count that differs from what it should hold needs a reason.
 */
export default function ShowTill({ till, summary, methods }: Props) {
    const { currency } = usePage().props;
    const money = (minor: number) => formatMoney(minor, currency ?? 'USD');
    const expected =
        till.status === 'closed' && till.expectedCashMinor !== null
            ? till.expectedCashMinor
            : summary.expectedCashMinor;

    return (
        <>
            <Head title={`Till ${till.number}`} />
            <PageHeader
                title={`Till ${till.number}`}
                description={`${till.openedBy}, opened ${new Date(till.openedAt).toLocaleString()}`}
                badges={
                    <StatusBadge tone={statusTone(till.status)}>
                        {till.status}
                    </StatusBadge>
                }
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Tills</Link>
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
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Cash in the drawer" />
                    <PanelBody>
                        <dl className="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 text-sm">
                            <dt className="text-muted-foreground">Float</dt>
                            <dd className="text-right tabular-nums">
                                {money(till.openingFloatMinor)}
                            </dd>
                            <dt className="text-muted-foreground">
                                Cash taken
                            </dt>
                            <dd className="text-right tabular-nums">
                                {money(summary.takenMinor.cash ?? 0)}
                            </dd>
                            <dt className="text-muted-foreground">
                                Cash given back ({summary.refundsCount})
                            </dt>
                            <dd className="text-right tabular-nums">
                                −{money(summary.cashRefundsMinor)}
                            </dd>
                            <dt className="font-medium">Should hold</dt>
                            <dd className="text-right font-medium tabular-nums">
                                {money(expected)}
                            </dd>
                            {till.status === 'closed' && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Counted by {till.closedBy}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {money(till.countedCashMinor ?? 0)}
                                    </dd>
                                    <dt className="font-medium">
                                        Over / short
                                    </dt>
                                    <dd
                                        className={`text-right font-medium tabular-nums ${till.differenceMinor ? 'text-destructive' : ''}`}
                                    >
                                        {money(till.differenceMinor ?? 0)}
                                    </dd>
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

                        {till.status === 'open' && (
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
                                                Note (needed when the count
                                                differs)
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
                                            Close till
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
