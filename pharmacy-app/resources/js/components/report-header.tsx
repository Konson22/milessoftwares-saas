import { Form, Head, Link, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { cashiers, index, profitability, purchases } from '@/routes/reports';

const REPORTS = [
    { key: 'overview', title: 'Overview', route: index },
    { key: 'profitability', title: 'Profitability', route: profitability },
    { key: 'purchases', title: 'Purchases', route: purchases },
    { key: 'cashiers', title: 'Cashiers', route: cashiers },
] as const;

export type ReportKey = (typeof REPORTS)[number]['key'];

/**
 * The reports' title, tabs and period; switching tabs keeps the period.
 */
export function ReportHeader({
    current,
    from,
    to,
}: {
    current: ReportKey;
    from: string;
    to: string;
}) {
    const { branch } = usePage().props;
    const report = REPORTS.find((r) => r.key === current) ?? REPORTS[0];

    return (
        <>
            <Head
                title={
                    current === 'overview'
                        ? 'Reports'
                        : `${report.title} report`
                }
            />
            <PageHeader title="Reports" description={branch?.name} />

            <nav
                aria-label="Reports"
                className="mb-3 flex flex-wrap gap-1 print:hidden"
            >
                {REPORTS.map((r) => (
                    <Button
                        key={r.key}
                        variant={r.key === current ? 'secondary' : 'ghost'}
                        size="sm"
                        asChild
                    >
                        <Link href={r.route({ query: { from, to } })}>
                            {r.title}
                        </Link>
                    </Button>
                ))}
            </nav>

            <Panel className="mb-5 print:hidden">
                <PanelToolbar>
                    <Form
                        {...report.route.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors }) => (
                            <>
                                <div className="grid gap-1.5">
                                    <label
                                        htmlFor="from"
                                        className="text-sm font-medium"
                                    >
                                        From
                                    </label>
                                    <Input
                                        id="from"
                                        name="from"
                                        type="date"
                                        defaultValue={from}
                                        className="w-44"
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <label
                                        htmlFor="to"
                                        className="text-sm font-medium"
                                    >
                                        To
                                    </label>
                                    <Input
                                        id="to"
                                        name="to"
                                        type="date"
                                        defaultValue={to}
                                        className="w-44"
                                    />
                                </div>
                                <Button type="submit" variant="outline">
                                    Show
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => window.print()}
                                >
                                    Print
                                </Button>
                                {(errors.from || errors.to) && (
                                    <p className="w-full text-sm text-destructive">
                                        {errors.from ?? errors.to}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                </PanelToolbar>
            </Panel>
        </>
    );
}

export function margin(revenue: number, cost: number): string {
    return revenue === 0
        ? '–'
        : `${Math.round(((revenue - cost) / revenue) * 100)}%`;
}
