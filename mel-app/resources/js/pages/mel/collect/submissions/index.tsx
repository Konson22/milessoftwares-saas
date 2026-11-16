import { Head, Link, router } from '@inertiajs/react';
import { Inbox } from 'lucide-react';
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
import { selectClass } from '@/mel/components/fields';
import { COLLECTED } from '@/mel/lib/collect';
import type { CollectedStatus } from '@/mel/lib/collect';
import type { Option } from '@/mel/lib/mel';
import { formatDateTime } from '@/mel/lib/mel';
import { index, show } from '@/routes/mel/collect/submissions';

type View =
    | 'to_review'
    | 'correction_requested'
    | 'accepted'
    | 'rejected'
    | 'receiving'
    | 'all';

type Props = {
    filters: { view: View; form: string | null };
    counts: Record<CollectedStatus, number>;
    submissions: {
        data: {
            id: string;
            form: string;
            version: number;
            author: string;
            unit: string | null;
            status: CollectedStatus;
            completedAt: string;
            receivedAt: string;
            revised: boolean;
            missingPhotos: number;
        }[];
        links: { prev: string | null; next: string | null };
        total: number;
    };
    forms: Option[];
};

const VIEWS: {
    value: View;
    label: string;
    count: (c: Props['counts']) => number;
}[] = [
    {
        value: 'to_review',
        label: 'To review',
        count: (c) => c.submitted + c.under_review,
    },
    {
        value: 'correction_requested',
        label: 'Correction requested',
        count: (c) => c.correction_requested,
    },
    { value: 'accepted', label: 'Accepted', count: (c) => c.accepted },
    { value: 'rejected', label: 'Rejected', count: (c) => c.rejected },
    { value: 'receiving', label: 'Photos arriving', count: (c) => c.receiving },
    {
        value: 'all',
        label: 'All',
        count: (c) =>
            c.submitted +
            c.under_review +
            c.correction_requested +
            c.accepted +
            c.rejected,
    },
];

export default function CollectedSubmissions({
    filters,
    counts,
    submissions,
    forms,
}: Props) {
    const go = (next: Partial<Props['filters']>) =>
        router.get(
            index.url({ query: { ...filters, ...next } }),
            {},
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Collected data" />
            <PageHeader
                title="Collected data"
                description="Submissions from the field, each shown with the form version it was filled in on. Accepting a submission makes it count in analysis and exports of accepted data; it never verifies an indicator result."
            />
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="flex flex-wrap gap-1" role="tablist">
                    {VIEWS.map((view) => (
                        <Button
                            key={view.value}
                            size="sm"
                            role="tab"
                            aria-selected={filters.view === view.value}
                            variant={
                                filters.view === view.value
                                    ? 'default'
                                    : 'outline'
                            }
                            onClick={() => go({ view: view.value })}
                        >
                            {view.label}
                            <span className="ml-1 tabular-nums opacity-70">
                                {view.count(counts)}
                            </span>
                        </Button>
                    ))}
                </div>
                <label className="ml-auto flex items-center gap-2 text-sm">
                    <span className="text-muted-foreground">Form</span>
                    <select
                        className={`${selectClass} w-56`}
                        value={filters.form ?? ''}
                        onChange={(event) =>
                            go({ form: event.target.value || null })
                        }
                    >
                        <option value="">All forms</option>
                        {forms.map((form) => (
                            <option key={form.value} value={form.value}>
                                {form.label}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <Panel>
                {submissions.data.length === 0 ? (
                    <EmptyState icon={Inbox} title="Nothing here">
                        {filters.view === 'to_review'
                            ? 'No submission waits for review.'
                            : 'No submission matches.'}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Submission</TableHead>
                                <TableHead>Collected by</TableHead>
                                <TableHead>Completed</TableHead>
                                <TableHead>Received</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {submissions.data.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        <Link
                                            href={show(row.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {row.form} · {row.id.slice(0, 8)}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            Version {row.version}
                                            {row.unit && ` · ${row.unit}`}
                                            {row.revised && ' · corrected'}
                                        </div>
                                    </TableCell>
                                    <TableCell>{row.author}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDateTime(row.completedAt)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDateTime(row.receivedAt)}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={COLLECTED[row.status].tone}
                                        >
                                            {COLLECTED[row.status].label}
                                        </StatusBadge>
                                        {row.missingPhotos > 0 && (
                                            <div className="text-xs text-muted-foreground">
                                                {row.missingPhotos} photo
                                                {row.missingPhotos === 1
                                                    ? ''
                                                    : 's'}{' '}
                                                to come
                                            </div>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
            {(submissions.links.prev || submissions.links.next) && (
                <div className="mt-3 flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        {submissions.total} in all
                    </span>
                    <div className="flex gap-2">
                        {submissions.links.prev && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={submissions.links.prev}>
                                    Previous
                                </Link>
                            </Button>
                        )}
                        {submissions.links.next && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={submissions.links.next}>Next</Link>
                            </Button>
                        )}
                    </div>
                </div>
            )}
        </>
    );
}
