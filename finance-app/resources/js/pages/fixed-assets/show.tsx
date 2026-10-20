import { Form, Head, Link } from '@inertiajs/react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { useCan } from '@/lib/access';
import { show as showBill } from '@/routes/bills';
import { dispose } from '@/routes/fixed-assets';
import { show as showJournal } from '@/routes/journals';

type Props = {
    asset: {
        id: string;
        number: string;
        name: string;
        category: string;
        accounts: { asset: string; accumulated: string; expense: string };
        acquiredOn: string;
        costMinor: number;
        residualMinor: number;
        openingDepreciationMinor: number;
        accumulatedMinor: number;
        valueMinor: number;
        method: string;
        lifeMonths: number;
        rate: string | null;
        acquisition: { id: string; number: string } | null;
        bill: { id: string; number: string } | null;
        disposedOn: string | null;
        disposalProceedsMinor: number | null;
        disposalJournalId: string | null;
    };
    schedule: {
        month: string;
        amountMinor: number;
        valueMinor: number;
        posted: boolean;
    }[];
    banks: { id: string; label: string }[];
    today: string;
};

/** "2026-03" → "Mar 2026". */
function monthLabel(month: string): string {
    const [year, number] = month.split('-').map(Number);

    return new Date(year, number - 1, 1).toLocaleDateString(undefined, {
        month: 'short',
        year: 'numeric',
    });
}

/**
 * One asset: its details, its depreciation month by month (run or still to
 * come), and disposal.
 */
export default function ShowFixedAsset({
    asset,
    schedule,
    banks,
    today,
}: Props) {
    const can = useCan();
    const manage = can('finance.assets.manage');

    return (
        <>
            <Head title={`${asset.number} ${asset.name}`} />
            <PageHeader
                title={`${asset.number} ${asset.name}`}
                description={`${asset.category} · ${asset.method}${asset.rate ? `, ${asset.rate}% a year` : ''} · ${asset.lifeMonths} months`}
                badges={
                    asset.disposedOn ? (
                        <StatusBadge>Disposed {asset.disposedOn}</StatusBadge>
                    ) : (
                        <StatusBadge tone="success">Held</StatusBadge>
                    )
                }
            />

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Depreciation"
                        description="Months already run, and what is still to come."
                    />
                    <div className="max-h-[32rem] overflow-y-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Month</TableHead>
                                    <TableHead className="text-right">
                                        Depreciation
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Value at the end
                                    </TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {schedule.map((row) => (
                                    <TableRow
                                        key={row.month}
                                        className={
                                            row.posted
                                                ? undefined
                                                : 'text-muted-foreground'
                                        }
                                    >
                                        <TableCell className="tabular-nums">
                                            {monthLabel(row.month)}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount
                                                minor={row.amountMinor}
                                                blankZero
                                            />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Amount minor={row.valueMinor} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {row.posted && (
                                                <StatusBadge tone="success">
                                                    Run
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Panel>

                <div className="grid content-start gap-3">
                    <Panel>
                        <PanelHeader title="Summary" />
                        <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-2 p-4 text-sm">
                            <dt className="text-muted-foreground">Acquired</dt>
                            <dd className="tabular-nums">{asset.acquiredOn}</dd>
                            <dt className="text-muted-foreground">Cost</dt>
                            <dd>
                                <Amount minor={asset.costMinor} />
                            </dd>
                            {asset.residualMinor > 0 && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Residual value
                                    </dt>
                                    <dd>
                                        <Amount minor={asset.residualMinor} />
                                    </dd>
                                </>
                            )}
                            {asset.openingDepreciationMinor > 0 && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Brought in depreciated
                                    </dt>
                                    <dd>
                                        <Amount
                                            minor={
                                                asset.openingDepreciationMinor
                                            }
                                        />
                                    </dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">
                                Depreciation
                            </dt>
                            <dd>
                                <Amount minor={asset.accumulatedMinor} />
                            </dd>
                            <dt className="text-muted-foreground">Value</dt>
                            <dd className="font-semibold">
                                <Amount minor={asset.valueMinor} />
                            </dd>
                            <dt className="text-muted-foreground">Bought by</dt>
                            <dd>
                                {asset.bill ? (
                                    <Link
                                        href={showBill(asset.bill.id)}
                                        className="text-primary hover:underline"
                                    >
                                        Bill {asset.bill.number}
                                    </Link>
                                ) : asset.acquisition ? (
                                    <Link
                                        href={showJournal(asset.acquisition.id)}
                                        className="text-primary hover:underline"
                                    >
                                        Journal {asset.acquisition.number}
                                    </Link>
                                ) : (
                                    'Already in the books'
                                )}
                            </dd>
                            <dt className="text-muted-foreground">Accounts</dt>
                            <dd className="text-xs">
                                {asset.accounts.asset}
                                <br />
                                {asset.accounts.accumulated}
                                <br />
                                {asset.accounts.expense}
                            </dd>
                            {asset.disposedOn && (
                                <>
                                    <dt className="text-muted-foreground">
                                        Proceeds
                                    </dt>
                                    <dd>
                                        <Amount
                                            minor={
                                                asset.disposalProceedsMinor ?? 0
                                            }
                                        />
                                        {asset.disposalJournalId && (
                                            <>
                                                {' · '}
                                                <Link
                                                    href={showJournal(
                                                        asset.disposalJournalId,
                                                    )}
                                                    className="text-primary hover:underline"
                                                >
                                                    journal
                                                </Link>
                                            </>
                                        )}
                                    </dd>
                                </>
                            )}
                        </dl>
                    </Panel>

                    {manage && !asset.disposedOn && (
                        <Panel>
                            <PanelHeader
                                title="Dispose of it"
                                description="Sold or written off: its cost and depreciation leave the books, with the gain or loss."
                            />
                            <PanelBody>
                                <Form
                                    {...dispose.form(asset.id)}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid grid-cols-2 gap-3">
                                                <Field
                                                    id="disposed_on"
                                                    label="Date"
                                                    error={errors.disposed_on}
                                                >
                                                    <Input
                                                        id="disposed_on"
                                                        name="disposed_on"
                                                        type="date"
                                                        defaultValue={today}
                                                        required
                                                    />
                                                </Field>
                                                <Field
                                                    id="proceeds"
                                                    label="Proceeds"
                                                    error={errors.proceeds}
                                                >
                                                    <Input
                                                        id="proceeds"
                                                        name="proceeds"
                                                        inputMode="decimal"
                                                        className="text-right tabular-nums"
                                                        placeholder="0"
                                                    />
                                                </Field>
                                            </div>
                                            <Field
                                                id="account_id"
                                                label="Received into"
                                                error={errors.account_id}
                                                hint="Leave blank when written off for nothing."
                                            >
                                                <Select
                                                    id="account_id"
                                                    name="account_id"
                                                    defaultValue=""
                                                >
                                                    <option value="">
                                                        Nothing received
                                                    </option>
                                                    {banks.map((bank) => (
                                                        <option
                                                            key={bank.id}
                                                            value={bank.id}
                                                        >
                                                            {bank.label}
                                                        </option>
                                                    ))}
                                                </Select>
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                Dispose of {asset.number}
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}
