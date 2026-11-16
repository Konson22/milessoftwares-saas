import { Form, Head, Link, router, useForm } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { useState } from 'react';
import type { Answers, Definition } from '@/mel/collect/definition';
import { FormRenderer } from '@/mel/collect/renderer';
import { textareaClass } from '@/mel/components/fields';
import { COLLECTED } from '@/mel/lib/collect';
import type { CollectedStatus } from '@/mel/lib/collect';
import { formatDateTime } from '@/mel/lib/mel';
import { show as formPage } from '@/routes/mel/collect/forms';
import {
    accept,
    correct,
    index,
    photo,
    reject,
    reopen,
    requestCorrection,
    start,
} from '@/routes/mel/collect/submissions';

type AnswerRow = {
    name: string;
    label: string;
    type: string;
    group: string | null;
    personal: boolean;
    hidden: boolean;
    value: string | null;
    original: string | null;
    changed: boolean;
    error: string | null;
    attachmentId: string | null;
};

type Props = {
    submission: {
        id: string;
        label: string;
        form: { id: string; name: string; programme: string | null };
        version: { id: string; number: number };
        status: CollectedStatus;
        author: string;
        unit: string | null;
        startedAt: string | null;
        completedAt: string;
        receivedAt: string;
        appVersion: string | null;
        consentGiven: boolean | null;
        place: { lat: string; lng: string; accuracy: string | null } | null;
        photos: { expected: number; received: number };
        reviewer: string | null;
        reviewedAt: string | null;
        revision: number;
    };
    answers: AnswerRow[];
    personalHidden: boolean;
    revisions: {
        number: number;
        changed: string[];
        reason: string;
        author: string;
        at: string;
    }[];
    decisions: {
        action: string;
        comment: string | null;
        notes: Record<string, string> | null;
        by: string;
        at: string;
    }[];
    correction: {
        definition: Definition;
        answers: Answers;
        today: string;
    } | null;
    can: { review: boolean; reopen: boolean; correct: boolean };
    ownWork: boolean;
};

const ACTIONS: Record<string, string> = {
    received: 'Received',
    review_started: 'Review started',
    accepted: 'Accepted',
    rejected: 'Rejected',
    correction_requested: 'Correction requested',
    corrected: 'Corrected',
    reopened: 'Reopened',
};

function ReasonForm({
    action,
    field,
    label,
    submit,
    variant = 'outline',
}: {
    action: ReturnType<typeof reject.form>;
    field: 'reason' | 'comment';
    label: string;
    submit: string;
    variant?: 'outline' | 'destructive' | 'default';
}) {
    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <label className="grid gap-1 text-sm">
                        <span>{label}</span>
                        <textarea
                            name={field}
                            className={textareaClass}
                            maxLength={5000}
                        />
                    </label>
                    {errors[field] && (
                        <p className="text-sm text-destructive">
                            {errors[field]}
                        </p>
                    )}
                    <Button
                        type="submit"
                        variant={variant}
                        disabled={processing}
                    >
                        {submit}
                    </Button>
                </>
            )}
        </Form>
    );
}

export default function CollectedSubmission({
    submission,
    answers,
    personalHidden,
    revisions,
    decisions,
    correction,
    can,
    ownWork,
}: Props) {
    const [step, setStep] = useState<
        'reject' | 'correction' | 'reopen' | 'edit' | null
    >(null);
    const toReview =
        submission.status === 'submitted' ||
        submission.status === 'under_review';
    const decided =
        submission.status === 'accepted' || submission.status === 'rejected';
    const issues = answers.filter((row) => row.error !== null).length;

    return (
        <>
            <Head title={submission.label} />
            <PageHeader
                title={submission.label}
                description={[
                    `Version ${submission.version.number}`,
                    submission.form.programme,
                    submission.unit,
                    `collected by ${submission.author}`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    <StatusBadge tone={COLLECTED[submission.status].tone}>
                        {COLLECTED[submission.status].label}
                    </StatusBadge>
                }
                actions={
                    <div className="flex flex-wrap gap-2">
                        <Button variant="outline" asChild>
                            <Link href={index()}>Back to the list</Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={formPage(submission.form.id)}>
                                The form
                            </Link>
                        </Button>
                    </div>
                }
            />
            {ownWork && (
                <p className="mb-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-100">
                    You collected this submission, so someone else reviews it.
                </p>
            )}
            <div className="grid gap-3 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Answers"
                        description={
                            submission.revision > 0
                                ? `Corrected ${submission.revision} time${submission.revision === 1 ? '' : 's'}: the answers as they stand, with the original under each change.`
                                : 'As collected.'
                        }
                    />
                    <PanelBody className="grid gap-1">
                        {personalHidden && (
                            <p className="mb-2 text-sm text-muted-foreground">
                                Personal answers, photos and places are hidden:
                                you do not have access to personal data.
                            </p>
                        )}
                        {issues > 0 && (
                            <p className="mb-2 text-sm text-destructive">
                                {issues} answer{issues === 1 ? '' : 's'} fail
                                the form&apos;s rules as they stand.
                            </p>
                        )}
                        <dl className="divide-y">
                            {answers.map((row) => (
                                <div
                                    key={row.name}
                                    className="grid gap-1 py-2 sm:grid-cols-3"
                                >
                                    <dt className="text-sm text-muted-foreground">
                                        {row.label}
                                        {row.personal && (
                                            <span className="ml-1 text-xs">
                                                (personal)
                                            </span>
                                        )}
                                    </dt>
                                    <dd className="text-sm sm:col-span-2">
                                        {row.hidden ? (
                                            <span className="text-muted-foreground italic">
                                                Hidden (personal data)
                                            </span>
                                        ) : row.type === 'photo' &&
                                          row.attachmentId ? (
                                            <a
                                                href={photo.url([
                                                    submission.id,
                                                    row.attachmentId,
                                                ])}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <img
                                                    src={photo.url([
                                                        submission.id,
                                                        row.attachmentId,
                                                    ])}
                                                    alt={row.label}
                                                    className="max-h-48 rounded-md border"
                                                />
                                            </a>
                                        ) : (
                                            <span
                                                className={
                                                    row.value === null
                                                        ? 'text-muted-foreground'
                                                        : ''
                                                }
                                            >
                                                {row.value ?? 'No answer'}
                                            </span>
                                        )}
                                        {row.changed && !row.hidden && (
                                            <div className="text-xs text-muted-foreground">
                                                Originally:{' '}
                                                {row.original ?? 'no answer'}
                                            </div>
                                        )}
                                        {row.error && (
                                            <div className="text-xs text-destructive">
                                                Fails the rule: {row.error}
                                            </div>
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </PanelBody>
                </Panel>
                <div className="grid content-start gap-3">
                    <Panel>
                        <PanelHeader title="Review" />
                        <PanelBody className="grid gap-3 text-sm">
                            {submission.status === 'receiving' && (
                                <p className="text-muted-foreground">
                                    {submission.photos.received} of{' '}
                                    {submission.photos.expected} photos have
                                    arrived. It can be reviewed once all have.
                                </p>
                            )}
                            {submission.status === 'correction_requested' && (
                                <p className="text-muted-foreground">
                                    Waiting for the collector&apos;s correction.
                                </p>
                            )}
                            {can.review &&
                                submission.status === 'submitted' && (
                                    <Form
                                        {...start.form(submission.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            className="w-full"
                                        >
                                            Start review
                                        </Button>
                                    </Form>
                                )}
                            {can.review && toReview && (
                                <>
                                    <Form
                                        {...accept.form(submission.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <Button
                                                    type="submit"
                                                    className="w-full"
                                                    disabled={processing}
                                                >
                                                    Accept
                                                </Button>
                                                {errors.status && (
                                                    <p className="mt-1 text-destructive">
                                                        {errors.status}
                                                    </p>
                                                )}
                                            </>
                                        )}
                                    </Form>
                                    <div className="flex gap-2">
                                        <Button
                                            variant="outline"
                                            className="flex-1"
                                            onClick={() =>
                                                setStep('correction')
                                            }
                                        >
                                            Ask for a correction
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="flex-1"
                                            onClick={() => setStep('reject')}
                                        >
                                            Reject
                                        </Button>
                                    </div>
                                    {step === 'reject' && (
                                        <ReasonForm
                                            action={reject.form(submission.id)}
                                            field="reason"
                                            label="Why is it rejected? (kept, but left out of accepted data)"
                                            submit="Reject"
                                            variant="destructive"
                                        />
                                    )}
                                    {step === 'correction' && (
                                        <CorrectionRequest
                                            submissionId={submission.id}
                                            answers={answers}
                                        />
                                    )}
                                </>
                            )}
                            {can.reopen && decided && (
                                <>
                                    <p className="text-muted-foreground">
                                        {submission.status === 'accepted'
                                            ? 'Accepted'
                                            : 'Rejected'}{' '}
                                        by {submission.reviewer} on{' '}
                                        {formatDateTime(submission.reviewedAt)}.
                                        It is locked; reopen it to review or
                                        correct it again.
                                    </p>
                                    <Button
                                        variant="outline"
                                        onClick={() => setStep('reopen')}
                                    >
                                        Reopen
                                    </Button>
                                    {step === 'reopen' && (
                                        <ReasonForm
                                            action={reopen.form(submission.id)}
                                            field="reason"
                                            label="Why is it reopened?"
                                            submit="Reopen"
                                        />
                                    )}
                                </>
                            )}
                            {can.correct && correction && (
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        setStep(step === 'edit' ? null : 'edit')
                                    }
                                >
                                    {step === 'edit'
                                        ? 'Close the correction'
                                        : 'Correct the answers'}
                                </Button>
                            )}
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader title="Details" />
                        <PanelBody>
                            <dl className="grid gap-2 text-sm">
                                <Detail
                                    label="Started on the phone"
                                    value={formatDateTime(submission.startedAt)}
                                />
                                <Detail
                                    label="Completed on the phone"
                                    value={formatDateTime(
                                        submission.completedAt,
                                    )}
                                />
                                <Detail
                                    label="Received"
                                    value={formatDateTime(
                                        submission.receivedAt,
                                    )}
                                />
                                <Detail
                                    label="App version"
                                    value={submission.appVersion ?? '—'}
                                />
                                <Detail
                                    label="Consent"
                                    value={
                                        submission.consentGiven === null
                                            ? 'Not asked'
                                            : submission.consentGiven
                                              ? 'Given'
                                              : 'Not given'
                                    }
                                />
                                {submission.place && (
                                    <Detail
                                        label="Place"
                                        value={`${submission.place.lat}, ${submission.place.lng}${submission.place.accuracy ? ` (± ${Math.round(Number(submission.place.accuracy))} m)` : ''}`}
                                    />
                                )}
                            </dl>
                        </PanelBody>
                    </Panel>
                    <Panel>
                        <PanelHeader title="History" />
                        <PanelBody>
                            <ol className="grid gap-2 text-sm">
                                {decisions.map((decision, i) => (
                                    <li key={i}>
                                        <span className="font-medium">
                                            {ACTIONS[decision.action] ??
                                                decision.action}
                                        </span>{' '}
                                        by {decision.by}
                                        <div className="text-xs text-muted-foreground">
                                            {formatDateTime(decision.at)}
                                        </div>
                                        {decision.comment && (
                                            <p className="whitespace-pre-line">
                                                {decision.comment}
                                            </p>
                                        )}
                                        {decision.notes &&
                                            Object.entries(decision.notes).map(
                                                ([name, note]) => (
                                                    <p
                                                        key={name}
                                                        className="text-xs"
                                                    >
                                                        <span className="font-medium">
                                                            {answers.find(
                                                                (a) =>
                                                                    a.name ===
                                                                    name,
                                                            )?.label ?? name}
                                                            :
                                                        </span>{' '}
                                                        {note}
                                                    </p>
                                                ),
                                            )}
                                    </li>
                                ))}
                            </ol>
                            {revisions.length > 0 && (
                                <>
                                    <h3 className="mt-4 mb-1 text-sm font-semibold">
                                        Corrections
                                    </h3>
                                    <ol className="grid gap-2 text-sm">
                                        {revisions.map((revision) => (
                                            <li key={revision.number}>
                                                Revision {revision.number} by{' '}
                                                {revision.author},{' '}
                                                {formatDateTime(revision.at)}
                                                <div className="text-xs text-muted-foreground">
                                                    Changed:{' '}
                                                    {revision.changed.join(
                                                        ', ',
                                                    )}
                                                    . {revision.reason}
                                                </div>
                                            </li>
                                        ))}
                                    </ol>
                                </>
                            )}
                        </PanelBody>
                    </Panel>
                </div>
            </div>
            {step === 'edit' && correction && (
                <WebCorrection
                    submissionId={submission.id}
                    revision={submission.revision}
                    correction={correction}
                />
            )}
        </>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-3">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="text-right">{value}</dd>
        </div>
    );
}

function CorrectionRequest({
    submissionId,
    answers,
}: {
    submissionId: string;
    answers: AnswerRow[];
}) {
    const form = useForm<{ comment: string; notes: Record<string, string> }>({
        comment: '',
        notes: {},
    });

    return (
        <form
            className="grid gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                form.submit(requestCorrection(submissionId), {
                    preserveScroll: true,
                });
            }}
        >
            <label className="grid gap-1">
                <span>What needs correcting?</span>
                <textarea
                    className={textareaClass}
                    value={form.data.comment}
                    onChange={(event) =>
                        form.setData('comment', event.target.value)
                    }
                />
            </label>
            {form.errors.comment && (
                <p className="text-destructive">{form.errors.comment}</p>
            )}
            <details>
                <summary className="cursor-pointer text-muted-foreground">
                    Notes on single answers
                </summary>
                <div className="mt-2 grid gap-2">
                    {answers.map((row) => (
                        <label key={row.name} className="grid gap-1 text-xs">
                            <span>{row.label}</span>
                            <input
                                className="h-8 rounded-md border bg-transparent px-2"
                                value={form.data.notes[row.name] ?? ''}
                                onChange={(event) =>
                                    form.setData('notes', {
                                        ...form.data.notes,
                                        [row.name]: event.target.value,
                                    })
                                }
                            />
                        </label>
                    ))}
                </div>
            </details>
            <Button type="submit" disabled={form.processing}>
                Send back for correction
            </Button>
        </form>
    );
}

function WebCorrection({
    submissionId,
    revision,
    correction,
}: {
    submissionId: string;
    revision: number;
    correction: NonNullable<Props['correction']>;
}) {
    const [answers, setAnswers] = useState<Answers>(correction.answers);
    const [reason, setReason] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    return (
        <Panel className="mt-3">
            <PanelHeader
                title="Correct the answers"
                description="Your correction becomes a new revision. The original answers are kept, and photos cannot be changed here."
            />
            <PanelBody>
                <form
                    className="grid gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.post(
                            correct.url(submissionId),
                            {
                                answers,
                                reason,
                                base_revision: revision,
                            } as never,
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onError: setErrors,
                                onSuccess: () => setErrors({}),
                            },
                        );
                    }}
                >
                    <FormRenderer
                        definition={correction.definition}
                        answers={answers}
                        onChange={setAnswers}
                        today={correction.today}
                        showErrors
                    />
                    <label className="grid gap-1 text-sm">
                        <span>Why are the answers corrected?</span>
                        <textarea
                            className={textareaClass}
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </label>
                    {Object.entries(errors).map(([key, message]) => (
                        <p key={key} className="text-sm text-destructive">
                            {message}
                        </p>
                    ))}
                    <div>
                        <Button type="submit" disabled={processing}>
                            Save the correction
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}
