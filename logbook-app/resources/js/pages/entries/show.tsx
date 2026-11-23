import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileDown, Paperclip, Pencil, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import { Detail, Errors, Status, TextArea } from '@/components/fields';
import type { Answer, EntryRow } from '@/lib/logbook';
import { action, fileSize, formatDateTime } from '@/lib/logbook';
import { show as attachment } from '@/routes/attachments';
import { destroy, edit, pdf } from '@/routes/entries';
import { decide } from '@/routes/reviews';
import { show as sheetShow } from '@/routes/sheets';
import type { EntryLocation } from '@/lib/tracks';
import { show as trackPage } from '@/routes/tracks';

type Props = {
    entry: EntryRow & {
        version: number;
        latestVersion: number | null;
        revision: number;
        reviewedAt: string | null;
        reviewer: string | null;
        isMine: boolean;
        answers: Answer[];
        location: EntryLocation | null;
        track: string | null;
        shift?: string | null;
    };
    revisions: {
        revision: number;
        version: number;
        submittedAt: string;
        submittedBy: string;
        occurredAt: string;
        answers: Answer[];
        changed: string[];
    }[];
    reviews: {
        id: string;
        revision: number;
        decision: 'reviewed' | 'returned';
        comment: string | null;
        reviewer: string;
        decidedAt: string;
    }[];
    history: { id: string; action: string; user: string | null; at: string }[];
    can: { edit: boolean; discard: boolean; review: boolean };
};

export default function EntryShow({
    entry,
    revisions,
    reviews,
    history,
    can,
}: Props) {
    const [openRevision, setOpenRevision] = useState<number | null>(null);

    return (
        <>
            <Head title={entry.reference ?? 'Draft entry'} />
            <PageHeader
                title={`${entry.reference ?? 'Draft'} · ${entry.sheet.name}`}
                description={
                    <>
                        <Link
                            href={sheetShow(entry.sheet.id)}
                            className="hover:underline"
                        >
                            {entry.logbook} · {entry.sheet.code}
                        </Link>{' '}
                        · template version {entry.version}
                        {entry.latestVersion !== null &&
                            entry.latestVersion !== entry.version &&
                            ` (the sheet is now on version ${entry.latestVersion})`}
                    </>
                }
                badges={<Status value={entry.status} />}
                actions={
                    <>
                        {can.edit && (
                            <Button asChild>
                                <Link href={edit(entry.id)}>
                                    <Pencil />
                                    {entry.status === 'returned'
                                        ? 'Correct and resubmit'
                                        : 'Continue'}
                                </Link>
                            </Button>
                        )}
                        {entry.status !== 'draft' && (
                            <Button asChild variant="outline">
                                <a href={pdf(entry.id).url}>
                                    <FileDown /> PDF
                                </a>
                            </Button>
                        )}
                        {can.discard && (
                            <ActionButton
                                url={destroy(entry.id).url}
                                method="delete"
                                label="Discard draft"
                                variant="destructive"
                                confirm="Discard this draft and its files? This cannot be undone."
                            />
                        )}
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="grid content-start gap-6 lg:col-span-2">
                    {can.review && (
                        <ReviewForm
                            entryId={entry.id}
                            revision={entry.revision}
                        />
                    )}
                    <Panel>
                        <PanelHeader
                            title="Entry"
                            description={
                                entry.revision > 1
                                    ? `Revision ${entry.revision}: corrected after being returned.`
                                    : undefined
                            }
                        />
                        <PanelBody>
                            <dl className="mb-4 grid gap-4 border-b pb-4 sm:grid-cols-3">
                                <Detail label="Occurred">
                                    {formatDateTime(entry.occurredAt)}
                                </Detail>
                                <Detail label="Written by">
                                    {entry.creator}
                                </Detail>
                                <Detail label="Submitted">
                                    {entry.submittedAt
                                        ? formatDateTime(entry.submittedAt)
                                        : 'Not yet'}
                                </Detail>
                                <Detail label="Review">
                                    {!entry.reviewRequired &&
                                    entry.status === 'finalized'
                                        ? 'Not required for this sheet'
                                        : entry.reviewer
                                          ? `Reviewed by ${entry.reviewer}, ${formatDateTime(entry.reviewedAt)}`
                                          : entry.status === 'draft'
                                            ? '—'
                                            : 'Not reviewed yet'}
                                </Detail>
                                {entry.shift && (
                                    <Detail label="Shift">{entry.shift}</Detail>
                                )}
                                {(entry.location || entry.track) && (
                                    <Detail label="Location">
                                        {entry.location ? (
                                            <>
                                                {entry.location.lat.toFixed(5)},{' '}
                                                {entry.location.lng.toFixed(5)}
                                                {entry.location.accuracy_m !==
                                                    null &&
                                                    ` (±${Math.round(entry.location.accuracy_m)} m)`}
                                                <span className="block text-xs text-muted-foreground">
                                                    Taken{' '}
                                                    {formatDateTime(
                                                        entry.location
                                                            .captured_at,
                                                    )}{' '}
                                                    by the{' '}
                                                    {entry.location.source ===
                                                    'browser'
                                                        ? 'browser'
                                                        : 'phone'}
                                                </span>
                                            </>
                                        ) : (
                                            'No location taken'
                                        )}
                                        {entry.track && (
                                            <Link
                                                href={trackPage(entry.track)}
                                                className="block text-xs hover:underline"
                                            >
                                                See the route it was written on
                                            </Link>
                                        )}
                                    </Detail>
                                )}
                            </dl>
                            <Answers answers={entry.answers} />
                        </PanelBody>
                    </Panel>

                    {revisions.length > 1 && (
                        <Panel>
                            <PanelHeader
                                title="Submitted revisions"
                                description="Every submission is kept exactly as it was sent."
                            />
                            <ul className="divide-y">
                                {revisions.map((r) => (
                                    <li key={r.revision} className="px-4 py-3">
                                        <button
                                            type="button"
                                            className="flex w-full items-center justify-between gap-2 text-left text-sm"
                                            aria-expanded={
                                                openRevision === r.revision
                                            }
                                            onClick={() =>
                                                setOpenRevision(
                                                    openRevision === r.revision
                                                        ? null
                                                        : r.revision,
                                                )
                                            }
                                        >
                                            <span>
                                                <strong>
                                                    Revision {r.revision}
                                                </strong>{' '}
                                                by {r.submittedBy},{' '}
                                                {formatDateTime(r.submittedAt)}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {r.changed.length > 0
                                                    ? `${r.changed.length} changed`
                                                    : 'first submission'}
                                            </span>
                                        </button>
                                        {openRevision === r.revision && (
                                            <div className="mt-3">
                                                <Answers
                                                    answers={r.answers}
                                                    changed={r.changed}
                                                />
                                            </div>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>

                <div className="grid content-start gap-6">
                    <Panel>
                        <PanelHeader title="Reviews" />
                        <PanelBody>
                            {reviews.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {entry.reviewRequired ||
                                    entry.status === 'draft'
                                        ? 'No review decision yet.'
                                        : 'This sheet has no review step.'}
                                </p>
                            ) : (
                                <ul className="grid gap-3 text-sm">
                                    {reviews.map((r) => (
                                        <li key={r.id} className="grid gap-1">
                                            <span className="flex items-center gap-2">
                                                <Status value={r.decision} />
                                                <span className="text-xs text-muted-foreground">
                                                    revision {r.revision}
                                                </span>
                                            </span>
                                            <span>
                                                {r.reviewer},{' '}
                                                {formatDateTime(r.decidedAt)}
                                            </span>
                                            {r.comment && (
                                                <span className="whitespace-pre-line text-muted-foreground">
                                                    {r.comment}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader title="History" />
                        <PanelBody>
                            <ol className="grid gap-2 text-sm">
                                {history.map((h) => (
                                    <li key={h.id}>
                                        <strong>
                                            {h.user ?? 'Miles Logbook'}
                                        </strong>{' '}
                                        {action(h.action)}
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(h.at)}
                                        </span>
                                    </li>
                                ))}
                            </ol>
                        </PanelBody>
                    </Panel>
                </div>
            </div>
        </>
    );
}

function Answers({
    answers,
    changed = [],
}: {
    answers: Answer[];
    changed?: string[];
}) {
    const flagged = answers.filter((a) => a.outOfRange !== null);

    return (
        <>
            {flagged.length > 0 && (
                <div
                    role="status"
                    className="mb-4 flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900"
                >
                    <TriangleAlert
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden
                    />
                    <span>
                        {flagged.length === 1
                            ? '1 reading is outside its normal range.'
                            : `${flagged.length} readings are outside their normal range.`}{' '}
                        They are recorded as entered.
                    </span>
                </div>
            )}
            <dl className="grid gap-4 sm:grid-cols-2">
                {answers.map((a) => (
                    <div
                        key={a.name}
                        className={`grid gap-0.5 ${a.type === 'textarea' || a.type === 'file' ? 'sm:col-span-2' : ''}`}
                    >
                        <dt className="text-xs text-muted-foreground">
                            {a.label}
                            {changed.includes(a.name) && (
                                <span className="ml-2 rounded bg-amber-100 px-1 text-amber-900">
                                    changed
                                </span>
                            )}
                        </dt>
                        <dd className="text-sm whitespace-pre-line">
                            {a.type === 'file' ? (
                                a.files.length === 0 ? (
                                    '—'
                                ) : (
                                    <ul className="grid gap-1">
                                        {a.files.map((f) => (
                                            <li
                                                key={f.id}
                                                className="flex items-center gap-2"
                                            >
                                                <Paperclip className="size-4 text-muted-foreground" />
                                                <a
                                                    href={attachment(f.id).url}
                                                    className="hover:underline"
                                                >
                                                    {f.name}
                                                </a>
                                                <span className="text-xs text-muted-foreground">
                                                    {fileSize(f.size)}
                                                </span>
                                                {f.mime?.startsWith(
                                                    'image/',
                                                ) && (
                                                    <a
                                                        href={
                                                            attachment(f.id, {
                                                                query: {
                                                                    inline: 1,
                                                                },
                                                            }).url
                                                        }
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="text-xs underline"
                                                    >
                                                        View
                                                    </a>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                )
                            ) : a.text === null ? (
                                '—'
                            ) : (
                                <span className="inline-flex flex-wrap items-center gap-2">
                                    <span
                                        className={
                                            a.outOfRange
                                                ? 'font-semibold text-amber-900'
                                                : undefined
                                        }
                                    >
                                        {a.text}
                                        {a.unit ? ` ${a.unit}` : ''}
                                    </span>
                                    {a.outOfRange && (
                                        <span className="inline-flex items-center gap-1 rounded-sm border border-amber-300 bg-amber-50 px-1.5 text-xs font-medium text-amber-900">
                                            {a.outOfRange.direction === 'low'
                                                ? 'Below range'
                                                : 'Above range'}
                                            <span className="font-normal">
                                                (normal {a.outOfRange.range})
                                            </span>
                                        </span>
                                    )}
                                </span>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </>
    );
}

function ReviewForm({
    entryId,
    revision,
}: {
    entryId: string;
    revision: number;
}) {
    const [comment, setComment] = useState('');
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    const send = (decision: 'reviewed' | 'returned') => {
        setBusy(true);
        router.post(
            decide(entryId).url,
            { decision, comment, revision },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    };

    return (
        <Panel className="border-primary">
            <PanelHeader
                title="Review"
                description={`Revision ${revision} waits on a reviewer. Check the answers and evidence below.`}
            />
            <PanelBody className="grid gap-3">
                <TextArea
                    label="Comment (needed to return)"
                    value={comment}
                    onChange={setComment}
                    error={errors.comment}
                    rows={3}
                />
                <Errors
                    errors={
                        errors.decision ? { decision: errors.decision } : {}
                    }
                />
                <div className="flex flex-wrap gap-2">
                    <Button disabled={busy} onClick={() => send('reviewed')}>
                        Mark reviewed
                    </Button>
                    <Button
                        variant="outline"
                        disabled={busy || comment.trim() === ''}
                        onClick={() => send('returned')}
                    >
                        Return for correction
                    </Button>
                </div>
            </PanelBody>
        </Panel>
    );
}
