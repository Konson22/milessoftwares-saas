import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, FileText } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { formatDate, statusTone } from '@/lib/status';

type Report = {
    id: string;
    title: string;
    kind: string;
    dueOn: string;
    status: string;
    statusLabel: string;
    submittedOn: string | null;
    acceptedOn: string | null;
    canDecide: boolean;
    isOwed: boolean;
    overdue: boolean;
    grant: string;
    beneficiary: string;
    url: string;
    documents: { id: string; name: string; size: number }[];
};

const FILTERS: { key: string; label: string; test: (r: Report) => boolean }[] =
    [
        { key: 'all', label: 'All', test: () => true },
        { key: 'decide', label: 'To decide', test: (r) => r.canDecide },
        { key: 'owed', label: 'Owed', test: (r) => r.isOwed },
        { key: 'accepted', label: 'Accepted', test: (r) => !!r.acceptedOn },
    ];

/**
 * Every report of every shared grant, across beneficiaries.
 */
export default function DonorReports({ reports }: { reports: Report[] }) {
    const [filter, setFilter] = useState('all');
    const chosen = FILTERS.find((item) => item.key === filter) ?? FILTERS[0];
    const shown = reports.filter(chosen.test);

    return (
        <>
            <Head title="Reports" />
            <PageHeader
                title="Reports"
                description="Reports on the grants your beneficiaries share with you."
            />

            {reports.length === 0 ? (
                <Panel>
                    <EmptyState icon={FileText} title="No reports yet">
                        Reports appear here once a beneficiary schedules them on
                        a grant shared with you.
                    </EmptyState>
                </Panel>
            ) : (
                <>
                    <div className="mb-3 flex flex-wrap gap-2">
                        {FILTERS.map((item) => (
                            <Button
                                key={item.key}
                                size="sm"
                                variant={
                                    item.key === filter ? 'default' : 'outline'
                                }
                                aria-pressed={item.key === filter}
                                onClick={() => setFilter(item.key)}
                            >
                                {item.label} ({reports.filter(item.test).length}
                                )
                            </Button>
                        ))}
                    </div>
                    <Panel>
                        {shown.length === 0 ? (
                            <p className="px-4 py-3 text-sm text-muted-foreground">
                                No reports here.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Report</TableHead>
                                        <TableHead>Beneficiary</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Dates</TableHead>
                                        <TableHead>Documents</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shown.map((report) => (
                                        <TableRow key={report.id}>
                                            <TableCell>
                                                <Link
                                                    href={report.url}
                                                    className="font-medium underline-offset-2 hover:underline"
                                                >
                                                    {report.title}
                                                </Link>
                                                <span className="block text-xs text-muted-foreground">
                                                    {report.grant},{' '}
                                                    {report.kind}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                {report.beneficiary}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    tone={statusTone(
                                                        report.status,
                                                    )}
                                                >
                                                    {report.statusLabel}
                                                </StatusBadge>
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {report.overdue ? (
                                                    <span className="inline-flex items-center gap-1 text-destructive">
                                                        <AlertTriangle
                                                            className="size-3.5"
                                                            aria-hidden
                                                        />
                                                        Overdue since{' '}
                                                        {formatDate(
                                                            report.dueOn,
                                                        )}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Due{' '}
                                                        {formatDate(
                                                            report.dueOn,
                                                        )}
                                                    </span>
                                                )}
                                                {report.submittedOn && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Submitted{' '}
                                                        {formatDate(
                                                            report.submittedOn,
                                                        )}
                                                    </span>
                                                )}
                                                {report.acceptedOn && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        Accepted{' '}
                                                        {formatDate(
                                                            report.acceptedOn,
                                                        )}
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-sm">
                                                {report.documents.length ===
                                                0 ? (
                                                    <span className="text-muted-foreground">
                                                        None
                                                    </span>
                                                ) : (
                                                    report.documents.map(
                                                        (document) => (
                                                            <a
                                                                key={
                                                                    document.id
                                                                }
                                                                href={`${report.url}/reports/${report.id}/documents/${document.id}`}
                                                                className="block underline underline-offset-2"
                                                            >
                                                                {document.name}
                                                            </a>
                                                        ),
                                                    )
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </Panel>
                </>
            )}
        </>
    );
}
