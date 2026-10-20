import { Form, Head, Link } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { TaxReport } from '@/components/tax-report';
import type { TaxReportData } from '@/components/tax-report';
import { useCan } from '@/lib/access';
import { show as showJournal } from '@/routes/journals';
import { destroy, settle, show } from '@/routes/tax-returns';

type Props = {
    taxReturn: {
        id: string;
        title: string;
        reference: string | null;
        netMinor: number;
        filedAt: string;
        filedBy: string;
        settled: boolean;
        settlementJournalId: string | null;
        settlementNumber: string | null;
        latest: boolean;
    };
    report: TaxReportData;
    changed: boolean;
    banks: { id: string; label: string }[];
    today: string;
};

/**
 * A filed tax return: its figures, the payment to (or refund from) the
 * tax authority, and unfiling while it is the latest.
 */
export default function TaxReturnShow({
    taxReturn,
    report,
    changed,
    banks,
    today,
}: Props) {
    const can = useCan();
    const manage = can('finance.tax.manage');
    const owed = taxReturn.netMinor > 0;

    return (
        <>
            <Head title={`Tax return ${taxReturn.title}`} />
            <PageHeader
                title={`Tax return ${taxReturn.title}`}
                description={`Filed ${new Date(taxReturn.filedAt).toLocaleDateString()} by ${taxReturn.filedBy}${taxReturn.reference ? ` · ${taxReturn.reference}` : ''}`}
                actions={
                    <Button variant="outline" asChild>
                        <a
                            href={show.url(taxReturn.id, {
                                query: { export: 'rows' },
                            })}
                            download
                        >
                            <Download className="size-4" aria-hidden />
                            CSV
                        </a>
                    </Button>
                }
            />

            {changed && (
                <p className="mb-3 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    The ledger now shows <Amount minor={report.net_minor} /> for
                    this period, not the <Amount minor={taxReturn.netMinor} />{' '}
                    filed. Something touching the tax account was posted in it
                    after filing.
                </p>
            )}

            <Panel className="mb-3">
                <TaxReport report={report} />
            </Panel>

            <Panel>
                <PanelHeader title="Settlement" />
                <PanelBody className="space-y-4">
                    {taxReturn.settlementJournalId !== null ? (
                        <p className="text-sm">
                            Settled in{' '}
                            <Link
                                href={showJournal.url(
                                    taxReturn.settlementJournalId,
                                )}
                                className="font-medium hover:underline"
                            >
                                {taxReturn.settlementNumber}
                            </Link>
                            .
                        </p>
                    ) : taxReturn.netMinor === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Nothing is owed either way.
                        </p>
                    ) : manage ? (
                        <Form
                            {...settle.form(taxReturn.id)}
                            className="flex flex-wrap items-end gap-3"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="account_id"
                                        label={
                                            owed ? 'Paid from' : 'Received into'
                                        }
                                        error={errors.account_id}
                                    >
                                        <Select
                                            id="account_id"
                                            name="account_id"
                                            required
                                            defaultValue=""
                                        >
                                            <option value="">
                                                Choose an account…
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
                                    <Field
                                        id="date"
                                        label="Date"
                                        error={
                                            errors.date ??
                                            errors.entry_date ??
                                            errors.return
                                        }
                                    >
                                        <Input
                                            id="date"
                                            name="date"
                                            type="date"
                                            required
                                            defaultValue={today}
                                        />
                                    </Field>
                                    <Button type="submit" disabled={processing}>
                                        {owed
                                            ? 'Record payment of '
                                            : 'Record refund of '}
                                        <Amount
                                            minor={Math.abs(taxReturn.netMinor)}
                                        />
                                    </Button>
                                </>
                            )}
                        </Form>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Not settled yet.
                        </p>
                    )}

                    {manage && taxReturn.latest && (
                        <Form
                            {...destroy.form(taxReturn.id)}
                            className="border-t pt-4"
                        >
                            {({ errors, processing }) => (
                                <div className="flex flex-wrap items-center gap-3">
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Unfile this return
                                    </Button>
                                    <span className="text-sm text-muted-foreground">
                                        Unlocks the period for tax
                                        {taxReturn.settled &&
                                            ' and reverses the settlement today'}
                                        .
                                    </span>
                                    {(errors.return ?? errors.journal) && (
                                        <p className="w-full text-sm text-destructive">
                                            {errors.return ?? errors.journal}
                                        </p>
                                    )}
                                </div>
                            )}
                        </Form>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
