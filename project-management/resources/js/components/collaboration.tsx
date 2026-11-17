import { Form, router } from '@inertiajs/react';
import type { RouteFormDefinition } from '@/wayfinder';
import { useEffect, useState } from 'react';
import { StatusBadge } from '@miles/ui/components/status-badge';
import type { Tone } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select } from '@/components/field';
import { formatDate } from '@/lib/status';
import { organizations } from '@/routes/collaboration';
import type { Option } from '@/types';

/** A capability a participant can be given (App\Collaboration\Capability). */
export type CapabilityOption = Option & { effective: boolean };

/** A relationship and the capabilities it suggests (App\Collaboration\Relationship). */
export type RelationshipOption = Option & { suggested: string[] };

export type ParticipantRow = {
    id: string;
    organization: string;
    relationship: string;
    relationshipLabel: string;
    parentId: string | null;
    parent: string | null;
    status: 'active' | 'suspended' | 'completed' | 'revoked';
    capabilities: string[];
    effective: string[];
    cutOff: boolean;
    joinedAt: string;
    statusChangedAt: string | null;
    representatives:
        { id: string; name: string; responsibilities: string[] }[] | null;
};

export type InvitationRow = {
    id: string;
    invitee: string | null;
    email: string | null;
    onTatook: boolean;
    from: string;
    fromParticipantId: string | null;
    inviter: string;
    relationship: string;
    relationshipLabel: string;
    capabilities: string[];
    message: string | null;
    status: 'pending' | 'expired' | 'accepted' | 'declined' | 'revoked';
    createdAt: string;
    expiresAt: string;
    answeredAt: string | null;
    acceptedBy: string | null;
};

const PARTICIPATION_TONES: Record<ParticipantRow['status'], Tone> = {
    active: 'success',
    suspended: 'warning',
    completed: 'neutral',
    revoked: 'danger',
};

const INVITATION_TONES: Record<InvitationRow['status'], Tone> = {
    pending: 'info',
    expired: 'warning',
    accepted: 'success',
    declined: 'neutral',
    revoked: 'neutral',
};

function capitalize(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

export function ParticipationStatus({
    status,
    cutOff = false,
}: {
    status: ParticipantRow['status'];
    cutOff?: boolean;
}) {
    if (cutOff) {
        return <StatusBadge tone="warning">Cut off</StatusBadge>;
    }

    return (
        <StatusBadge tone={PARTICIPATION_TONES[status]}>
            {capitalize(status)}
        </StatusBadge>
    );
}

export function InvitationStatus({
    status,
}: {
    status: InvitationRow['status'];
}) {
    return (
        <StatusBadge tone={INVITATION_TONES[status]}>
            {capitalize(status)}
        </StatusBadge>
    );
}

/**
 * The capabilities to give, as checkboxes. View is always given; what the
 * member could not do themselves can't be ticked; those marked "later" are
 * kept for features still to come.
 */
export function CapabilityPicker({
    capabilities,
    grantable,
    selected,
    onChange,
}: {
    capabilities: CapabilityOption[];
    grantable: string[];
    selected: string[];
    onChange?: (selected: string[]) => void;
}) {
    return (
        <fieldset className="grid gap-1.5 sm:grid-cols-2">
            <legend className="mb-1 text-sm font-medium">
                What they may do
            </legend>
            <input type="hidden" name="capabilities[]" value="view" />
            {capabilities.map((capability) => {
                const isView = capability.value === 'view';
                const allowed = grantable.includes(capability.value);

                // Held already but not theirs to give: kept as it is.
                const kept =
                    !isView && !allowed && selected.includes(capability.value);

                return (
                    <label
                        key={capability.value}
                        className="flex items-center gap-2 text-sm"
                    >
                        {kept && (
                            <input
                                type="hidden"
                                name="capabilities[]"
                                value={capability.value}
                            />
                        )}
                        <input
                            type="checkbox"
                            name={isView ? undefined : 'capabilities[]'}
                            value={capability.value}
                            checked={
                                isView || selected.includes(capability.value)
                            }
                            disabled={isView || !allowed}
                            onChange={(event) =>
                                onChange?.(
                                    event.target.checked
                                        ? [...selected, capability.value]
                                        : selected.filter(
                                              (key) => key !== capability.value,
                                          ),
                                )
                            }
                        />
                        <span
                            className={allowed ? '' : 'text-muted-foreground'}
                        >
                            {capability.label}
                            {!capability.effective && (
                                <span className="text-xs text-muted-foreground">
                                    {' '}
                                    (later)
                                </span>
                            )}
                        </span>
                    </label>
                );
            })}
        </fieldset>
    );
}

type Found = { id: string; name: string; slug: string; country: string | null };

/**
 * Finds an organization on Tatook to invite: by name among those that let
 * themselves be found (or published a profile), or by its exact workspace
 * address. A failed search says so; it is never shown as nothing found.
 */
export function OrganizationSearch({
    value,
    onChange,
    notFound = 'No organization on Tatook matches. Check the spelling, try its workspace address, or invite someone there by email.',
}: {
    value: Found | null;
    onChange: (found: Found | null) => void;
    notFound?: string;
}) {
    const [query, setQuery] = useState('');
    const [results, setResults] = useState<Found[]>([]);
    // What the last search came to: an error is never shown as "nothing found".
    const [state, setState] = useState<
        'idle' | 'searching' | 'done' | 'failed'
    >('idle');

    useEffect(() => {
        if (query.trim().length < 2) {
            setResults([]);
            setState('idle');

            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            setState('searching');
            fetch(organizations.url({ query: { q: query.trim() } }), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json();
                })
                .then((found: Found[]) => {
                    setResults(found);
                    setState('done');
                })
                .catch((error: unknown) => {
                    if (
                        error instanceof DOMException &&
                        error.name === 'AbortError'
                    ) {
                        return;
                    }
                    setResults([]);
                    setState('failed');
                });
        }, 250);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [query]);

    if (value !== null) {
        return (
            <div className="flex items-center gap-2 text-sm">
                <input type="hidden" name="organization_id" value={value.id} />
                <span className="font-medium">{value.name}</span>
                <span className="text-muted-foreground">{value.slug}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => onChange(null)}
                >
                    Change
                </Button>
            </div>
        );
    }

    return (
        <div className="grid gap-1">
            <Input
                id="invite-search"
                value={query}
                onChange={(event) => setQuery(event.target.value)}
                placeholder="Organization name or workspace address"
                autoComplete="off"
            />
            {state === 'idle' && (
                <p className="text-xs text-muted-foreground">
                    Type at least two letters of its name, or its workspace
                    address.
                </p>
            )}
            {state === 'searching' && (
                <p className="text-xs text-muted-foreground">Searching…</p>
            )}
            {state === 'failed' && (
                <p className="text-sm text-destructive" role="alert">
                    The search did not work just now. Try again in a moment.
                </p>
            )}
            {state === 'done' && results.length === 0 && (
                <p className="text-sm text-muted-foreground">{notFound}</p>
            )}
            {results.length > 0 && (
                <ul className="divide-y border text-sm">
                    {results.map((found) => (
                        <li key={found.id}>
                            <button
                                type="button"
                                className="w-full px-3 py-1.5 text-left hover:bg-muted"
                                onClick={() => onChange(found)}
                            >
                                {found.name}{' '}
                                <span className="text-muted-foreground">
                                    {found.slug}
                                    {found.country ? ` · ${found.country}` : ''}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

/**
 * Invite an organization on Tatook, or someone by email whose organization
 * is not here yet.
 */
export function InviteForm({
    form,
    relationships,
    capabilities,
    grantable,
    parents,
    defaultRelationship = 'implementer',
}: {
    form: RouteFormDefinition<'post'>;
    relationships: RelationshipOption[];
    capabilities: CapabilityOption[];
    grantable: string[];
    parents?: Option[];
    defaultRelationship?: string;
}) {
    const suggestion = (key: string) =>
        (
            relationships.find((option) => option.value === key)?.suggested ?? [
                'view',
            ]
        ).filter((capability) => grantable.includes(capability));
    const [byEmail, setByEmail] = useState(false);
    const [found, setFound] = useState<Found | null>(null);
    const [relationship, setRelationship] = useState(defaultRelationship);
    const [selected, setSelected] = useState<string[]>(
        suggestion(defaultRelationship),
    );

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onSuccess={() => {
                setFound(null);
                setSelected(suggestion(relationship));
            }}
            resetOnSuccess
            className="grid gap-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="flex gap-2 text-sm">
                        <Button
                            type="button"
                            size="sm"
                            variant={byEmail ? 'ghost' : 'outline'}
                            onClick={() => setByEmail(false)}
                        >
                            On Tatook
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant={byEmail ? 'outline' : 'ghost'}
                            onClick={() => setByEmail(true)}
                        >
                            Not here yet: by email
                        </Button>
                    </div>
                    {byEmail ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field
                                id="invite-contact"
                                label="Contact name"
                                error={errors.contact_name}
                            >
                                <Input
                                    id="invite-contact"
                                    name="contact_name"
                                    required
                                />
                            </Field>
                            <Field
                                id="invite-email"
                                label="Email"
                                error={errors.email}
                            >
                                <Input
                                    id="invite-email"
                                    name="email"
                                    type="email"
                                    required
                                />
                            </Field>
                        </div>
                    ) : (
                        <Field
                            id="invite-search"
                            label="Organization"
                            error={errors.organization_id}
                            hint="Organizations with a published profile, by name, or any by its exact workspace address."
                        >
                            <OrganizationSearch
                                value={found}
                                onChange={setFound}
                            />
                        </Field>
                    )}
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field
                            id="invite-relationship"
                            label="How they take part"
                            error={errors.relationship}
                            hint="A label: what they may do is what you tick below."
                        >
                            <Select
                                id="invite-relationship"
                                name="relationship"
                                value={relationship}
                                onChange={(event) => {
                                    setRelationship(event.target.value);
                                    setSelected(suggestion(event.target.value));
                                }}
                                options={relationships}
                            />
                        </Field>
                        {parents && parents.length > 0 && (
                            <Field
                                id="invite-parent"
                                label="Works under"
                                error={errors.parent_participant_id}
                            >
                                <Select
                                    id="invite-parent"
                                    name="parent_participant_id"
                                    defaultValue=""
                                    placeholder="Directly with us"
                                    options={parents}
                                />
                            </Field>
                        )}
                    </div>
                    <CapabilityPicker
                        capabilities={capabilities}
                        grantable={grantable}
                        selected={selected}
                        onChange={setSelected}
                    />
                    {errors.capabilities && (
                        <p className="text-sm text-destructive">
                            {errors.capabilities}
                        </p>
                    )}
                    <Field
                        id="invite-message"
                        label="Message (optional)"
                        error={errors.message}
                    >
                        <textarea
                            id="invite-message"
                            name="message"
                            rows={3}
                            maxLength={2000}
                            className="w-full rounded-md border bg-transparent px-3 py-2 text-sm"
                        />
                    </Field>
                    <div>
                        <Button
                            type="submit"
                            disabled={
                                processing || (!byEmail && found === null)
                            }
                        >
                            Send invitation
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * Invitations sent, with their status, and withdraw or send again while
 * nobody has answered.
 */
export function InvitationsTable({
    invitations,
    revokeUrl,
    resendUrl,
}: {
    invitations: InvitationRow[];
    revokeUrl?: (invitation: InvitationRow) => string;
    resendUrl?: (invitation: InvitationRow) => string | undefined;
}) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Invited</TableHead>
                    <TableHead>As</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Sent</TableHead>
                    <TableHead />
                </TableRow>
            </TableHeader>
            <TableBody>
                {invitations.map((invitation) => {
                    const open =
                        invitation.status === 'pending' ||
                        invitation.status === 'expired';
                    const again = open ? resendUrl?.(invitation) : undefined;

                    return (
                        <TableRow key={invitation.id}>
                            <TableCell>
                                <span className="font-medium">
                                    {invitation.invitee}
                                </span>
                                <p className="text-xs text-muted-foreground">
                                    {invitation.onTatook
                                        ? 'On Tatook'
                                        : invitation.email}
                                    {invitation.acceptedBy &&
                                        !invitation.onTatook &&
                                        ` · accepted as ${invitation.acceptedBy}`}
                                </p>
                            </TableCell>
                            <TableCell>
                                {invitation.relationshipLabel}
                                <p className="text-xs text-muted-foreground">
                                    {invitation.capabilities.join(', ')}
                                </p>
                            </TableCell>
                            <TableCell>
                                <InvitationStatus status={invitation.status} />
                                {invitation.status === 'pending' && (
                                    <p className="text-xs text-muted-foreground">
                                        until {formatDate(invitation.expiresAt)}
                                    </p>
                                )}
                            </TableCell>
                            <TableCell className="text-xs text-muted-foreground">
                                {formatDate(invitation.createdAt)} by{' '}
                                {invitation.inviter}
                                {invitation.fromParticipantId &&
                                    ` (${invitation.from})`}
                            </TableCell>
                            <TableCell className="text-right whitespace-nowrap">
                                {again && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                again,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Send again
                                    </Button>
                                )}
                                {open && revokeUrl && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() =>
                                            router.post(
                                                revokeUrl(invitation),
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Withdraw
                                    </Button>
                                )}
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}

/**
 * What changing a participation's status can lead to (completed and revoked
 * are final).
 */
export function nextStatuses(
    status: ParticipantRow['status'],
): { value: ParticipantRow['status']; label: string }[] {
    if (status === 'active') {
        return [
            { value: 'suspended', label: 'Suspend' },
            { value: 'completed', label: 'Complete' },
            { value: 'revoked', label: 'Revoke' },
        ];
    }

    if (status === 'suspended') {
        return [
            { value: 'active', label: 'Reactivate' },
            { value: 'completed', label: 'Complete' },
            { value: 'revoked', label: 'Revoke' },
        ];
    }

    return [];
}
