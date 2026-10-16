import { Head, Link } from '@inertiajs/react';
import { NotebookPen } from 'lucide-react';
import { Amount } from '@/components/amount';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { PeriodFilter } from '@/components/period-filter';
import { SearchField } from '@miles/ui/components/search-field';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { useCan } from '@/lib/access';
import { sourceLabel, sourceTypeLabel } from '@/lib/format';
import { create, index, show } from '@/routes/journals';

type Props = {
    from: string;
    to: string;
    search: string;
    journals: {
        id: string;
        number: string;
        date: string;
        memo: string;
        source: string;
        sourceType: string;
        sourceReference: string | null;
        totalMinor: number;
        reversed: boolean;
        reversal: boolean;
    }[];
};

/**
 * Everything posted in a period, newest first.
 */
export default function Journals({ from, to, search, journals }: Props) {
    const can = useCan();

    return (
        <>
            <Head title="Journals" />
            <PageHeader
                title="Journals"
                description="Every entry in the books. Entries from products are posted automatically."
                actions={
                    can('finance.journals.post') && (
                        <Button asChild>
                            <Link href={create()}>
                                <NotebookPen className="size-4" aria-hidden />
                                New journal
                            </Link>
                        </Button>
                    )
                }
            />

            <Panel>
                <PanelToolbar>
                    <PeriodFilter form={index.form()} from={from} to={to}>
                        <SearchField
                            name="search"
                            defaultValue={search}
                            placeholder="Number, memo or reference"
                            aria-label="Search journals"
                            className="w-64"
                        />
                    </PeriodFilter>
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Journal</TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Memo</TableHead>
                            <TableHead>Kind</TableHead>
                            <TableHead>From</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {journals.length === 0 && (
                            <TableEmpty colSpan={6}>
                                No journals in this period.
                            </TableEmpty>
                        )}
                        {journals.map((journal) => (
                            <TableRow key={journal.id}>
                                <TableCell className="font-mono text-xs">
                                    <Link
                                        href={show(journal.id)}
                                        className="text-primary hover:underline"
                                    >
                                        {journal.number}
                                    </Link>
                                </TableCell>
                                <TableCell className="tabular-nums">
                                    {journal.date}
                                </TableCell>
                                <TableCell className="max-w-96">
                                    <span className="block truncate">
                                        {journal.memo}
                                    </span>
                                    {journal.sourceReference && (
                                        <span className="block text-xs text-muted-foreground">
                                            {journal.sourceReference}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="space-x-1">
                                    <span className="text-sm">
                                        {sourceTypeLabel(journal.sourceType)}
                                    </span>
                                    {journal.reversed && (
                                        <StatusBadge tone="warning">
                                            Reversed
                                        </StatusBadge>
                                    )}
                                </TableCell>
                                <TableCell>
                                    <StatusBadge
                                        tone={
                                            journal.source === 'finance'
                                                ? 'neutral'
                                                : 'info'
                                        }
                                    >
                                        {sourceLabel(journal.source)}
                                    </StatusBadge>
                                </TableCell>
                                <TableCell className="text-right">
                                    <Amount minor={journal.totalMinor} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
