import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft, Paperclip } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { cn } from '@miles/ui/lib/utils';
import { Field } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { formatDate, formatDateTime } from '@/lib/status';
import type { ProjectHeader } from '@/types';

/** Partner reports and project requests (ADR-0015), on both sides of a project. */

export type WorkFrame =
    | { side: 'owner'; project: ProjectHeader }
    | {
          side: 'participant';
          participation: {
              id: string;
              projectCode: string;
              projectName: string;
              owner: string;
              relationship: string;
              url: string;
          };
      };

export type HistoryEntry = {
    id: string;
    action: string;
    comment: string | null;
    by: string;
    organization: string;
    at: string;
    version?: number | null;
};

export type AttachmentRow = {
    id: string;
    name: string;
    size: number;
    by: string;
    url: string;
};

const ACTIONS: Record<string, string> = {
    submitted: 'Submitted',
    resubmitted: 'Resubmitted',
    withdrawn: 'Withdrew',
    review_started: 'Started the review',
    approved: 'Approved',
    changes_requested: 'Asked for changes',
    rejected: 'Rejected',
    published: 'Published',
    commented: 'Commented',
    cancelled: 'Cancelled',
    escalated: 'Escalated',
    budget_revision_started: 'Opened a budget revision',
    timeline_applied: 'Applied the new end date',
    disbursement_linked: 'Linked a scheduled payment',
};

/**
 * The owner's project tabs, or the shared project it is seen through.
 */
export function WorkPage({
    frame,
    title,
    actions,
    children,
}: {
    frame: WorkFrame;
    title: string;
    actions?: ReactNode;
    children: ReactNode;
}) {
    if (frame.side === 'owner') {
        return (
            <ProjectFrame
                project={frame.project}
                title={title}
                actions={actions}
            >
                {children}
            </ProjectFrame>
        );
    }

    const { participation } = frame;

    return (
        <>
            <Head title={`${title} · ${participation.projectCode}`} />
            <Link
                href={participation.url}
                className="mb-2 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft className="size-4" />
                {participation.projectCode} · {participation.projectName}
            </Link>
            <PageHeader title={title} actions={actions} />
            <p className="-mt-2 mb-4 text-sm text-muted-foreground">
                {participation.owner}&rsquo;s project, where you are{' '}
                {participation.relationship.toLowerCase()}.
            </p>
            <div className="grid gap-4">{children}</div>
        </>
    );
}

export function TextArea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            rows={3}
            className={cn(
                'w-full border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        />
    );
}

/**
 * Who did what, with their organization, oldest first.
 */
export function History({ entries }: { entries: HistoryEntry[] }) {
    return (
        <Panel>
            <PanelHeader title="History" />
            <PanelBody>
                {entries.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing yet.
                    </p>
                ) : (
                    <ol className="grid gap-3">
                        {entries.map((entry) => (
                            <li key={entry.id} className="grid gap-0.5 text-sm">
                                <span>
                                    <span className="font-medium">
                                        {ACTIONS[entry.action] ?? entry.action}
                                    </span>{' '}
                                    by {entry.by} ({entry.organization})
                                    {entry.version
                                        ? `, version ${entry.version}`
                                        : ''}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(entry.at)}
                                </span>
                                {entry.comment && (
                                    <p className="border-l-2 pl-3 whitespace-pre-line text-muted-foreground">
                                        {entry.comment}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ol>
                )}
            </PanelBody>
        </Panel>
    );
}

export function Attachments({
    files,
    uploadUrl,
}: {
    files: AttachmentRow[];
    uploadUrl: string | null;
}) {
    return (
        <Panel>
            <PanelHeader title="Files" />
            <PanelBody className="grid gap-3">
                {files.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No files.</p>
                ) : (
                    <ul className="grid gap-1 text-sm">
                        {files.map((file) => (
                            <li
                                key={file.id}
                                className="flex items-center gap-2"
                            >
                                <Paperclip className="size-4 text-muted-foreground" />
                                <a href={file.url} className="underline">
                                    {file.name}
                                </a>
                                <span className="text-xs text-muted-foreground">
                                    {Math.ceil(file.size / 1024)} KB · {file.by}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {uploadUrl && (
                    <Form
                        action={uploadUrl}
                        method="post"
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="file"
                                    label="Add a file"
                                    error={errors.file}
                                >
                                    <input
                                        id="file"
                                        name="file"
                                        type="file"
                                        required
                                        className="text-sm"
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    size="sm"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Upload
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}

/**
 * One workflow step: a button, with a comment when it needs one.
 */
export function StepForm({
    url,
    action,
    label,
    comment,
    variant = 'default',
}: {
    url: string;
    action: string;
    label: string;
    /** 'required', 'optional' or none. */
    comment?: 'required' | 'optional';
    variant?: ComponentProps<typeof Button>['variant'];
}) {
    return (
        <Form
            action={url}
            method="post"
            resetOnSuccess
            options={{ preserveScroll: true }}
            className="grid gap-2"
        >
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="action" value={action} />
                    {comment && (
                        <Field
                            id={`comment-${action}`}
                            label={
                                comment === 'required'
                                    ? `${label}: why`
                                    : `${label}: note (optional)`
                            }
                            error={errors.comment}
                        >
                            <TextArea
                                id={`comment-${action}`}
                                name="comment"
                                maxLength={5000}
                                required={comment === 'required'}
                            />
                        </Field>
                    )}
                    {Object.entries(errors)
                        .filter(([key]) => key !== 'comment')
                        .map(([key, message]) => (
                            <p key={key} className="text-sm text-destructive">
                                {message}
                            </p>
                        ))}
                    <span>
                        <Button
                            type="submit"
                            size="sm"
                            variant={variant}
                            disabled={processing}
                        >
                            {label}
                        </Button>
                    </span>
                </>
            )}
        </Form>
    );
}

export function Facts({ items }: { items: [string, ReactNode][] }) {
    return (
        <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            {items.map(([label, value]) => (
                <div key={label} className="grid gap-0.5">
                    <dt className="text-xs text-muted-foreground">{label}</dt>
                    <dd>{value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** A report period, or a dash. */
export function period(start: string | null, end: string | null): string {
    if (!start || !end) {
        return '—';
    }

    return `${formatDate(start)} – ${formatDate(end)}`;
}
