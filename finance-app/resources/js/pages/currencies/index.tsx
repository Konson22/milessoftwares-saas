import { Form, Head, Link, router } from '@inertiajs/react';
import { Coins, X } from 'lucide-react';
import { Amount } from '@/components/amount';
import { Field } from '@/components/field';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { index, revalue } from '@/routes/currencies';
import {
    destroy as destroyRate,
    store as storeRate,
} from '@/routes/currencies/rates';
import { show as showJournal } from '@/routes/journals';

type Props = {
    currencies: {
        currency: string;
        latest: { date: string; rate: string };
        rates: { id: string; date: string; rate: string }[];
    }[];
    accounts: { label: string; currency: string }[];
    revaluations: {
        id: string;
        asOf: string;
        journalId: string | null;
        journalNumber: string | null;
        reversalJournalId: string | null;
        by: string;
    }[];
    asOf: string;
    preview: {
        kind: 'bank' | 'invoice' | 'bill';
        label: string;
        currency: string;
        currencyMinor: number;
        carriedMinor: number;
        valueMinor: number;
        differenceMinor: number;
        rate: string;
    }[];
    previewError: string | null;
    revalued: boolean;
    booksCurrency: string;
    today: string;
};

const KINDS = { bank: 'Account', invoice: 'Invoice', bill: 'Bill' } as const;

/**
 * Other currencies: the rates entries are converted at, and revaluing
 * money and open documents held in them at a day's rate.
 */
export default function Currencies({
    currencies,
    accounts,
    revaluations,
    asOf,
    preview,
    previewError,
    revalued,
    booksCurrency,
    today,
}: Props) {
    const can = useCan();
    const manage = can('finance.currencies.manage');
    // A bill that grows is a loss: its difference counts against the gain.
    const gain = preview.reduce(
        (sum, row) =>
            sum +
            (row.kind === 'bill' ? -row.differenceMinor : row.differenceMinor),
        0,
    );

    return (
        <>
            <Head title="Currencies" />
            <PageHeader
                title="Currencies"
                description={`The books are kept in ${booksCurrency}. Rates say how much ${booksCurrency} one unit of another currency is worth.`}
            />

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Exchange rates"
                        description="Invoices, bills and payments use the latest rate on or before their date, unless one is typed."
                    />
                    {currencies.length === 0 ? (
                        <EmptyState icon={Coins} title="No rates yet">
                            Add a rate for each currency you invoice, buy or
                            bank in.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Currency</TableHead>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="text-right">
                                        {booksCurrency} per unit
                                    </TableHead>
                                    {manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {currencies.flatMap((currency) =>
                                    currency.rates.map((rate, position) => (
                                        <TableRow key={rate.id}>
                                            <TableCell className="font-medium">
                                                {position === 0 &&
                                                    currency.currency}
                                            </TableCell>
                                            <TableCell className="tabular-nums">
                                                {rate.date}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {rate.rate}
                                            </TableCell>
                                            {manage && (
                                                <TableCell className="text-right">
                                                    <Form
                                                        {...destroyRate.form(
                                                            rate.id,
                                                        )}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="ghost"
                                                                disabled={
                                                                    processing
                                                                }
                                                                aria-label={`Remove the ${currency.currency} rate of ${rate.date}`}
                                                            >
                                                                <X
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                            </Button>
                                                        )}
                                                    </Form>
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    )),
                                )}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                <div className="grid content-start gap-3">
                    {manage && (
                        <Panel>
                            <PanelHeader title="Add a rate" />
                            <PanelBody>
                                <Form
                                    {...storeRate.form()}
                                    resetOnSuccess={['rate']}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <div className="grid grid-cols-2 gap-3">
                                                <Field
                                                    id="currency"
                                                    label="Currency"
                                                    error={errors.currency}
                                                >
                                                    <Input
                                                        id="currency"
                                                        name="currency"
                                                        maxLength={3}
                                                        placeholder="USD"
                                                        className="uppercase"
                                                        required
                                                    />
                                                </Field>
                                                <Field
                                                    id="rate_on"
                                                    label="From"
                                                    error={errors.rate_on}
                                                >
                                                    <Input
                                                        id="rate_on"
                                                        name="rate_on"
                                                        type="date"
                                                        defaultValue={today}
                                                        required
                                                    />
                                                </Field>
                                            </div>
                                            <Field
                                                id="rate"
                                                label={`${booksCurrency} per unit`}
                                                error={errors.rate}
                                                hint="A rate for a day already set replaces it."
                                            >
                                                <Input
                                                    id="rate"
                                                    name="rate"
                                                    inputMode="decimal"
                                                    required
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Save rate
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="Accounts in other currencies" />
                        {accounts.length === 0 ? (
                            <p className="p-4 text-sm text-muted-foreground">
                                None. Give a bank or cash account a currency in
                                the chart of accounts.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {accounts.map((account) => (
                                    <li
                                        key={account.label}
                                        className="flex justify-between gap-3 px-4 py-2.5"
                                    >
                                        {account.label}
                                        <StatusBadge tone="info">
                                            {account.currency}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>
            </div>

            <Panel className="mt-3">
                <PanelHeader
                    title="Revaluation"
                    description="Brings money and open invoices and bills in other currencies to a day's rate, usually a month end. The journal is reversed the next day; payments still post the actual difference."
                />
                <PanelBody className="flex flex-wrap items-end gap-3">
                    <Field id="as_of" label="At the rates of">
                        <Input
                            id="as_of"
                            type="date"
                            defaultValue={asOf}
                            max={today}
                            onChange={(event) =>
                                event.target.value &&
                                router.get(
                                    index.url({
                                        query: { as_of: event.target.value },
                                    }),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        />
                    </Field>
                    {manage && (
                        <Form
                            {...revalue.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ errors, processing }) => (
                                <div className="grid gap-1">
                                    <input
                                        type="hidden"
                                        name="as_of"
                                        value={asOf}
                                    />
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            revalued ||
                                            previewError !== null
                                        }
                                    >
                                        {revalued
                                            ? 'Revalued on this day'
                                            : 'Revalue'}
                                    </Button>
                                    {errors.as_of && (
                                        <p className="text-sm text-destructive">
                                            {errors.as_of}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Form>
                    )}
                </PanelBody>
                {previewError ? (
                    <p className="border-t px-4 py-3 text-sm text-destructive">
                        {previewError}
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Revalued</TableHead>
                                <TableHead className="text-right">
                                    In its currency
                                </TableHead>
                                <TableHead className="text-right">
                                    Rate
                                </TableHead>
                                <TableHead className="text-right">
                                    In the books
                                </TableHead>
                                <TableHead className="text-right">
                                    At the rate
                                </TableHead>
                                <TableHead className="text-right">
                                    Difference
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {preview.length === 0 && (
                                <TableEmpty colSpan={6}>
                                    Nothing held in another currency on this
                                    day.
                                </TableEmpty>
                            )}
                            {preview.map((row) => (
                                <TableRow key={`${row.kind}-${row.label}`}>
                                    <TableCell>
                                        <span className="text-muted-foreground">
                                            {KINDS[row.kind]}
                                        </span>{' '}
                                        {row.kind === 'bank'
                                            ? row.label
                                            : row.label.replace(
                                                  /^(Invoice|Bill) /,
                                                  '',
                                              )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={row.currencyMinor}
                                            currency={row.currency}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.rate}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={row.carriedMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={row.valueMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount
                                            minor={row.differenceMinor}
                                            blankZero
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        {preview.length > 0 && (
                            <TableFooter>
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={5}>
                                        Unrealised exchange{' '}
                                        {gain >= 0 ? 'gain' : 'loss'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={Math.abs(gain)} />
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                )}
            </Panel>

            {revaluations.length > 0 && (
                <Panel className="mt-3">
                    <PanelHeader title="Past revaluations" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>At</TableHead>
                                <TableHead>Journal</TableHead>
                                <TableHead>By</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {revaluations.map((revaluation) => (
                                <TableRow key={revaluation.id}>
                                    <TableCell className="tabular-nums">
                                        {revaluation.asOf}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {revaluation.journalId ? (
                                            <>
                                                <Link
                                                    href={showJournal(
                                                        revaluation.journalId,
                                                    )}
                                                    className="text-primary hover:underline"
                                                >
                                                    {revaluation.journalNumber}
                                                </Link>
                                                {revaluation.reversalJournalId && (
                                                    <>
                                                        {' · '}
                                                        <Link
                                                            href={showJournal(
                                                                revaluation.reversalJournalId,
                                                            )}
                                                            className="text-primary hover:underline"
                                                        >
                                                            reversal
                                                        </Link>
                                                    </>
                                                )}
                                            </>
                                        ) : (
                                            <span className="font-sans text-muted-foreground">
                                                Nothing to revalue
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>{revaluation.by}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}
        </>
    );
}
