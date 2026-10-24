import { Form, Head, Link } from '@inertiajs/react';
import { Check, Puzzle } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { dashboard } from '@/routes';
import { plans as organizationPlans } from '@/routes/organization';
import { create as createOrganization } from '@/routes/organizations';
import { appIcon } from '@/shared/lib/app-icons';
import { formatDate } from '@/shared/lib/billing';

type Application = {
    code: string;
    name: string;
    tagline: string | null;
    state: 'eligible' | 'trialing' | 'used' | 'active';
    endsAt: string | null;
    daysLeft: number | null;
};

type Props = {
    organization: { displayName: string };
    isOwner: boolean;
    trialDays: number;
    applications: Application[];
    selected: string | null;
};

const stateLabels: Record<Application['state'], string | null> = {
    eligible: null,
    trialing: 'On free trial',
    used: 'Trial used',
    active: 'Already yours',
};

/**
 * Confirm a free trial for the current business. Only applications it may
 * still try can be chosen; the server checks the same again on submit.
 */
export default function Trial({
    organization,
    isOwner,
    trialDays,
    applications,
    selected: initial,
}: Props) {
    const eligible = applications.filter((app) => app.state === 'eligible');
    const [selected, setSelected] = useState(
        eligible.find((app) => app.code === initial)?.code ??
            eligible[0]?.code ??
            '',
    );

    return (
        <>
            <Head title="Start a free trial" />

            <PageHeader
                title={`Start your ${trialDays}-day free trial`}
                description={`For ${organization.displayName}. No payment details are needed, and nothing is charged unless you choose a plan.`}
            />

            {!isOwner ? (
                <Panel>
                    <EmptyState
                        icon={Puzzle}
                        title="Only the owner can start a trial"
                    >
                        Ask the owner of {organization.displayName} to start it,
                        or create a business of your own.
                        <Button asChild className="mt-4">
                            <Link
                                href={createOrganization(
                                    initial
                                        ? { query: { app: initial } }
                                        : undefined,
                                )}
                            >
                                New business
                            </Link>
                        </Button>
                    </EmptyState>
                </Panel>
            ) : applications.length === 0 ? (
                <Panel>
                    <EmptyState icon={Puzzle} title="No trials open right now">
                        Free trials open as applications are released.
                    </EmptyState>
                </Panel>
            ) : (
                <Form action="/trial" method="post">
                    {({ errors, processing }) => (
                        <div className="grid gap-3 lg:grid-cols-[1fr_20rem]">
                            <Panel aria-labelledby="choose">
                                <PanelHeader
                                    title={
                                        <span id="choose">
                                            Choose an application
                                        </span>
                                    }
                                    description="Each application can be tried once per business."
                                />
                                <fieldset className="grid gap-3 p-4 sm:grid-cols-2">
                                    <legend className="sr-only">
                                        Application to try
                                    </legend>
                                    {applications.map((app) => (
                                        <Option
                                            key={app.code}
                                            app={app}
                                            checked={app.code === selected}
                                            onSelect={() =>
                                                setSelected(app.code)
                                            }
                                        />
                                    ))}
                                </fieldset>
                                <InputError
                                    message={errors.application}
                                    className="px-4 pb-4"
                                />
                            </Panel>

                            <Panel aria-labelledby="terms" className="h-fit">
                                <PanelHeader
                                    title={<span id="terms">Your trial</span>}
                                />
                                <ul className="space-y-2.5 px-4 text-sm">
                                    {[
                                        `${trialDays} days, starting when you confirm`,
                                        'Shared modules it needs, such as Finance, come with it',
                                        'Your data stays when you choose a plan',
                                        'Access pauses when the trial ends; nothing is charged',
                                    ].map((line) => (
                                        <li key={line} className="flex gap-2">
                                            <Check
                                                className="mt-0.5 size-4 shrink-0 text-primary"
                                                aria-hidden
                                            />
                                            {line}
                                        </li>
                                    ))}
                                </ul>
                                <div className="p-4">
                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={processing || selected === ''}
                                    >
                                        Start free trial
                                    </Button>
                                    <p className="mt-3 text-center text-xs text-muted-foreground">
                                        Want to pay right away?{' '}
                                        <Link
                                            href={organizationPlans()}
                                            className="underline"
                                        >
                                            See plans
                                        </Link>
                                    </p>
                                </div>
                            </Panel>
                        </div>
                    )}
                </Form>
            )}
        </>
    );
}

function Option({
    app,
    checked,
    onSelect,
}: {
    app: Application;
    checked: boolean;
    onSelect: () => void;
}) {
    const Icon = appIcon(app.code);
    const disabled = app.state !== 'eligible';
    const label = stateLabels[app.state];

    return (
        <label
            className={`flex gap-3 rounded-lg border p-3 transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary/5 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary ${disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer hover:border-primary/40'}`}
        >
            <input
                type="radio"
                name="application"
                value={app.code}
                checked={checked}
                disabled={disabled}
                onChange={onSelect}
                className="sr-only"
            />
            <span className="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                <Icon className="size-4" aria-hidden />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex flex-wrap items-center gap-1.5">
                    <span className="font-medium">{app.name}</span>
                    {label && (
                        <StatusBadge
                            tone={app.state === 'trialing' ? 'info' : 'neutral'}
                        >
                            {label}
                        </StatusBadge>
                    )}
                </span>
                {app.state === 'trialing' && app.endsAt ? (
                    <span className="mt-0.5 block text-sm text-muted-foreground">
                        {app.daysLeft} days left, ends {formatDate(app.endsAt)}
                    </span>
                ) : (
                    app.tagline && (
                        <span className="mt-0.5 line-clamp-2 block text-sm text-muted-foreground">
                            {app.tagline}
                        </span>
                    )
                )}
            </span>
        </label>
    );
}

Trial.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Free trial', href: '/trial' },
    ],
};
