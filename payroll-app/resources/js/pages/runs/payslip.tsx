import { Head, Link } from '@inertiajs/react';
import { FileDown, Printer } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { formatDate } from '@/components/fields';
import { formatMoney } from '@/lib/money';
import { show } from '@/routes/runs';
import { pdf } from '@/routes/runs/payslip';

type Line = { kind: string; name: string; amount_minor: number };

type Props = {
    currency: string | null;
    organization: string | null;
    run: { id: string; number: string; period: string; payDate: string };
    payslip: {
        id: string;
        number: string;
        name: string;
        jobTitle: string | null;
        branch: string | null;
        department: string | null;
        daysPaid: string | null;
        hoursPaid: string | null;
        earningsMinor: number;
        taxableMinor: number;
        netMinor: number;
        lines: Line[];
    };
};

function Rows({ lines }: { lines: Line[] }) {
    return (
        <>
            {lines.map((line, i) => (
                <div
                    key={`${line.name}-${i}`}
                    className="flex justify-between border-b py-1.5 text-sm last:border-0"
                >
                    <span>{line.name}</span>
                    <span className="tabular-nums">
                        {formatMoney(line.amount_minor)}
                    </span>
                </div>
            ))}
        </>
    );
}

export default function Payslip({
    currency,
    organization,
    run,
    payslip,
}: Props) {
    const earnings = payslip.lines.filter((line) => line.kind === 'earning');
    const deductions = payslip.lines.filter((line) =>
        ['tax', 'social_security', 'deduction'].includes(line.kind),
    );
    const employer = payslip.lines.filter((line) => line.kind === 'employer');
    const deducted = deductions.reduce(
        (sum, line) => sum + line.amount_minor,
        0,
    );

    return (
        <>
            <Head title={`Payslip ${payslip.name}`} />
            <div className="print:hidden">
                <PageHeader
                    title={`Payslip: ${payslip.name}`}
                    actions={
                        <>
                            <Button asChild variant="ghost">
                                <Link href={show(run.id)}>
                                    Back to {run.number}
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => window.print()}
                            >
                                <Printer className="size-4" aria-hidden />
                                Print
                            </Button>
                            <Button asChild>
                                <a
                                    href={
                                        pdf({
                                            run: run.id,
                                            payslip: payslip.id,
                                        }).url
                                    }
                                    download
                                >
                                    <FileDown className="size-4" aria-hidden />
                                    Download PDF
                                </a>
                            </Button>
                        </>
                    }
                />
            </div>

            <Panel className="mx-auto max-w-2xl">
                <PanelBody className="grid gap-5">
                    <div className="flex flex-wrap justify-between gap-3">
                        <div>
                            <p className="text-lg font-semibold">
                                {organization}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Payslip for {run.period}, paid{' '}
                                {formatDate(run.payDate)}
                            </p>
                        </div>
                        <div className="text-right text-sm">
                            <p className="font-medium">{payslip.name}</p>
                            <p className="text-muted-foreground">
                                {payslip.number}
                                {payslip.jobTitle && `, ${payslip.jobTitle}`}
                            </p>
                            {(payslip.branch || payslip.department) && (
                                <p className="text-muted-foreground">
                                    {[payslip.branch, payslip.department]
                                        .filter(Boolean)
                                        .join(', ')}
                                </p>
                            )}
                            {payslip.daysPaid !== null && (
                                <p className="text-muted-foreground">
                                    {Number(payslip.daysPaid)} days paid
                                </p>
                            )}
                            {payslip.hoursPaid !== null && (
                                <p className="text-muted-foreground">
                                    {Number(payslip.hoursPaid)} hours paid
                                </p>
                            )}
                        </div>
                    </div>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <section>
                            <h2 className="mb-1 text-sm font-semibold">
                                Earnings
                            </h2>
                            <Rows lines={earnings} />
                            <div className="flex justify-between pt-2 text-sm font-semibold">
                                <span>Total</span>
                                <span className="tabular-nums">
                                    {formatMoney(payslip.earningsMinor)}
                                </span>
                            </div>
                        </section>
                        <section>
                            <h2 className="mb-1 text-sm font-semibold">
                                Deductions
                            </h2>
                            {deductions.length === 0 ? (
                                <p className="py-1.5 text-sm text-muted-foreground">
                                    None
                                </p>
                            ) : (
                                <Rows lines={deductions} />
                            )}
                            <div className="flex justify-between pt-2 text-sm font-semibold">
                                <span>Total</span>
                                <span className="tabular-nums">
                                    {formatMoney(deducted)}
                                </span>
                            </div>
                        </section>
                    </div>

                    <div className="flex items-center justify-between border-t pt-3">
                        <span className="font-semibold">Net pay</span>
                        <span className="text-xl font-semibold tabular-nums">
                            {formatMoney(payslip.netMinor, currency)}
                        </span>
                    </div>

                    <div className="text-xs text-muted-foreground">
                        Taxable pay {formatMoney(payslip.taxableMinor)}
                        {employer.map((line) => (
                            <span key={line.name}>
                                {' '}
                                · {line.name} {formatMoney(line.amount_minor)}
                            </span>
                        ))}
                    </div>
                </PanelBody>
            </Panel>
        </>
    );
}
