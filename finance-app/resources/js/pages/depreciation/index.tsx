import { Form, Head, Link } from '@inertiajs/react';
import { Undo2 } from 'lucide-react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
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
import { destroy, store } from '@/routes/depreciation';
import { show as showAsset } from '@/routes/fixed-assets';
import { show as showJournal } from '@/routes/journals';

type Props = {
    next: string | null;
    nextLabel: string | null;
    runnable: boolean;
    preview: {
        id: string;
        number: string;
        name: string;
        category: string;
        amountMinor: number;
    }[];
    runs: {
        id: string;
        month: string;
        journalId: string | null;
        journalNumber: string | null;
        totalMinor: number;
        assets: number;
        by: string | null;
    }[];
};

/**
 * Depreciation, one month at a time: what the next month charges, running
 * it, and undoing the latest run.
 */
export default function Depreciation({
    next,
    nextLabel,
    runnable,
    preview,
    runs,
}: Props) {
    const can = useCan();
    const manage = can('finance.assets.manage');
    const total = preview.reduce((sum, row) => sum + row.amountMinor, 0);

    return (
        <>
            <Head title="Depreciation" />
            <PageHeader
                title="Depreciation"
                description="Run once a month, in order. Each run posts one journal at the month end, never below an asset's residual value."
            />

            <Panel className="mb-3">
                <PanelHeader
                    title={
                        nextLabel ? `Next: ${nextLabel}` : 'Nothing to run yet'
                    }
                    description={
                        next === null
                            ? 'Register assets first.'
                            : runnable
                              ? undefined
                              : 'This month has not started yet.'
                    }
                    actions={
                        manage &&
                        next !== null &&
                        runnable && (
                            <Form
                                {...store.form()}
                                options={{ preserveScroll: true }}
                            >
                                {({ processing }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="month"
                                            value={next}
                                        />
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Run {nextLabel}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )
                    }
                />
                {next !== null && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Asset</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead className="text-right">
                                    Depreciation
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {preview.length === 0 && (
                                <TableEmpty colSpan={3}>
                                    Nothing to depreciate this month.
                                </TableEmpty>
                            )}
                            {preview.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={showAsset(row.id)}
                                            className="font-mono text-xs text-primary hover:underline"
                                        >
                                            {row.number}
                                        </Link>{' '}
                                        {row.name}
                                    </TableCell>
                                    <TableCell>{row.category}</TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={row.amountMinor} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        {preview.length > 0 && (
                            <TableFooter>
                                <TableRow className="hover:bg-transparent">
                                    <TableCell colSpan={2}>Total</TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={total} />
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                )}
            </Panel>

            <Panel>
                <PanelHeader title="Runs" />
                {runs.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        No months run yet.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Month</TableHead>
                                <TableHead>Journal</TableHead>
                                <TableHead className="text-right">
                                    Assets
                                </TableHead>
                                <TableHead className="text-right">
                                    Depreciation
                                </TableHead>
                                <TableHead>By</TableHead>
                                {manage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {runs.map((run, index) => (
                                <TableRow key={run.id}>
                                    <TableCell>{run.month}</TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {run.journalId ? (
                                            <Link
                                                href={showJournal(
                                                    run.journalId,
                                                )}
                                                className="text-primary hover:underline"
                                            >
                                                {run.journalNumber}
                                            </Link>
                                        ) : (
                                            '—'
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.assets}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={run.totalMinor} />
                                    </TableCell>
                                    <TableCell>{run.by}</TableCell>
                                    {manage && (
                                        <TableCell className="text-right">
                                            {index === 0 && (
                                                <Form
                                                    {...destroy.form(run.id)}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    {({
                                                        processing,
                                                        errors,
                                                    }) => (
                                                        <>
                                                            <Button
                                                                type="submit"
                                                                size="sm"
                                                                variant="ghost"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                <Undo2
                                                                    className="size-4"
                                                                    aria-hidden
                                                                />
                                                                Undo
                                                            </Button>
                                                            {errors.run && (
                                                                <p className="text-xs text-destructive">
                                                                    {errors.run}
                                                                </p>
                                                            )}
                                                        </>
                                                    )}
                                                </Form>
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
