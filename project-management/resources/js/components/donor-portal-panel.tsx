import { Form, router, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid } from '@/components/field';
import { formatDateTime } from '@/lib/status';
import { store as postMessage } from '@/routes/grants/messages';
import { share } from '@/routes/grants/portal';
import {
    destroy as removeContact,
    store as inviteContact,
} from '@/routes/grants/portal/contacts';
import {
    destroy as removeDocument,
    show as showDocument,
    store as attachDocument,
} from '@/routes/grants/reports/documents';

export type GrantPortal = {
    enabled: boolean;
    budget: boolean;
    spending: boolean;
    commitments: boolean;
    progress: boolean;
    milestones: boolean;
    tasks: boolean;
    contacts: { id: string; name: string; email: string }[];
    invited: string[];
    reports: {
        id: string;
        title: string;
        statusLabel: string;
        canAttach: boolean;
        canDetach: boolean;
        documents: { id: string; name: string }[];
    }[];
    messages: {
        id: string;
        author: string;
        fromFunder: boolean;
        body: string;
        at: string;
    }[];
};

const keep = { preserveScroll: true };

const switches = [
    {
        key: 'budget',
        label: 'Approved budget',
        hint: 'The whole approved budget of each funded project by category, including what other donors pay for.',
    },
    {
        key: 'spending',
        label: 'Spending on this grant',
        hint: 'Paid lines charged to this grant: date, category, description and amount. Never suppliers, requesters or receipts.',
    },
    {
        key: 'commitments',
        label: 'Commitments on this grant',
        hint: 'What is approved but not yet paid from this grant, as totals by category. Never what each item is, suppliers or approvers.',
    },
    {
        key: 'progress',
        label: 'Project progress',
        hint: 'Status and percent done of each funded project.',
    },
    {
        key: 'milestones',
        label: 'Milestones',
        hint: 'The milestones of each funded project: name, due date and whether reached.',
    },
    {
        key: 'tasks',
        label: 'Project tasks',
        hint: 'The tasks of each funded project: number, title, status and due date. Never who they are assigned to, descriptions, comments, files or time.',
    },
] as const;

/**
 * What the funder can see of this grant, who acts for them, the documents of
 * each report, and the conversation with them.
 */
export function DonorPortalPanel({
    grantId,
    visible,
    canManage,
    portal,
}: {
    grantId: string;
    /** Only an active or closed grant reaches the funder. */
    visible: boolean;
    canManage: boolean;
    portal: GrantPortal;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const sharing = {
        portal_enabled: portal.enabled,
        portal_budget: portal.budget,
        portal_spending: portal.spending,
        portal_commitments: portal.commitments,
        portal_progress: portal.progress,
        portal_milestones: portal.milestones,
        portal_tasks: portal.tasks,
    };
    const change = (key: keyof typeof sharing, value: boolean) =>
        router.put(
            share.url({ grant: grantId }),
            { ...sharing, [key]: value },
            keep,
        );

    return (
        <Panel className="mt-6">
            <PanelHeader
                title="Donor portal"
                description="Nothing reaches the funder until you share this grant."
            />
            <PanelBody className="space-y-6">
                <section className="space-y-2">
                    <label className="flex items-center gap-2 text-sm font-medium">
                        <input
                            type="checkbox"
                            checked={portal.enabled}
                            disabled={!canManage}
                            onChange={(event) =>
                                change('portal_enabled', event.target.checked)
                            }
                        />
                        Share this grant with the funder
                    </label>
                    <p className="text-xs text-muted-foreground">
                        {visible
                            ? 'They see its terms, payments, reports and the projects it funds, and can write to you.'
                            : 'The funder will see it once it is active.'}
                    </p>
                    {portal.enabled &&
                        switches.map((item) => (
                            <label
                                key={item.key}
                                className="flex items-start gap-2 pl-5 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    className="mt-1"
                                    checked={portal[item.key]}
                                    disabled={!canManage}
                                    onChange={(event) =>
                                        change(
                                            `portal_${item.key}`,
                                            event.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    {item.label}
                                    <span className="block text-xs text-muted-foreground">
                                        {item.hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                </section>

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">
                        People acting for this funder
                    </h3>
                    {portal.contacts.length === 0 &&
                        portal.invited.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Nobody yet.
                            </p>
                        )}
                    <ul className="space-y-1 text-sm">
                        {portal.contacts.map((contact) => (
                            <li
                                key={contact.id}
                                className="flex items-center justify-between gap-2"
                            >
                                <span>
                                    {contact.name}{' '}
                                    <span className="text-muted-foreground">
                                        {contact.email}
                                    </span>
                                </span>
                                {canManage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label={`End access for ${contact.name}`}
                                        onClick={() =>
                                            router.delete(
                                                removeContact.url({
                                                    grant: grantId,
                                                    contact: contact.id,
                                                }),
                                                keep,
                                            )
                                        }
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                        {portal.invited.map((email) => (
                            <li
                                key={email}
                                className="flex items-center gap-2 text-muted-foreground"
                            >
                                {email}
                                <StatusBadge>Invited</StatusBadge>
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <Form
                            {...inviteContact.form({ grant: grantId })}
                            options={keep}
                            resetOnSuccess
                            className="flex flex-wrap items-end gap-2"
                        >
                            <Field
                                label="Invite by email"
                                id="contact-email"
                                error={errors.email}
                            >
                                <Input
                                    id="contact-email"
                                    name="email"
                                    type="email"
                                    required
                                    {...invalid('contact-email', errors.email)}
                                />
                            </Field>
                            <Button type="submit" size="sm" variant="outline">
                                Send invitation
                            </Button>
                        </Form>
                    )}
                </section>

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">Report documents</h3>
                    {errors.document && (
                        <p role="alert" className="text-sm text-destructive">
                            {errors.document}
                        </p>
                    )}
                    {portal.reports.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No reports scheduled.
                        </p>
                    )}
                    <ul className="space-y-3 text-sm">
                        {portal.reports.map((report) => (
                            <li key={report.id} className="space-y-1">
                                <div className="flex items-center gap-2">
                                    {report.title}
                                    <StatusBadge>
                                        {report.statusLabel}
                                    </StatusBadge>
                                </div>
                                {report.documents.map((document) => (
                                    <div
                                        key={document.id}
                                        className="flex items-center gap-2 pl-3"
                                    >
                                        <a
                                            className="underline"
                                            href={showDocument.url({
                                                grant: grantId,
                                                report: report.id,
                                                document: document.id,
                                            })}
                                        >
                                            {document.name}
                                        </a>
                                        {canManage && report.canDetach && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label={`Remove ${document.name}`}
                                                onClick={() =>
                                                    router.delete(
                                                        removeDocument.url({
                                                            grant: grantId,
                                                            report: report.id,
                                                            document:
                                                                document.id,
                                                        }),
                                                        keep,
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                            </Button>
                                        )}
                                    </div>
                                ))}
                                {canManage && report.canAttach && (
                                    <Form
                                        {...attachDocument.form({
                                            grant: grantId,
                                            report: report.id,
                                        })}
                                        options={keep}
                                        resetOnSuccess
                                        className="flex items-center gap-2 pl-3"
                                    >
                                        <Input
                                            type="file"
                                            name="document"
                                            required
                                            aria-label={`Attach a document to ${report.title}`}
                                            className="max-w-xs"
                                        />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                        >
                                            Attach
                                        </Button>
                                    </Form>
                                )}
                            </li>
                        ))}
                    </ul>
                    <p className="text-xs text-muted-foreground">
                        The funder sees a report&apos;s documents once it is
                        submitted.
                    </p>
                </section>

                <section className="space-y-2">
                    <h3 className="text-sm font-medium">
                        Conversation with the funder
                    </h3>
                    {portal.messages.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No messages yet.
                        </p>
                    )}
                    <ul className="space-y-2 text-sm">
                        {portal.messages.map((message) => (
                            <li key={message.id}>
                                <p className="text-xs text-muted-foreground">
                                    {message.author}
                                    {message.fromFunder
                                        ? ' (funder)'
                                        : ''},{' '}
                                    {formatDateTime(message.at)}
                                </p>
                                <p className="whitespace-pre-wrap">
                                    {message.body}
                                </p>
                            </li>
                        ))}
                    </ul>
                    {canManage && (
                        <Form
                            {...postMessage.form({ grant: grantId })}
                            options={keep}
                            resetOnSuccess
                            className="space-y-2"
                        >
                            <Field
                                label="Write to the funder"
                                id="grant-message"
                                error={errors.body}
                            >
                                <textarea
                                    id="grant-message"
                                    name="body"
                                    rows={3}
                                    required
                                    maxLength={5000}
                                    className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                                />
                            </Field>
                            <Button type="submit" size="sm" variant="outline">
                                Send
                            </Button>
                        </Form>
                    )}
                </section>
            </PanelBody>
        </Panel>
    );
}
