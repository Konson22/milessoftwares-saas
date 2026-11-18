import { Form, Head, Link } from '@inertiajs/react';
import { Building2, CircleAlert, CircleCheck, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import GetStartedController from '@/actions/App/Sites/Central/Http/Controllers/Account/GetStartedController';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import { Spinner } from '@/shared/components/ui/spinner';
import { workspaces as workspacesRoute } from '@/routes';

export type Selection = {
    id: string;
    kind: 'application' | 'product' | 'plan';
    action: 'trial' | 'subscribe';
    name: string | null;
    summary: string | null;
    applications: string[];
    plan: {
        name: string;
        price: string;
        interval: string;
        trialDays: number;
    } | null;
    expiresAt: string;
};

type Organization = {
    id: string;
    name: string;
    address: string | null;
    isOwner: boolean;
    open: boolean;
    ownerName: string | null;
};

export type GetStartedProps = {
    state:
        | 'choose'
        | 'completed'
        | 'expired'
        | 'unavailable'
        | 'other_account'
        | 'missing'
        | 'guest';
    selection: Selection | null;
    trialDays: number;
    organizations: Organization[];
    createUrl: string | null;
    completed: {
        organization: string | null;
        outcome: string | null;
        url: string | null;
    } | null;
    others: { name: string; url: string }[];
    registerUrl: string;
    loginUrl: string;
    chooseAgainUrl: string;
};

/** What the person is starting, in one line. */
export function startingLine(selection: Selection, trialDays: number): string {
    if (selection.plan) {
        return selection.action === 'trial'
            ? `${selection.plan.trialDays}-day free trial of the ${selection.plan.name} plan`
            : `${selection.plan.name} plan, ${selection.plan.price}${selection.plan.price === 'Free' ? '' : ` ${selection.plan.interval}`}. Tatook switches it on once it is paid.`;
    }

    return `${trialDays}-day free trial. Nothing is charged unless you choose a plan.`;
}

const outcomes: Record<string, string> = {
    trial_started: 'Your free trial has started.',
    already_active: 'This organization already had it, so nothing changed.',
    requested:
        'Your request was sent. Tatook confirms it and sends the invoice; it switches on once paid.',
};

/**
 * Getting started with a product chosen on the public site: the account
 * chooses which of its organizations it is for (only an owner can add
 * one), or creates a new one, and confirms. Shows what is being started
 * the whole way, and says plainly when the choice can no longer be used.
 */
export default function GetStarted({
    state,
    selection,
    trialDays,
    organizations,
    createUrl,
    completed,
    others,
    chooseAgainUrl,
}: GetStartedProps) {
    const eligible = organizations.filter((o) => o.isOwner && o.open);
    const [chosen, setChosen] = useState<string | null>(
        eligible.length === 1 ? eligible[0].id : null,
    );
    const name = selection?.name ?? 'this product';

    if (state !== 'choose' && state !== 'completed') {
        return (
            <>
                <Head title="Get started" />
                <EmptyState
                    icon={CircleAlert}
                    title={problems[state] ?? problems.missing}
                    children="Your account and organizations are unchanged."
                    action={
                        <Button asChild>
                            <a href={chooseAgainUrl}>Choose a product</a>
                        </Button>
                    }
                />
            </>
        );
    }

    if (state === 'completed' && completed) {
        return (
            <>
                <Head title="Get started" />
                <EmptyState
                    icon={CircleCheck}
                    title={`${name} is set up in ${completed.organization ?? 'your organization'}`}
                    children={
                        outcomes[completed.outcome ?? ''] ??
                        'Everything is in place.'
                    }
                    action={
                        completed.url && (
                            <Button asChild>
                                <a href={completed.url}>Open {name}</a>
                            </Button>
                        )
                    }
                />
            </>
        );
    }

    return (
        <>
            <Head title={`Get started with ${name}`} />

            <PageHeader
                title={`You're getting started with ${name}`}
                description={
                    selection ? startingLine(selection, trialDays) : undefined
                }
            />

            {selection && selection.applications.length > 1 && (
                <p className="mb-4 text-sm text-muted-foreground">
                    Includes {selection.applications.join(', ')}.
                </p>
            )}

            <div className="grid gap-3 lg:grid-cols-2">
                <Panel aria-labelledby="existing">
                    <PanelHeader
                        title={
                            <span id="existing">
                                Add it to an organization you own
                            </span>
                        }
                        description="Only an organization's owner can add products to it."
                    />
                    {organizations.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">
                            You have no organizations yet. Create one to
                            continue.
                        </p>
                    ) : (
                        <Form
                            {...GetStartedController.store.form({
                                intent: selection?.id ?? '',
                            })}
                        >
                            {({ processing, errors }) => (
                                <>
                                    <ul className="divide-y" role="radiogroup">
                                        {organizations.map((organization) => {
                                            const usable =
                                                organization.isOwner &&
                                                organization.open;

                                            return (
                                                <li key={organization.id}>
                                                    <label
                                                        className={`flex items-center gap-3 p-4 ${usable ? 'cursor-pointer' : 'text-muted-foreground'}`}
                                                    >
                                                        <input
                                                            type="radio"
                                                            name="organization_id"
                                                            value={
                                                                organization.id
                                                            }
                                                            disabled={!usable}
                                                            checked={
                                                                chosen ===
                                                                organization.id
                                                            }
                                                            onChange={() =>
                                                                setChosen(
                                                                    organization.id,
                                                                )
                                                            }
                                                        />
                                                        <Building2
                                                            className="size-4 shrink-0"
                                                            aria-hidden
                                                        />
                                                        <span className="min-w-0 flex-1">
                                                            <span className="flex flex-wrap items-center gap-1.5 font-medium">
                                                                {
                                                                    organization.name
                                                                }
                                                                {organization.isOwner && (
                                                                    <StatusBadge>
                                                                        Owner
                                                                    </StatusBadge>
                                                                )}
                                                            </span>
                                                            <span className="block truncate text-sm text-muted-foreground">
                                                                {!organization.open
                                                                    ? 'Not open'
                                                                    : organization.isOwner
                                                                      ? organization.address
                                                                      : `Ask ${organization.ownerName ?? 'the owner'} to add it`}
                                                            </span>
                                                        </span>
                                                    </label>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                    <div className="space-y-3 border-t p-4">
                                        <InputError
                                            message={errors.organization_id}
                                        />
                                        <Button
                                            className="w-full"
                                            disabled={
                                                processing || chosen === null
                                            }
                                        >
                                            {processing && <Spinner />}
                                            {chosen
                                                ? `${selection?.action === 'subscribe' ? 'Request' : 'Start'} ${name} in ${organizations.find((o) => o.id === chosen)?.name}`
                                                : 'Choose an organization'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </Panel>

                <div className="space-y-3">
                    <Panel aria-labelledby="new" className="h-fit">
                        <PanelHeader
                            title={
                                <span id="new">Create a new organization</span>
                            }
                            description={`Its own address and data, with you as owner. ${name} starts there as soon as it is created.`}
                        />
                        <div className="p-4">
                            {createUrl && (
                                <Button asChild className="w-full">
                                    <Link href={createUrl}>
                                        <Plus aria-hidden />
                                        New organization
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </Panel>

                    {others.length > 0 && (
                        <Panel aria-labelledby="others" className="h-fit">
                            <PanelHeader
                                title={
                                    <span id="others">
                                        Also waiting for you
                                    </span>
                                }
                                description="Other products you chose. Each is separate."
                            />
                            <ul className="divide-y">
                                {others.map((other) => (
                                    <li key={other.url} className="p-4">
                                        <Link
                                            href={other.url}
                                            className="text-sm font-medium underline-offset-4 hover:underline"
                                        >
                                            {other.name}
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}

                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <a href={chooseAgainUrl}>Choose something else</a>
                        </Button>
                        {selection && (
                            <Form
                                {...GetStartedController.cancel.form({
                                    intent: selection.id,
                                })}
                            >
                                {({ processing }) => (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Not now
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

export const problems: Record<string, string> = {
    expired: 'This selection has expired. Choose the product again.',
    unavailable:
        'This is no longer available to start. Choose another product or plan.',
    other_account:
        'This selection belongs to another account. Choose the product again while signed in as yourself.',
    missing: 'This link is not valid. Choose the product again.',
};

GetStarted.layout = {
    breadcrumbs: [
        { title: 'Your businesses', href: workspacesRoute() },
        { title: 'Get started', href: '#' },
    ],
};
