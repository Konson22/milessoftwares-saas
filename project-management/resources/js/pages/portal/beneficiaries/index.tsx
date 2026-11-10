import { Form, Head, Link, router } from '@inertiajs/react';
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

type Partnership = {
    id: string;
    name: string;
    label: string;
    email: string | null;
    status: 'connected' | 'invited' | 'expired' | 'declined';
    direct: boolean;
    expiresOn: string | null;
    grants: number;
};

type Option = { value: string; label: string };

const statuses = {
    connected: { label: 'Connected', tone: 'success' },
    invited: { label: 'Invitation sent', tone: 'info' },
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
}: {
    partnerships: Partnership[];
    labels: Option[];
    timezones: string[];
    defaults: { currency: string; timezone: string };
}) {
    const can = useCan();

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
                                ' Invite the first one below.'}
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
                                    </TableCell>
                                    <TableCell>{partnership.label}</TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                statuses[partnership.status]
                                                    .tone
                                            }
                                        >
                                            {statuses[partnership.status].label}
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
                        title="Invite an organization"
                        description="Name the organization and the person who will run it. They get a link to set it up, and then invite their own team. Nothing connects until they accept."
                    />
                    <PanelBody>
                        <Form
                            {...store.form()}
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
                                    <Field
                                        id="email"
                                        label="Email of the person who will run it"
                                        error={errors.email}
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
                                        hint="Two letters, e.g. SS, KE. Optional."
                                    >
                                        <Input
                                            id="country_code"
                                            name="country_code"
                                            maxLength={2}
                                            className="uppercase"
                                            {...invalid(
                                                'country_code',
                                                errors.country_code,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id="timezone"
                                        label="Time zone"
                                        error={errors.timezone}
                                        className="sm:col-span-2"
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
                                            Send the invitation
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
