import { Form, Head, Link } from '@inertiajs/react';
import { HandCoins } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, invalid } from '@/components/field';
import { Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import { index, show, store } from '@/routes/advances';

type Advance = {
    id: string;
    reference: string;
    holderId: string;
    holder: string;
    purpose: string;
    status: string;
    amountMinor: number;
    outstandingMinor: number;
    currency: string;
    dueOn: string | null;
    daysOverdue: number;
};

type Props = {
    mine: Advance[];
    all: Advance[];
    aging: {
        buckets: { label: string; amountMinor: number }[];
        totalMinor: number;
    } | null;
    holderFilter: string | null;
    currency: string;
    maxMinor: number | null;
    retireDays: number | null;
    canRequest: boolean;
};

function Advances({
    advances,
    showHolder,
}: {
    advances: Advance[];
    showHolder: boolean;
}) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Advance</TableHead>
                    {showHolder && <TableHead>Held by</TableHead>}
                    <TableHead>Status</TableHead>
                    <TableHead>Account for it by</TableHead>
                    <TableHead className="text-right">Amount</TableHead>
                    <TableHead className="text-right">Still owed</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {advances.map((advance) => (
                    <TableRow key={advance.id}>
                        <TableCell>
                            <Link
                                href={show(advance.id)}
                                className="font-medium hover:underline"
                            >
                                {advance.reference}
                            </Link>
                            <p className="text-xs text-muted-foreground">
                                {advance.purpose}
                            </p>
                        </TableCell>
                        {showHolder && (
                            <TableCell>
                                <Link
                                    href={index({
                                        query: { holder: advance.holderId },
                                    })}
                                    className="hover:underline"
                                >
                                    {advance.holder}
                                </Link>
                            </TableCell>
                        )}
                        <TableCell>
                            <Status status={advance.status} />
                        </TableCell>
                        <TableCell className="text-muted-foreground">
                            {advance.dueOn ? formatDate(advance.dueOn) : '—'}
                            {advance.daysOverdue > 0 && (
                                <p className="font-medium text-destructive">
                                    {advance.daysOverdue} days late
                                </p>
                            )}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {formatMoney(advance.amountMinor, advance.currency)}
                        </TableCell>
                        <TableCell className="text-right tabular-nums">
                            {formatMoney(
                                advance.outstandingMinor,
                                advance.currency,
                            )}
                        </TableCell>
                    </TableRow>
                ))}
            </TableBody>
        </Table>
    );
}

/**
 * Advances: your own, and if you may view them, everyone's by age.
 */
export default function StaffAdvancesPage({
    mine,
    all,
    aging,
    holderFilter,
    currency,
    maxMinor,
    retireDays,
    canRequest,
}: Props) {
    const noPolicy = maxMinor === null || retireDays === null;

    return (
        <>
            <Head title="Staff advances" />
            <PageHeader
                title="Staff advances"
                description="Money given ahead of spending it. An asset until the receipts are approved."
            />

            <div className="grid gap-4">
                {noPolicy && (
                    <p
                        role="status"
                        className="border border-input bg-card p-3 text-sm"
                    >
                        No advance policy is set, so nothing can be advanced
                        yet. Someone who runs the organization sets the largest
                        advance and the days allowed in Administration,
                        Settings.
                    </p>
                )}

                {aging && (
                    <Panel>
                        <PanelHeader title="Outstanding, by age" />
                        <PanelBody>
                            <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                                {aging.buckets.map((bucket) => (
                                    <div key={bucket.label}>
                                        <dt className="text-muted-foreground">
                                            {bucket.label}
                                        </dt>
                                        <dd className="font-medium tabular-nums">
                                            {formatMoney(
                                                bucket.amountMinor,
                                                currency,
                                            )}
                                        </dd>
                                    </div>
                                ))}
                                <div>
                                    <dt className="text-muted-foreground">
                                        Total
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {formatMoney(
                                            aging.totalMinor,
                                            currency,
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </PanelBody>
                    </Panel>
                )}

                {canRequest && !noPolicy && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Ask for an advance" />
                        <PanelBody>
                            <Form
                                {...store.form()}
                                className="grid gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="amount"
                                            label={`Amount (${currency})`}
                                            error={errors.amount}
                                            hint={`Up to ${formatMoney(maxMinor, currency)}. You will have ${retireDays} days to account for it.`}
                                        >
                                            <Input
                                                id="amount"
                                                name="amount"
                                                required
                                                inputMode="decimal"
                                                {...invalid(
                                                    'amount',
                                                    errors.amount,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="purpose"
                                            label="What it is for"
                                            error={errors.purpose}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="purpose"
                                                name="purpose"
                                                required
                                                maxLength={255}
                                                {...invalid(
                                                    'purpose',
                                                    errors.purpose,
                                                )}
                                            />
                                        </Field>
                                        <div className="sm:col-span-3">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Ask for advance
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader title="My advances" />
                    {mine.length === 0 ? (
                        <EmptyState
                            icon={HandCoins}
                            title="You have no advances"
                        />
                    ) : (
                        <Advances advances={mine} showHolder={false} />
                    )}
                </Panel>

                {aging && (
                    <Panel>
                        <PanelHeader
                            title={
                                holderFilter
                                    ? "One person's advances"
                                    : 'All advances'
                            }
                            actions={
                                holderFilter && (
                                    <Link
                                        href={index()}
                                        className="text-sm underline"
                                    >
                                        Show everyone
                                    </Link>
                                )
                            }
                        />
                        {all.length === 0 ? (
                            <EmptyState
                                icon={HandCoins}
                                title="No advances yet"
                            />
                        ) : (
                            <Advances advances={all} showHolder />
                        )}
                    </Panel>
                )}
            </div>
        </>
    );
}
