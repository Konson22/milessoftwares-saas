import { Form, Head, Link, router } from '@inertiajs/react';
import { Flag, Handshake, Mail, Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import type {
    CapabilityOption,
    InvitationRow,
    ParticipantRow,
    RelationshipOption,
} from '@/components/collaboration';
import {
    InvitationsTable,
    InviteForm,
    nextStatuses,
    ParticipationStatus,
} from '@/components/collaboration';
import { Field, Select } from '@/components/field';
import { Progress, Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import {
    resend,
    revoke,
    store as inviteSubPartner,
} from '@/routes/shared-projects/invitations';
import { update as updateSubPartner } from '@/routes/shared-projects/partners';
import { index as partnerReports } from '@/routes/shared-projects/reports';
import { index as partnerRequests } from '@/routes/shared-projects/requests';
import {
    destroy as removeRepresentative,
    store as addRepresentative,
} from '@/routes/shared-projects/representatives';
import type { Option } from '@/types';

type SharedProject = {
    code: string;
    name: string;
    description: string | null;
    status: string;
    startOn: string | null;
    dueOn: string | null;
    currency: string;
    progress: number;
    owner: string;
    milestones: { name: string; dueOn: string; reached: boolean }[];
    budget: {
        approvedMinor: number;
        /** Paid, under its earlier name. */
        spentMinor: number;
        committedMinor?: number;
        provenance?: { asOf: string; source: string; paidStatus: string };
        categories: { category: string; amountMinor: number }[];
    } | null;
};

type Props = {
    participation: ParticipantRow;
    capabilityLabels: string[];
    project: SharedProject | null;
    participants: {
        id: string;
        organization: string;
        relationshipLabel: string;
        status: ParticipantRow['status'];
        isUs: boolean;
    }[];
    canManage: boolean;
    members: Option[];
    responsibilities: Option[];
    canInvite: boolean;
    subPartners: ParticipantRow[];
    sentInvitations: InvitationRow[];
    grantable: string[];
    capabilities: CapabilityOption[];
    relationships: RelationshipOption[];
};

/**
 * Another organization's project, as this organization takes part in it:
 * only what the owner shares (App\Collaboration\ParticipantProjection), who
 * works on it for us, and the sub-partners we bring in.
 */
export default function SharedProject({
    participation,
    capabilityLabels,
    project,
    participants,
    canManage,
    members,
    responsibilities,
    canInvite,
    subPartners,
    sentInvitations,
    grantable,
    capabilities,
    relationships,
}: Props) {
    const title = project
        ? `${project.code} · ${project.name}`
        : 'Shared project';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <span className="flex flex-wrap items-center gap-2">
                        {project !== null && (
                            <>
                                <Button asChild size="sm" variant="outline">
                                    <Link
                                        href={partnerReports({
                                            participant: participation.id,
                                        })}
                                    >
                                        Reports
                                    </Link>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <Link
                                        href={partnerRequests({
                                            participant: participation.id,
                                        })}
                                    >
                                        Requests
                                    </Link>
                                </Button>
                            </>
                        )}
                        <ParticipationStatus
                            status={participation.status}
                            cutOff={participation.cutOff}
                        />
                    </span>
                }
            />

            {project === null ? (
                <Panel>
                    <EmptyState
                        icon={Handshake}
                        title="Nothing of this project is shared with you now"
                    >
                        {participation.status === 'active'
                            ? 'The organization you work under is suspended or has left the project.'
                            : `Your participation is ${participation.status}. What was recorded stays on record.`}
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <StatCard
                            label="Owner"
                            value={project.owner}
                            hint={`You: ${participation.relationshipLabel}`}
                        />
                        <StatCard
                            label="Progress"
                            value={`${project.progress}%`}
                            hint={<Status status={project.status} />}
                        />
                        <StatCard
                            label="Dates"
                            value={
                                project.dueOn ? formatDate(project.dueOn) : '—'
                            }
                            hint={
                                project.startOn
                                    ? `from ${formatDate(project.startOn)}`
                                    : undefined
                            }
                        />
                    </div>

                    <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                        <Panel>
                            <PanelHeader
                                title="About"
                                description={`You may: ${capabilityLabels.join(', ')}.`}
                            />
                            <PanelBody className="grid gap-3 text-sm">
                                {project.description && (
                                    <p className="whitespace-pre-line">
                                        {project.description}
                                    </p>
                                )}
                                <Progress value={project.progress} />
                            </PanelBody>
                            <PanelHeader title="Milestones" />
                            {project.milestones.length === 0 ? (
                                <EmptyState icon={Flag} title="No milestones" />
                            ) : (
                                <ul className="divide-y text-sm">
                                    {project.milestones.map((milestone) => (
                                        <li
                                            key={`${milestone.name}-${milestone.dueOn}`}
                                            className="flex items-center gap-2 px-3 py-2"
                                        >
                                            <span
                                                className={
                                                    milestone.reached
                                                        ? 'flex-1 text-muted-foreground line-through'
                                                        : 'flex-1'
                                                }
                                            >
                                                {milestone.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {formatDate(milestone.dueOn)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>

                        <Panel>
                            <PanelHeader title="Organizations on this project" />
                            <ul className="divide-y text-sm">
                                <li className="flex items-center gap-2 px-3 py-2">
                                    <span className="flex-1 font-medium">
                                        {project.owner}
                                    </span>
                                    <StatusBadge>Owner</StatusBadge>
                                </li>
                                {participants.map((other) => (
                                    <li
                                        key={other.id}
                                        className="flex items-center gap-2 px-3 py-2"
                                    >
                                        <span className="flex-1">
                                            {other.organization}
                                            {other.isUs && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    (you)
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {other.relationshipLabel}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    </div>

                    {project.budget && (
                        <Panel>
                            <PanelHeader
                                title="Approved budget"
                                description={`Paid so far: ${formatMoney(project.budget.spentMinor, project.currency)} of ${formatMoney(project.budget.approvedMinor, project.currency)}; ${formatMoney(project.budget.committedMinor ?? 0, project.currency)} committed. ${project.budget.provenance?.paidStatus === 'posted' ? 'Payments posted in Finance' : 'Payments as recorded by the owner, not verified by Finance'}, as of ${project.budget.provenance?.asOf ?? 'today'}.`}
                            />
                            <Table>
                                <TableBody>
                                    {project.budget.categories.map((line) => (
                                        <TableRow key={line.category}>
                                            <TableCell>
                                                {line.category}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    line.amountMinor,
                                                    project.currency,
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </Panel>
                    )}
                </div>
            )}

            <div className="mt-4 grid gap-4">
                <Panel>
                    <PanelHeader
                        title="Who works on it for us"
                        description="Only these members, and those who manage your collaboration, see this project."
                    />
                    {(participation.representatives ?? []).length === 0 ? (
                        <EmptyState icon={Users} title="Nobody yet" />
                    ) : (
                        <ul className="divide-y text-sm">
                            {(participation.representatives ?? []).map(
                                (person) => (
                                    <li
                                        key={person.id}
                                        className="flex items-center gap-2 px-3 py-2"
                                    >
                                        <span className="flex-1">
                                            {person.name}
                                            {person.responsibilities.length >
                                                0 && (
                                                <span className="text-xs text-muted-foreground">
                                                    {' '}
                                                    ·{' '}
                                                    {person.responsibilities.join(
                                                        ', ',
                                                    )}
                                                </span>
                                            )}
                                        </span>
                                        {canManage && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.delete(
                                                        removeRepresentative.url(
                                                            {
                                                                participant:
                                                                    participation.id,
                                                                representative:
                                                                    person.id,
                                                            },
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove
                                            </Button>
                                        )}
                                    </li>
                                ),
                            )}
                        </ul>
                    )}
                    {canManage && members.length > 0 && (
                        <PanelBody className="border-t">
                            <Form
                                {...addRepresentative.form({
                                    participant: participation.id,
                                })}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="grid gap-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="representative"
                                            label="Add a member"
                                            error={errors.user_id}
                                        >
                                            <Select
                                                id="representative"
                                                name="user_id"
                                                required
                                                defaultValue=""
                                                placeholder="Choose"
                                                options={members}
                                            />
                                        </Field>
                                        <fieldset className="grid gap-1 sm:grid-cols-3">
                                            <legend className="mb-1 text-sm font-medium">
                                                Responsibilities
                                            </legend>
                                            {responsibilities.map((option) => (
                                                <label
                                                    key={option.value}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    <input
                                                        type="checkbox"
                                                        name="responsibilities[]"
                                                        value={option.value}
                                                    />
                                                    {option.label}
                                                </label>
                                            ))}
                                        </fieldset>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Add
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>

                {(canInvite || subPartners.length > 0) && (
                    <Panel>
                        <PanelHeader
                            title="Our sub-partners"
                            description="Organizations working under yours on this project. They can never do more than you may."
                        />
                        {subPartners.length === 0 ? (
                            <EmptyState icon={Handshake} title="None yet" />
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Organization</TableHead>
                                        <TableHead>Status</TableHead>
                                        {canInvite && <TableHead />}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {subPartners.map((child) => (
                                        <TableRow key={child.id}>
                                            <TableCell>
                                                {child.organization}
                                                <p className="text-xs text-muted-foreground">
                                                    {child.relationshipLabel}
                                                </p>
                                            </TableCell>
                                            <TableCell>
                                                <ParticipationStatus
                                                    status={child.status}
                                                    cutOff={child.cutOff}
                                                />
                                            </TableCell>
                                            {canInvite && (
                                                <TableCell className="text-right whitespace-nowrap">
                                                    {nextStatuses(
                                                        child.status,
                                                    ).map((next) => (
                                                        <Button
                                                            key={next.value}
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() =>
                                                                router.put(
                                                                    updateSubPartner.url(
                                                                        {
                                                                            participant:
                                                                                participation.id,
                                                                            child: child.id,
                                                                        },
                                                                    ),
                                                                    {
                                                                        status: next.value,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            {next.label}
                                                        </Button>
                                                    ))}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        {canInvite && (
                            <PanelBody className="border-t">
                                <InviteForm
                                    form={inviteSubPartner.form({
                                        participant: participation.id,
                                    })}
                                    relationships={relationships}
                                    capabilities={capabilities}
                                    grantable={grantable}
                                    defaultRelationship="sub_implementer"
                                />
                            </PanelBody>
                        )}
                    </Panel>
                )}

                {sentInvitations.length > 0 && (
                    <Panel>
                        <PanelHeader title="Invitations we sent" />
                        {sentInvitations.length === 0 ? (
                            <EmptyState icon={Mail} title="None" />
                        ) : (
                            <InvitationsTable
                                invitations={sentInvitations}
                                revokeUrl={(invitation) =>
                                    revoke.url({
                                        participant: participation.id,
                                        invitation: invitation.id,
                                    })
                                }
                                resendUrl={(invitation) =>
                                    resend.url({
                                        participant: participation.id,
                                        invitation: invitation.id,
                                    })
                                }
                            />
                        )}
                    </Panel>
                )}
            </div>
        </>
    );
}
