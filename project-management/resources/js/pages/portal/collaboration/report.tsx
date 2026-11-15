import { Form, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select } from '@/components/field';
import type {
    AttachmentRow,
    HistoryEntry,
    WorkFrame,
} from '@/components/partner-work';
import {
    Attachments,
    Facts,
    History,
    period,
    StepForm,
    TextArea,
    WorkPage,
} from '@/components/partner-work';
import { Status } from '@/components/status';
import { formatMoney, parseMoney, toMajor } from '@/lib/money';
import { formatDate, formatDateTime } from '@/lib/status';
import type { Option } from '@/types';

type Line = { label: string; amountMinor: number | null; source?: string };

type MelResult = {
    indicator: string;
    unit: string;
    period: string;
    unitLabel: string | null;
    value: string | null;
    numerator: string | null;
    denominator: string | null;
    published: boolean;
};

type Content = {
    implementation: Record<string, string | null>;
    financial: { lines: Line[]; note: string | null };
    narrative: Record<string, string | null>;
    financialHidden?: boolean;
    system?: {
        capturedAt: string;
        financial: {
            currency: string;
            approvedMinor: number;
            spentMinor: number;
            varianceMinor: number;
            /** Snapshots from 2026-11-16 on. */
            committedMinor?: number;
            provenance?: { paidStatus: string; asOf: string };
            categories: { category: string; amountMinor: number }[];
        } | null;
        financialHidden?: boolean;
        mel: MelResult[];
    };
};

type Props = {
    frame: WorkFrame;
    report: {
        id: string;
        reference: string;
        title: string;
        category: string;
        periodStart: string | null;
        periodEnd: string | null;
        dueOn: string | null;
        status: string;
        overdue: boolean;
        submitter: string;
        reviewer: string;
        direction: string;
        obligation: string | null;
        publishHint: boolean;
        submittedAt: string | null;
        publishedAt: string | null;
    };
    version: {
        number: number;
        frozen: boolean;
        submittedAt: string | null;
        content: Content;
        attachments: AttachmentRow[];
    } | null;
    versions: { number: number; submittedAt: string | null }[];
    reviews: HistoryEntry[];
    sources: {
        id: string;
        reference: string;
        title: string;
        from: string;
        periodStart: string | null;
        periodEnd: string | null;
        status: string;
        content: Content | null;
        removeUrl: string | null;
    }[];
    sourceCandidates: Option[];
    can: {
        edit: boolean;
        submit: boolean;
        withdraw: boolean;
        start: boolean;
        decide: boolean;
        publish: boolean;
        comment: boolean;
        ownVersion: boolean;
    };
    currency: string;
    labels: {
        implementation: Record<string, string>;
        narrative: Record<string, string>;
        sources: Record<string, string>;
    };
    urls: {
        index: string;
        show: string;
        update: string;
        action: string;
        sources: string;
        attachments: string;
    };
};

export default function Report(props: Props) {
    const { frame, report, version, versions, reviews, can, urls } = props;
    const editing = can.edit && version !== null && !version.frozen;

    return (
        <WorkPage
            frame={frame}
            title={`${report.reference} · ${report.title}`}
            actions={<Status status={report.status} />}
        >
            <Panel>
                <PanelBody className="grid gap-3">
                    <Facts
                        items={[
                            ['Kind', report.category],
                            [
                                'Period',
                                period(report.periodStart, report.periodEnd),
                            ],
                            ['From', report.submitter],
                            ['To', report.reviewer],
                            [
                                'Due',
                                <>
                                    {report.dueOn
                                        ? formatDate(report.dueOn)
                                        : '—'}
                                    {report.overdue && (
                                        <StatusBadge
                                            tone="danger"
                                            className="ml-2"
                                        >
                                            Overdue
                                        </StatusBadge>
                                    )}
                                </>,
                            ],
                            ['Obligation', report.obligation ?? 'None'],
                        ]}
                    />
                    <Link href={urls.index} className="text-sm underline">
                        All reports
                    </Link>
                </PanelBody>
            </Panel>

            {versions.length > 1 && (
                <div className="flex flex-wrap gap-2 text-sm">
                    <span className="text-muted-foreground">Versions:</span>
                    {versions.map((item) => (
                        <button
                            key={item.number}
                            type="button"
                            className={
                                item.number === version?.number
                                    ? 'font-medium underline'
                                    : 'underline'
                            }
                            onClick={() =>
                                router.get(
                                    urls.show,
                                    { version: item.number },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            {item.number}
                            {item.submittedAt
                                ? ` (submitted ${formatDateTime(item.submittedAt)})`
                                : ' (draft)'}
                        </button>
                    ))}
                </div>
            )}

            {version === null ? null : editing ? (
                <Editor {...props} content={version.content} />
            ) : (
                <ContentView
                    content={version.content}
                    labels={props.labels}
                    currency={props.currency}
                />
            )}

            {version && (
                <Attachments
                    files={version.attachments}
                    uploadUrl={editing ? urls.attachments : null}
                />
            )}

            <Sources {...props} />

            <Actions {...props} />

            <History entries={reviews} />
        </WorkPage>
    );
}

function Editor({
    content,
    urls,
    labels,
    currency,
}: Props & { content: Content }) {
    const form = useForm<{ content: Content }>({ content });
    const set = (
        section: 'implementation' | 'narrative',
        key: string,
        value: string,
    ) =>
        form.setData('content', {
            ...form.data.content,
            [section]: { ...form.data.content[section], [key]: value },
        });
    const lines = form.data.content.financial.lines;
    const setLines = (next: Line[]) =>
        form.setData('content', {
            ...form.data.content,
            financial: { ...form.data.content.financial, lines: next },
        });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put(urls.update, { preserveScroll: true });
            }}
            className="grid gap-4"
        >
            {(['implementation', 'narrative'] as const).map((section) => (
                <Panel key={section}>
                    <PanelHeader
                        title={
                            section === 'implementation'
                                ? 'Implementation'
                                : 'Narrative'
                        }
                    />
                    <PanelBody className="grid gap-3 sm:grid-cols-2">
                        {Object.entries(labels[section]).map(([key, label]) => (
                            <Field
                                key={key}
                                id={`${section}-${key}`}
                                label={label}
                                error={
                                    (form.errors as Record<string, string>)[
                                        `${section}.${key}`
                                    ]
                                }
                            >
                                <TextArea
                                    id={`${section}-${key}`}
                                    value={
                                        form.data.content[section][key] ?? ''
                                    }
                                    maxLength={10000}
                                    onChange={(event) =>
                                        set(section, key, event.target.value)
                                    }
                                />
                            </Field>
                        ))}
                    </PanelBody>
                </Panel>
            ))}

            <Panel>
                <PanelHeader
                    title="Financial"
                    description={labels.sources.reported}
                />
                <PanelBody className="grid gap-2">
                    {lines.map((line, index) => (
                        <div
                            key={index}
                            className="flex flex-wrap items-end gap-2"
                        >
                            <Field
                                id={`line-${index}`}
                                label="Description"
                                className="flex-1"
                            >
                                <Input
                                    id={`line-${index}`}
                                    value={line.label}
                                    maxLength={200}
                                    required
                                    onChange={(event) =>
                                        setLines(
                                            lines.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          label: event.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                            <Field
                                id={`amount-${index}`}
                                label={`Amount (${currency})`}
                            >
                                <Input
                                    id={`amount-${index}`}
                                    inputMode="decimal"
                                    defaultValue={
                                        line.amountMinor === null
                                            ? ''
                                            : toMajor(
                                                  line.amountMinor,
                                                  currency,
                                              )
                                    }
                                    onChange={(event) =>
                                        setLines(
                                            lines.map((item, i) =>
                                                i === index
                                                    ? {
                                                          ...item,
                                                          amountMinor:
                                                              parseMoney(
                                                                  event.target
                                                                      .value,
                                                                  currency,
                                                              ),
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                            </Field>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Remove line"
                                onClick={() =>
                                    setLines(
                                        lines.filter((_, i) => i !== index),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                    ))}
                    <span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                setLines([
                                    ...lines,
                                    { label: '', amountMinor: null },
                                ])
                            }
                        >
                            <Plus className="size-4" /> Add a line
                        </Button>
                    </span>
                    <Field id="financial-note" label="Note">
                        <TextArea
                            id="financial-note"
                            value={form.data.content.financial.note ?? ''}
                            onChange={(event) =>
                                form.setData('content', {
                                    ...form.data.content,
                                    financial: {
                                        ...form.data.content.financial,
                                        note: event.target.value,
                                    },
                                })
                            }
                        />
                    </Field>
                    <p className="text-xs text-muted-foreground">
                        When you submit, the project&rsquo;s recorded budget
                        figures (if you may see them) and verified M&amp;E
                        results for the period are added as they stand.
                    </p>
                </PanelBody>
            </Panel>

            <span>
                <Button type="submit" disabled={form.processing}>
                    Save draft
                </Button>
            </span>
        </form>
    );
}

function ContentView({
    content,
    labels,
    currency,
}: {
    content: Content;
    labels: Props['labels'];
    currency: string;
}) {
    const system = content.system;

    return (
        <>
            {(['implementation', 'narrative'] as const).map((section) => (
                <Panel key={section}>
                    <PanelHeader
                        title={
                            section === 'implementation'
                                ? 'Implementation'
                                : 'Narrative'
                        }
                    />
                    <PanelBody>
                        <dl className="grid gap-3 text-sm sm:grid-cols-2">
                            {Object.entries(labels[section]).map(
                                ([key, label]) => (
                                    <div key={key} className="grid gap-0.5">
                                        <dt className="text-xs text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="whitespace-pre-line">
                                            {content[section]?.[key] || '—'}
                                        </dd>
                                    </div>
                                ),
                            )}
                        </dl>
                    </PanelBody>
                </Panel>
            ))}

            <Panel>
                <PanelHeader title="Financial" />
                <PanelBody className="grid gap-4 text-sm">
                    <div className="grid gap-1">
                        <p className="text-xs text-muted-foreground">
                            {labels.sources.reported}
                        </p>
                        {content.financialHidden ? (
                            <p>
                                Not shown: you may not see the financial section
                                of this report.
                            </p>
                        ) : content.financial.lines.length === 0 ? (
                            <p>No figures reported.</p>
                        ) : (
                            <ul className="grid gap-1">
                                {content.financial.lines.map((line, index) => (
                                    <li
                                        key={index}
                                        className="flex justify-between gap-4"
                                    >
                                        <span>{line.label}</span>
                                        <span className="tabular-nums">
                                            {line.amountMinor === null
                                                ? '—'
                                                : formatMoney(
                                                      line.amountMinor,
                                                      currency,
                                                  )}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {content.financial.note && (
                            <p className="whitespace-pre-line">
                                {content.financial.note}
                            </p>
                        )}
                    </div>
                    {system && (
                        <div className="grid gap-1">
                            <p className="text-xs text-muted-foreground">
                                {labels.sources.recorded}, as at{' '}
                                {formatDateTime(system.capturedAt)}
                            </p>
                            {system.financial ? (
                                <ul className="grid gap-1">
                                    <li className="flex justify-between">
                                        <span>Approved budget</span>
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                system.financial.approvedMinor,
                                                system.financial.currency,
                                            )}
                                        </span>
                                    </li>
                                    <li className="flex justify-between">
                                        <span>Paid to date</span>
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                system.financial.spentMinor,
                                                system.financial.currency,
                                            )}
                                        </span>
                                    </li>
                                    <li className="flex justify-between">
                                        <span>
                                            Not yet paid (approved − paid)
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMoney(
                                                system.financial.varianceMinor,
                                                system.financial.currency,
                                            )}
                                        </span>
                                    </li>
                                    {system.financial.committedMinor !==
                                        undefined && (
                                        <li className="flex justify-between">
                                            <span>Of which committed</span>
                                            <span className="tabular-nums">
                                                {formatMoney(
                                                    system.financial
                                                        .committedMinor,
                                                    system.financial.currency,
                                                )}
                                            </span>
                                        </li>
                                    )}
                                    {system.financial.provenance && (
                                        <li className="text-xs text-muted-foreground">
                                            {system.financial.provenance
                                                .paidStatus === 'posted'
                                                ? 'Payments posted in Finance.'
                                                : 'Payments as recorded in Projects, not verified by Finance.'}
                                        </li>
                                    )}
                                </ul>
                            ) : (
                                <p>
                                    {system.financialHidden
                                        ? 'Not shown: you may not see this project’s budget.'
                                        : 'No recorded figures were included.'}
                                </p>
                            )}
                        </div>
                    )}
                </PanelBody>
            </Panel>

            {system && system.mel.length > 0 && (
                <Panel>
                    <PanelHeader
                        title="Verified M&E results"
                        description="As verified, each on its own; not added up."
                    />
                    <PanelBody>
                        <ul className="grid gap-1 text-sm">
                            {system.mel.map((result, index) => (
                                <li
                                    key={index}
                                    className="flex flex-wrap justify-between gap-2"
                                >
                                    <span>
                                        {result.indicator} · {result.unit} ·{' '}
                                        {result.period}
                                    </span>
                                    <span className="tabular-nums">
                                        {result.value ??
                                            (result.numerator !== null
                                                ? `${result.numerator} / ${result.denominator}`
                                                : '—')}{' '}
                                        {result.unitLabel ?? ''}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}

function Sources({
    sources,
    sourceCandidates,
    can,
    urls,
    labels,
    currency,
}: Props) {
    if (sources.length === 0 && !(can.edit && sourceCandidates.length > 0)) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader
                title="Built from"
                description="Approved reports sent to you, each listed as it was approved. Nothing is added up."
            />
            <PanelBody className="grid gap-4">
                {sources.map((source) => (
                    <details key={source.id} className="border p-3">
                        <summary className="flex cursor-pointer flex-wrap items-center justify-between gap-2 text-sm">
                            <span className="font-medium">
                                {source.reference} · {source.title}
                            </span>
                            <span className="text-muted-foreground">
                                {source.from} ·{' '}
                                {period(source.periodStart, source.periodEnd)}
                            </span>
                        </summary>
                        <div className="mt-3 grid gap-3">
                            {source.content && (
                                <ContentView
                                    content={source.content}
                                    labels={labels}
                                    currency={currency}
                                />
                            )}
                            {source.removeUrl && (
                                <Form
                                    action={source.removeUrl}
                                    method="delete"
                                    options={{ preserveScroll: true }}
                                >
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="outline"
                                    >
                                        Remove
                                    </Button>
                                </Form>
                            )}
                        </div>
                    </details>
                ))}
                {can.edit && sourceCandidates.length > 0 && (
                    <Form
                        action={urls.sources}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="source"
                                    label="Add a source report"
                                    error={errors.source}
                                    className="flex-1"
                                >
                                    <Select
                                        id="source"
                                        name="source"
                                        options={sourceCandidates}
                                        required
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Add
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </PanelBody>
        </Panel>
    );
}

function Actions({ report, can, urls }: Props) {
    const any =
        can.submit ||
        can.withdraw ||
        can.start ||
        can.decide ||
        can.publish ||
        can.comment;
    if (!any) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader title="What next" />
            <PanelBody className="grid gap-4">
                {can.ownVersion &&
                    (can.start || can.decide) === false &&
                    report.direction === 'incoming' && (
                        <p className="text-sm text-muted-foreground">
                            You submitted this version: someone else decides on
                            it.
                        </p>
                    )}
                {can.submit && (
                    <StepForm
                        url={urls.action}
                        action="submit"
                        label={`Submit to ${report.reviewer}`}
                    />
                )}
                {can.withdraw && (
                    <StepForm
                        url={urls.action}
                        action="withdraw"
                        label="Withdraw"
                        comment="optional"
                        variant="outline"
                    />
                )}
                {can.start && (
                    <StepForm
                        url={urls.action}
                        action="start"
                        label="Start review"
                        variant="outline"
                    />
                )}
                {can.decide && (
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StepForm
                            url={urls.action}
                            action="approve"
                            label="Approve"
                        />
                        <StepForm
                            url={urls.action}
                            action="changes"
                            label="Ask for changes"
                            comment="required"
                            variant="outline"
                        />
                        <StepForm
                            url={urls.action}
                            action="reject"
                            label="Reject"
                            comment="required"
                            variant="destructive"
                        />
                    </div>
                )}
                {can.publish && (
                    <div className="grid gap-1">
                        {report.publishHint && (
                            <p className="text-sm text-muted-foreground">
                                This obligation expects approved reports to be
                                published.
                            </p>
                        )}
                        <StepForm
                            url={urls.action}
                            action="publish"
                            label="Publish to the owner and funders"
                        />
                    </div>
                )}
                {can.comment && (
                    <StepForm
                        url={urls.action}
                        action="comment"
                        label="Comment"
                        comment="required"
                        variant="outline"
                    />
                )}
            </PanelBody>
        </Panel>
    );
}
