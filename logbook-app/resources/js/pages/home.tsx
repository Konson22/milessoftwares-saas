import { Head, Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    NotebookPen,
    Plus,
    RotateCcw,
    Send,
    Sun,
    TriangleAlert,
} from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import type { WritableSheet } from '@/lib/logbook';
import { action, formatDateTime } from '@/lib/logbook';
import { create, index as entries, show as entry } from '@/routes/entries';
import { index as logbooks } from '@/routes/logbooks';
import { index as reviews } from '@/routes/reviews';

type Props = {
    figures: {
        logbooks: number;
        sheets: number;
        entries: number;
        today: number;
        pending: number;
        reviewed: number;
        returned: number;
        outOfRange: number;
    };
    myDrafts: number;
    awaitingMyReview: number | null;
    frequentSheets: (WritableSheet & { count: number })[];
    writableSheets: WritableSheet[];
    activity: {
        id: string;
        action: string;
        subject: string;
        subjectType: string;
        subjectId: string | null;
        user: string | null;
        at: string;
    }[];
};

export default function Home({
    figures,
    myDrafts,
    awaitingMyReview,
    frequentSheets,
    writableSheets,
    activity,
}: Props) {
    const { user } = usePage().props;
    const quick =
        frequentSheets.length > 0 ? frequentSheets : writableSheets.slice(0, 6);

    return (
        <>
            <Head title="Overview" />
            <PageHeader
                title="Overview"
                description={`Every activity recorded. Every record accounted for.${user ? ` Welcome, ${user.name}.` : ''}`}
                actions={
                    writableSheets.length > 0 && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New entry
                            </Link>
                        </Button>
                    )
                }
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5">
                <StatCard
                    label="Logbooks"
                    value={figures.logbooks}
                    hint={`${figures.sheets} active log sheets`}
                    icon={BookOpen}
                    accent="blue"
                    href={logbooks()}
                />
                <StatCard
                    label="Entries"
                    value={figures.entries}
                    hint={`${figures.today} submitted today`}
                    icon={FileText}
                    accent="slate"
                    href={entries({ query: { scope: 'all' } })}
                />
                <StatCard
                    label="Pending review"
                    value={figures.pending}
                    hint={
                        awaitingMyReview !== null
                            ? `${awaitingMyReview} waiting on you`
                            : undefined
                    }
                    icon={Send}
                    accent="amber"
                    href={awaitingMyReview !== null ? reviews() : undefined}
                />
                <StatCard
                    label="Reviewed"
                    value={figures.reviewed}
                    hint={`${figures.returned} returned for correction`}
                    icon={CheckCircle2}
                    accent="emerald"
                />
                <StatCard
                    label="Out of range"
                    value={figures.outOfRange}
                    hint="Entries with a reading outside its normal range, last 7 days"
                    icon={TriangleAlert}
                    accent={figures.outOfRange > 0 ? 'red' : 'slate'}
                />
            </div>

            <div className="mt-6 grid gap-6 lg:grid-cols-5">
                <div className="grid content-start gap-6 lg:col-span-2">
                    {myDrafts > 0 && (
                        <Panel>
                            <PanelBody className="flex items-center justify-between gap-3">
                                <span className="flex items-center gap-2 text-sm">
                                    <RotateCcw className="size-4 text-amber-600" />
                                    {myDrafts} of your entries are drafts or
                                    returned for correction.
                                </span>
                                <Button asChild size="sm" variant="outline">
                                    <Link href={entries()}>Open</Link>
                                </Button>
                            </PanelBody>
                        </Panel>
                    )}
                    <Panel>
                        <PanelHeader
                            title="Quick entry"
                            description={
                                frequentSheets.length > 0
                                    ? 'The sheets you write on most.'
                                    : 'Sheets you can write on.'
                            }
                        />
                        {quick.length === 0 ? (
                            <EmptyState
                                icon={NotebookPen}
                                title="Nothing to write on yet"
                            >
                                A manager publishes log sheets in Logbooks; they
                                appear here when you can write on them.
                            </EmptyState>
                        ) : (
                            <ul className="divide-y">
                                {quick.map((sheet) => (
                                    <li key={sheet.id}>
                                        <Link
                                            href={create({
                                                query: { sheet: sheet.id },
                                            })}
                                            className="flex items-center justify-between gap-3 px-4 py-3 hover:bg-muted"
                                        >
                                            <span>
                                                <span className="block text-sm font-medium">
                                                    {sheet.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {sheet.code} ·{' '}
                                                    {sheet.logbook}
                                                </span>
                                            </span>
                                            <Plus className="size-4 text-muted-foreground" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <Panel className="lg:col-span-3">
                    <PanelHeader title="Recent activity" />
                    {activity.length === 0 ? (
                        <EmptyState icon={Sun} title="No activity yet">
                            Entries, reviews and published templates show here.
                        </EmptyState>
                    ) : (
                        <ul className="divide-y">
                            {activity.map((e) => (
                                <li
                                    key={e.id}
                                    className="flex gap-3 px-4 py-3 text-sm"
                                >
                                    <ClipboardCheck className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                                    <span className="grid gap-0.5">
                                        <span>
                                            <strong>
                                                {e.user ?? 'Tatook'}
                                            </strong>{' '}
                                            {action(e.action)}{' '}
                                            {e.subjectType === 'entry' &&
                                            e.subjectId ? (
                                                <Link
                                                    href={entry(e.subjectId)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {e.subject}
                                                </Link>
                                            ) : (
                                                <span className="font-medium">
                                                    {e.subject}
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatDateTime(e.at)}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}
