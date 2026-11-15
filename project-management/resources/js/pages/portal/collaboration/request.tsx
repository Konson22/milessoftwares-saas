import { Form, Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
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
    StepForm,
    WorkPage,
} from '@/components/partner-work';
import { detailsToInput, RequestForm } from '@/components/request-fields';
import { Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import type { Option } from '@/types';

type Props = {
    frame: WorkFrame;
    request: {
        id: string;
        reference: string;
        type: string;
        typeLabel: string;
        title: string;
        status: string;
        justification: string;
        details: Record<string, unknown>;
        amountMinor: number | null;
        currency: string | null;
        submitter: string;
        reviewer: string;
        direction: string;
        raisedBy: string;
        reviewerId: string;
        submittedAt: string | null;
        decidedAt: string | null;
        appliedAt: string | null;
        fundingState:
            | 'not_approved'
            | 'approved_not_scheduled'
            | 'scheduled'
            | 'received'
            | null;
        budgetVersion: number | null;
    };
    decisions: HistoryEntry[];
    attachments: AttachmentRow[];
    can: {
        edit: boolean;
        cancel: boolean;
        start: boolean;
        decide: boolean;
        escalate: boolean;
        comment: boolean;
        own: boolean;
        startBudget: boolean;
        applyTimeline: boolean;
        linkDisbursement: boolean;
    };
    disbursements: (Option & { amountMinor: number })[];
    types: Record<string, string>;
    categories: Option[];
    reviewers: Option[];
    urls: {
        index: string;
        update: string;
        action: string;
        followUp: string | null;
        attachments: string;
        budget: string | null;
    };
};

const FUNDING: Record<
    string,
    { label: string; tone: 'neutral' | 'warning' | 'info' | 'success' }
> = {
    not_approved: { label: 'Not approved', tone: 'neutral' },
    approved_not_scheduled: { label: 'Approved, not paid', tone: 'warning' },
    scheduled: { label: 'Payment scheduled, not received', tone: 'info' },
    received: { label: 'Received', tone: 'success' },
};

export default function ProjectRequestPage({
    frame,
    request,
    decisions,
    attachments,
    can,
    disbursements,
    types,
    categories,
    reviewers,
    urls,
}: Props) {
    const currency = request.currency ?? '';
    const label = (value: string) =>
        categories.find((option) => option.value === value)?.label ?? value;

    return (
        <WorkPage
            frame={frame}
            title={`${request.reference} · ${request.title}`}
            actions={<Status status={request.status} />}
        >
            <Panel>
                <PanelBody className="grid gap-3">
                    <Facts
                        items={[
                            ['Kind', request.typeLabel],
                            [
                                'Raised by',
                                `${request.raisedBy} (${request.submitter})`,
                            ],
                            ['To', request.reviewer],
                            ...(request.fundingState
                                ? ([
                                      [
                                          'Funds',
                                          <StatusBadge
                                              key="funds"
                                              tone={
                                                  FUNDING[request.fundingState]
                                                      .tone
                                              }
                                          >
                                              {
                                                  FUNDING[request.fundingState]
                                                      .label
                                              }
                                          </StatusBadge>,
                                      ],
                                  ] as [string, ReactNode][])
                                : []),
                            ...(request.budgetVersion
                                ? ([
                                      [
                                          'Budget revision',
                                          `Version ${request.budgetVersion} (in the budget workflow)`,
                                      ],
                                  ] as [string, string][])
                                : []),
                        ]}
                    />
                    <Link href={urls.index} className="text-sm underline">
                        All requests
                    </Link>
                </PanelBody>
            </Panel>

            {can.edit ? (
                <Panel>
                    <PanelHeader title="Edit" />
                    <PanelBody>
                        <RequestForm
                            initial={{
                                type: request.type,
                                title: request.title,
                                justification: request.justification,
                                reviewer: request.reviewerId,
                                details: detailsToInput(
                                    request.type,
                                    request.details,
                                    request.amountMinor,
                                    request.currency,
                                ),
                            }}
                            url={urls.update}
                            method="put"
                            types={Object.entries(types).map(
                                ([value, text]) => ({ value, label: text }),
                            )}
                            categories={categories}
                            reviewers={reviewers}
                            currency={currency}
                            submitLabel="Save"
                            typeFixed
                            reviewerFixed={request.submittedAt !== null}
                        />
                    </PanelBody>
                </Panel>
            ) : (
                <Panel>
                    <PanelHeader title="What is asked" />
                    <PanelBody className="grid gap-3 text-sm">
                        <p className="whitespace-pre-line">
                            {request.justification}
                        </p>
                        <Details
                            type={request.type}
                            details={request.details}
                            amountMinor={request.amountMinor}
                            currency={currency}
                            label={label}
                        />
                    </PanelBody>
                </Panel>
            )}

            <Attachments
                files={attachments}
                uploadUrl={can.edit ? urls.attachments : null}
            />

            {(can.edit ||
                can.cancel ||
                can.start ||
                can.decide ||
                can.escalate ||
                can.comment) && (
                <Panel>
                    <PanelHeader title="What next" />
                    <PanelBody className="grid gap-4">
                        {can.own && request.direction === 'incoming' && (
                            <p className="text-sm text-muted-foreground">
                                You raised this request: someone else decides on
                                it.
                            </p>
                        )}
                        {can.edit && (
                            <StepForm
                                url={urls.action}
                                action="submit"
                                label={`Submit to ${request.reviewer}`}
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
                                    comment="optional"
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
                        {can.escalate && (
                            <StepForm
                                url={urls.action}
                                action="escalate"
                                label="Escalate to the party above"
                                comment="required"
                                variant="outline"
                            />
                        )}
                        {can.cancel && (
                            <StepForm
                                url={urls.action}
                                action="cancel"
                                label="Cancel request"
                                comment="optional"
                                variant="outline"
                            />
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
            )}

            {urls.followUp &&
                (can.startBudget ||
                    can.applyTimeline ||
                    can.linkDisbursement ||
                    urls.budget) && (
                    <Panel>
                        <PanelHeader
                            title="Follow up"
                            description="Through the project's own workflows: nothing here changes the budget or moves money by itself."
                        />
                        <PanelBody className="grid gap-4">
                            {can.startBudget && (
                                <FollowUp
                                    url={urls.followUp}
                                    action="budget"
                                    label="Open a budget revision"
                                />
                            )}
                            {urls.budget && (
                                <Link
                                    href={urls.budget}
                                    className="text-sm underline"
                                >
                                    Open the budget
                                </Link>
                            )}
                            {can.applyTimeline && (
                                <FollowUp
                                    url={urls.followUp}
                                    action="timeline"
                                    label="Apply the new end date"
                                />
                            )}
                            {can.linkDisbursement &&
                                (disbursements.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        Schedule a payment on a grant funding
                                        this project, then link it here.
                                    </p>
                                ) : (
                                    <Form
                                        action={urls.followUp}
                                        method="post"
                                        options={{ preserveScroll: true }}
                                        className="flex flex-wrap items-end gap-2"
                                    >
                                        {({ errors, processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="disbursement"
                                                />
                                                <Field
                                                    id="disbursement"
                                                    label="Scheduled payment"
                                                    error={errors.disbursement}
                                                    className="flex-1"
                                                >
                                                    <Select
                                                        id="disbursement"
                                                        name="disbursement"
                                                        options={disbursements}
                                                        required
                                                    />
                                                </Field>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    disabled={processing}
                                                >
                                                    Link
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                ))}
                        </PanelBody>
                    </Panel>
                )}

            <History entries={decisions} />
        </WorkPage>
    );
}

function FollowUp({
    url,
    action,
    label,
}: {
    url: string;
    action: string;
    label: string;
}) {
    return (
        <Form action={url} method="post" options={{ preserveScroll: true }}>
            {({ errors, processing }) => (
                <div className="grid gap-1">
                    <input type="hidden" name="action" value={action} />
                    {errors.request && (
                        <p className="text-sm text-destructive">
                            {errors.request}
                        </p>
                    )}
                    <span>
                        <Button type="submit" size="sm" disabled={processing}>
                            {label}
                        </Button>
                    </span>
                </div>
            )}
        </Form>
    );
}

function Details({
    type,
    details,
    amountMinor,
    currency,
    label,
}: {
    type: string;
    details: Record<string, unknown>;
    amountMinor: number | null;
    currency: string;
    label: (value: string) => string;
}) {
    const text = (key: string) => (details[key] as string | null) ?? '—';

    switch (type) {
        case 'funding':
            return (
                <Facts
                    items={[
                        [
                            'Amount asked',
                            amountMinor !== null
                                ? formatMoney(amountMinor, currency)
                                : '—',
                        ],
                        ['Agreement', text('agreementReference')],
                        [
                            'Needed by',
                            details.neededBy
                                ? formatDate(details.neededBy as string)
                                : '—',
                        ],
                        [
                            'Categories',
                            ((details.categories as string[] | undefined) ?? [])
                                .map(label)
                                .join(', ') || '—',
                        ],
                    ]}
                />
            );
        case 'budget_revision':
            return (
                <ul className="grid gap-1">
                    {(
                        (details.changes as
                            | {
                                  category: string;
                                  currentMinor: number;
                                  proposedMinor: number;
                                  reason: string | null;
                              }[]
                            | undefined) ?? []
                    ).map((change, index) => (
                        <li
                            key={index}
                            className="flex flex-wrap justify-between gap-2"
                        >
                            <span>
                                {label(change.category)}
                                {change.reason ? `: ${change.reason}` : ''}
                            </span>
                            <span className="tabular-nums">
                                {formatMoney(change.currentMinor, currency)} →{' '}
                                {formatMoney(change.proposedMinor, currency)}
                            </span>
                        </li>
                    ))}
                </ul>
            );
        case 'activity_change':
            return (
                <Facts
                    items={[
                        ['Activity', text('activity')],
                        [
                            'Change',
                            <span key="c" className="whitespace-pre-line">
                                {text('change')}
                            </span>,
                        ],
                    ]}
                />
            );
        case 'timeline_extension':
            return (
                <Facts
                    items={[
                        [
                            'Current end',
                            details.currentEnd
                                ? formatDate(details.currentEnd as string)
                                : '—',
                        ],
                        [
                            'Proposed end',
                            details.proposedEnd
                                ? formatDate(details.proposedEnd as string)
                                : '—',
                        ],
                    ]}
                />
            );
        default:
            return (
                <Facts
                    items={[
                        [
                            'Asked',
                            <span key="a" className="whitespace-pre-line">
                                {text('asked')}
                            </span>,
                        ],
                        [
                            'Needed by',
                            details.neededBy
                                ? formatDate(details.neededBy as string)
                                : '—',
                        ],
                    ]}
                />
            );
    }
}
