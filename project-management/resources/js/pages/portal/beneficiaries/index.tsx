import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { Field, Select, invalid } from '@/components/field';
import { useCan } from '@/lib/access';
import { formatDate } from '@/lib/status';
import { destroy, resend, show, store } from '@/routes/beneficiaries';
import { store as createWorkspace } from '@/routes/beneficiaries/workspaces';

type Partnership = {
    id: string;
    name: string;
    label: string;
    email: string | null;
    status: 'connected' | 'invited' | 'expired' | 'declined';
    direct: boolean;
    /** A workspace you created, waiting to be claimed: its address. */
    created: string | null;
    expiresOn: string | null;
    grants: number;
};

type Option = { value: string; label: string };

const statuses = {
    connected: { label: 'Connected', tone: 'success' },
    invited: { label: 'Invitation sent', tone: 'info' },
    awaiting: { label: 'Waiting to be claimed', tone: 'info' },
    expired: { label: 'Invitation expired', tone: 'warning' },
    declined: { label: 'Declined', tone: 'danger' },
} as const;

const keep = { preserveScroll: true };

/** Who a donor works with, and inviting more of them. */
export default function Beneficiaries({
    partnerships,
    labels,
    timezones,
    defaults,
    rootDomain,
}: {
    partnerships: Partnership[];
    labels: Option[];
    timezones: string[];
    defaults: { currency: string; timezone: string; country: string | null };
    rootDomain: string;
}) {
    const can = useCan();
    // Invite them to set up (or connect) their workspace, or create it for them.
    const [mode, setMode] = useState<'invite' | 'create'>('invite');
    const badge = (partnership: Partnership) =>
        statuses[
            partnership.status === 'invited' && partnership.created
                ? 'awaiting'
                : partnership.status
        ];

    return (
        <>
            <Head title="Beneficiaries" />
            <PageHeader
                title="Beneficiaries and implementers"
                description="The organizations you fund. Each one runs its own workspace and chooses what every grant shares with you."
            />

            <Panel>
                {partnerships.length === 0 ? (
                    <PanelBody>
                        <p className="text-sm text-muted-foreground">
                            Nobody yet.
                            {can('organization.manage') &&
                                ' Invite the first one below, or create their workspace.'}
                        </p>
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Organization</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Grants shared
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {partnerships.map((partnership) => (
                                <TableRow key={partnership.id}>
                                    <TableCell>
                                        {partnership.status === 'connected' ? (
                                            <Link
                                                href={show({
                                                    partnership: partnership.id,
                                                })}
                                                className="font-medium underline-offset-2 hover:underline"
                                            >
                                                {partnership.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {partnership.name}
                                            </span>
                                        )}
                                        {partnership.email && (
                                            <span className="block text-xs text-muted-foreground">
                                                {partnership.email}
                                            </span>
                                        )}
                                        {partnership.direct &&
                                            partnership.status !==
                                                'connected' && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Invited on the platform
                                                </span>
                                            )}
                                        {partnership.created && (
                                            <span className="block text-xs text-muted-foreground">
                                                Workspace created:{' '}
                                                {partnership.created}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>{partnership.label}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={badge(partnership).tone}
                                        >
                                            {badge(partnership).label}
                                        </StatusBadge>
                                        {partnership.status === 'invited' &&
                                            partnership.expiresOn && (
                                                <span className="block text-xs text-muted-foreground">
                                                    Until{' '}
                                                    {formatDate(
                                                        partnership.expiresOn,
                                                    )}
                                                </span>
                                            )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {partnership.status === 'connected'
                                            ? partnership.grants
                                            : ''}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {partnership.status !== 'connected' &&
                                            can('organization.manage') && (
                                                <span className="flex justify-end gap-2">
                                                    {partnership.status !==
                                                        'declined' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                router.post(
                                                                    resend.url({
                                                                        partnership:
                                                                            partnership.id,
                                                                    }),
                                                                    {},
                                                                    keep,
                                                                )
                                                            }
                                                        >
                                                            Send again
                                                        </Button>
                                                    )}
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.delete(
                                                                destroy.url({
                                                                    partnership:
                                                                        partnership.id,
                                                                }),
                                                                keep,
                                                            )
                                                        }
                                                    >
                                                        Withdraw
                                                    </Button>
                                                </span>
                                            )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {can('organization.manage') && (
                <Panel className="mt-6">
                    <PanelHeader
                        title={
                            mode === 'invite'
                                ? 'Invite an organization'
                                : 'Create an organization'
                        }
                        description={
                            mode === 'invite'
                                ? 'Name the organization and the person who will run it. They get a link to set it up, or to connect the workspace they already have, and then invite their own team. Nothing connects until they accept.'
                                : 'You set up their workspace now; the person who will run it gets a link to claim it and becomes its owner. You have no access to it: it connects to you when they open it, and shares only what they choose, grant by grant.'
                        }
                    />
                    <PanelBody>
                        <div
                            role="radiogroup"
                            aria-label="How to add them"
                            className="mb-5 inline-flex rounded-md border p-0.5"
                        >
                            {(
                                [
                                    ['invite', 'Invite by email'],
                                    ['create', 'Create their workspace'],
                                ] as const
                            ).map(([value, text]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={mode === value}
                                    onClick={() => setMode(value)}
                                    className={`rounded px-3 py-1.5 text-sm font-medium transition-colors ${
                                        mode === value
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground'
                                    }`}
                                >
                                    {text}
                                </button>
                            ))}
                        </div>
                        <Form
                            key={mode}
                            {...(mode === 'invite'
                                ? store.form()
                                : createWorkspace.form())}
                            options={keep}
                            resetOnSuccess
                            className="grid gap-4 sm:grid-cols-2"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="name"
                                        label="Organization name"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            {...invalid('name', errors.name)}
                                        />
                                    </Field>
                                    <Field
                                        id="label"
                                        label="Type"
                                        error={errors.label}
                                    >
                                        <Select
                                            id="label"
                                            name="label"
                                            defaultValue={labels[0]?.value}
                                            options={labels}
                                        />
                                    </Field>
                                    {mode === 'create' && (
                                        <Field
                                            id="owner_name"
                                            label="Name of the person who will run it"
                                            error={errors.owner_name}
                                        >
                                            <Input
                                                id="owner_name"
                                                name="owner_name"
                                                required
                                                {...invalid(
                                                    'owner_name',
                                                    errors.owner_name,
                                                )}
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        id="email"
                                        label="Email of the person who will run it"
                                        error={errors.email}
                                        hint={
                                            mode === 'create'
                                                ? 'They claim the workspace with this address and become its owner.'
                                                : undefined
                                        }
                                    >
                                        <Input
                                            id="email"
                                            name="email"
                                            type="email"
                                            required
                                            {...invalid('email', errors.email)}
                                        />
                                    </Field>
                                    <Field
                                        id="currency"
                                        label="Currency"
                                        error={errors.currency}
                                        hint="Its own default, e.g. USD, KES, SSP."
                                    >
                                        <Input
                                            id="currency"
                                            name="currency"
                                            required
                                            maxLength={3}
                                            defaultValue={defaults.currency}
                                            className="uppercase"
                                            {...invalid(
                                                'currency',
                                                errors.currency,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id="country_code"
                                        label="Country code"
                                        error={errors.country_code}
                                        hint={
                                            mode === 'create'
                                                ? 'Two letters, e.g. SS, KE.'
                                                : 'Two letters, e.g. SS, KE. Optional.'
                                        }
                                    >
                                        <Input
                                            id="country_code"
                                            name="country_code"
                                            maxLength={2}
                                            required={mode === 'create'}
                                            defaultValue={
                                                mode === 'create'
                                                    ? (defaults.country ?? '')
                                                    : undefined
                                            }
                                            className="uppercase"
                                            {...invalid(
                                                'country_code',
                                                errors.country_code,
                                            )}
                                        />
                                    </Field>
                                    {mode === 'create' && (
                                        <Field
                                            id="slug"
                                            label="Workspace address"
                                            error={errors.slug}
                                            hint={`Optional: made from the name when left empty. Ends in .${rootDomain}`}
                                        >
                                            <Input
                                                id="slug"
                                                name="slug"
                                                maxLength={32}
                                                autoComplete="off"
                                                spellCheck={false}
                                                placeholder="their-organization"
                                                className="lowercase"
                                                {...invalid('slug', errors.slug)}
                                            />
                                        </Field>
                                    )}
                                    <Field
                                        id="timezone"
                                        label="Time zone"
                                        error={errors.timezone}
                                        className={
                                            mode === 'create'
                                                ? undefined
                                                : 'sm:col-span-2'
                                        }
                                    >
                                        <Select
                                            id="timezone"
                                            name="timezone"
                                            defaultValue={defaults.timezone}
                                            options={timezones.map((zone) => ({
                                                value: zone,
                                                label: zone,
                                            }))}
                                        />
                                    </Field>
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {mode === 'invite'
                                                ? 'Send the invitation'
                                                : 'Create the workspace'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
