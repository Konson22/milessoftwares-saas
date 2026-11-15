import { Form, router } from '@inertiajs/react';
import { Handshake, Mail } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
    CapabilityPicker,
    InvitationsTable,
    InviteForm,
    nextStatuses,
    ParticipationStatus,
} from '@/components/collaboration';
import { ProjectFrame } from '@/components/project-frame';
import { formatDate } from '@/lib/status';
import {
    resend,
    revoke,
    store as invite,
} from '@/routes/projects/collaboration/invitations';
import {
    status as setStatus,
    update as updateCapabilities,
} from '@/routes/projects/collaboration/participants';
import type { Option, ProjectHeader } from '@/types';

type Props = {
    project: ProjectHeader;
    owner: string;
    participants: ParticipantRow[];
    invitations: InvitationRow[];
    canManage: boolean;
    grantable: string[];
    capabilities: CapabilityOption[];
    relationships: RelationshipOption[];
    parents: Option[];
};

/**
 * The organizations taking part in the project, what each may do, and the
 * invitations sent. The project stays this organization's: nobody joins
 * before someone who manages their organization accepts.
 */
export default function Collaboration({
    project,
    owner,
    participants,
    invitations,
    canManage,
    grantable,
    capabilities,
    relationships,
    parents,
}: Props) {
    const [editing, setEditing] = useState<string | null>(null);
    const labels = Object.fromEntries(
        capabilities.map((capability) => [capability.value, capability.label]),
    );

    return (
        <ProjectFrame project={project} title="Partners">
            <div className="grid gap-4">
                <Panel>
                    <PanelHeader
                        title="Participating organizations"
                        description={`${owner} owns this project. Others see it in their own workspace, and only what they are given.`}
                    />
                    {participants.length === 0 ? (
                        <EmptyState
                            icon={Handshake}
                            title="Only your organization works on this project"
                        >
                            {canManage
                                ? 'Invite a donor, an implementing partner or an auditor below when you need to.'
                                : undefined}
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Organization</TableHead>
                                    <TableHead>Takes part as</TableHead>
                                    <TableHead>May</TableHead>
                                    <TableHead>Status</TableHead>
                                    {canManage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {participants.map((participant) => (
                                    <TableRow key={participant.id}>
                                        <TableCell>
                                            <span className="font-medium">
                                                {participant.organization}
                                            </span>
                                            <p className="text-xs text-muted-foreground">
                                                {participant.parent
                                                    ? `Under ${participant.parent}`
                                                    : 'Directly with you'}
                                                {participant.representatives &&
                                                    participant.representatives
                                                        .length > 0 &&
                                                    ` · ${participant.representatives.map((person) => person.name).join(', ')}`}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {participant.relationshipLabel}
                                            <p className="text-xs text-muted-foreground">
                                                since{' '}
                                                {formatDate(
                                                    participant.joinedAt,
                                                )}
                                            </p>
                                        </TableCell>
                                        <TableCell className="max-w-xs text-xs">
                                            {editing === participant.id ? (
                                                <Form
                                                    {...updateCapabilities.form(
                                                        {
                                                            project: project.id,
                                                            participant:
                                                                participant.id,
                                                        },
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                    onSuccess={() =>
                                                        setEditing(null)
                                                    }
                                                    className="grid gap-2"
                                                >
                                                    {({
                                                        errors,
                                                        processing,
                                                    }) => (
                                                        <EditCapabilities
                                                            capabilities={
                                                                capabilities
                                                            }
                                                            grantable={
                                                                grantable
                                                            }
                                                            initial={
                                                                participant.capabilities
                                                            }
                                                            error={
                                                                errors.capabilities
                                                            }
                                                            processing={
                                                                processing
                                                            }
                                                            onCancel={() =>
                                                                setEditing(null)
                                                            }
                                                        />
                                                    )}
                                                </Form>
                                            ) : (
                                                participant.capabilities
                                                    .map(
                                                        (key) =>
                                                            labels[key] ?? key,
                                                    )
                                                    .join(', ')
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <ParticipationStatus
                                                status={participant.status}
                                                cutOff={participant.cutOff}
                                            />
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right whitespace-nowrap">
                                                {participant.status ===
                                                    'active' ||
                                                participant.status ===
                                                    'suspended' ? (
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            setEditing(
                                                                participant.id,
                                                            )
                                                        }
                                                    >
                                                        Change access
                                                    </Button>
                                                ) : null}
                                                {nextStatuses(
                                                    participant.status,
                                                ).map((next) => (
                                                    <Button
                                                        key={next.value}
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() =>
                                                            router.post(
                                                                setStatus.url({
                                                                    project:
                                                                        project.id,
                                                                    participant:
                                                                        participant.id,
                                                                }),
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
                </Panel>

                {canManage && (
                    <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
                        <Panel>
                            <PanelHeader
                                title="Invite an organization"
                                description="They take part once someone who manages their organization accepts. Completed and revoked participations stay on record."
                            />
                            <PanelBody>
                                <InviteForm
                                    form={invite.form({ project: project.id })}
                                    relationships={relationships}
                                    capabilities={capabilities}
                                    grantable={grantable}
                                    parents={parents}
                                />
                            </PanelBody>
                        </Panel>
                        <Panel>
                            <PanelHeader title="Invitations" />
                            {invitations.length === 0 ? (
                                <EmptyState icon={Mail} title="None sent yet" />
                            ) : (
                                <InvitationsTable
                                    invitations={invitations}
                                    revokeUrl={(invitation) =>
                                        revoke.url({
                                            project: project.id,
                                            invitation: invitation.id,
                                        })
                                    }
                                    resendUrl={(invitation) =>
                                        invitation.fromParticipantId
                                            ? undefined
                                            : resend.url({
                                                  project: project.id,
                                                  invitation: invitation.id,
                                              })
                                    }
                                />
                            )}
                        </Panel>
                    </div>
                )}
            </div>
        </ProjectFrame>
    );
}

function EditCapabilities({
    capabilities,
    grantable,
    initial,
    error,
    processing,
    onCancel,
}: {
    capabilities: CapabilityOption[];
    grantable: string[];
    initial: string[];
    error?: string;
    processing: boolean;
    onCancel: () => void;
}) {
    const [selected, setSelected] = useState(initial);

    return (
        <>
            <CapabilityPicker
                capabilities={capabilities}
                grantable={grantable}
                selected={selected}
                onChange={setSelected}
            />
            {error && <p className="text-destructive">{error}</p>}
            <span className="flex gap-2">
                <Button type="submit" size="sm" disabled={processing}>
                    Save
                </Button>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </span>
        </>
    );
}
