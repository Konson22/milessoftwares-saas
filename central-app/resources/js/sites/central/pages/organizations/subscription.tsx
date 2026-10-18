import { Form, Head, Link } from '@inertiajs/react';
import { AppWindow, CreditCard, Package } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/shared/components/ui/dialog';
import { Label } from '@/shared/components/ui/label';
import { dashboard } from '@/routes';
import {
    plans as organizationPlans,
    subscription as organizationSubscription,
} from '@/routes/organization';
import { cancellation } from '@/routes/organization/subscriptions';
import { RequestHistory } from '@/sites/central/components/request-history';
import type { PlanRequestRow } from '@/sites/central/components/request-history';
import {
    formatDate,
    formatMoney,
    humanize,
    intervalLabel,
    subscriptionTone,
} from '@/shared/lib/billing';

type Subscription = {
    id: string;
    status: string;
    live: boolean;
    trialEndsAt: string | null;
    startedAt: string | null;
    periodEnd: string | null;
    renewsAt: string | null;
    cancelledAt: string | null;
    cancellationRequestId: string | null;
    plans: {
        name: string;
        priceMinor: number;
        currency: string;
        interval: string;
        quantity: number;
        status: string;
    }[];
};

type Props = {
    organization: { displayName: string };
    subscriptions: Subscription[];
    entitlements: {
        id: string;
        application: string;
        source: string;
        validUntil: string | null;
    }[];
    requests: PlanRequestRow[];
};

const sourceLabel: Record<string, string> = {
    subscription: 'Included in your plan',
    trial: 'Free trial',
    manual: 'Granted by Miles Global Softwares',
};

export default function OrganizationSubscription({
    organization,
    subscriptions,
    entitlements,
    requests,
}: Props) {
    const live = subscriptions.filter((subscription) => subscription.live);
    const past = subscriptions.filter((subscription) => !subscription.live);

    return (
        <>
            <Head title={`${organization.displayName} subscription`} />

            <PageHeader
                title="Subscription"
                description="Your plans and the applications they include. Changes are confirmed by Miles Global Softwares."
                actions={
                    <Button asChild>
                        <Link href={organizationPlans()}>
                            <Package /> Change or add a plan
                        </Link>
                    </Button>
                }
            />

            <div className="grid gap-3 xl:grid-cols-3">
                <div className="space-y-3 xl:col-span-2">
                    <Panel>
                        <PanelHeader title="Current plans" />
                        {live.length === 0 ? (
                            <EmptyState
                                icon={CreditCard}
                                title="No active subscription"
                                action={
                                    <Button asChild size="sm">
                                        <Link href={organizationPlans()}>
                                            Browse plans
                                        </Link>
                                    </Button>
                                }
                            >
                                Pick a plan and we will set it up for you.
                            </EmptyState>
                        ) : (
                            <ul className="divide-y">
                                {live.map((subscription) => (
                                    <SubscriptionRow
                                        key={subscription.id}
                                        subscription={subscription}
                                    />
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <RequestHistory requests={requests} />

                    {past.length > 0 && (
                        <Panel>
                            <PanelHeader title="Past subscriptions" />
                            <ul className="divide-y text-sm">
                                {past.map((subscription) => (
                                    <li
                                        key={subscription.id}
                                        className="flex flex-wrap items-center gap-3 px-4 py-3"
                                    >
                                        <span className="min-w-0 flex-1">
                                            {subscription.plans
                                                .map((plan) => plan.name)
                                                .join(', ')}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDate(subscription.startedAt)}{' '}
                                            –{' '}
                                            {formatDate(
                                                subscription.cancelledAt,
                                            )}
                                        </span>
                                        <StatusBadge
                                            tone={
                                                subscriptionTone[
                                                    subscription.status
                                                ]
                                            }
                                        >
                                            {humanize(subscription.status)}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>

                <Panel className="self-start">
                    <PanelHeader
                        title="Applications you can use"
                        description="Every member's access is still set per person in the workspace."
                    />
                    {entitlements.length === 0 ? (
                        <EmptyState icon={AppWindow} title="None yet" />
                    ) : (
                        <ul className="divide-y text-sm">
                            {entitlements.map((entitlement) => (
                                <li
                                    key={entitlement.id}
                                    className="flex items-start gap-3 px-4 py-3"
                                >
                                    <AppWindow
                                        className="mt-0.5 size-4 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium">
                                            {entitlement.application}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {sourceLabel[entitlement.source] ??
                                                entitlement.source}
                                            {entitlement.validUntil &&
                                                ` · until ${formatDate(entitlement.validUntil)}`}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </>
    );
}

function SubscriptionRow({ subscription }: { subscription: Subscription }) {
    return (
        <li className="space-y-3 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge tone={subscriptionTone[subscription.status]}>
                    {humanize(subscription.status)}
                </StatusBadge>
                <span className="text-sm text-muted-foreground">
                    Since {formatDate(subscription.startedAt)}
                    {subscription.trialEndsAt &&
                        ` · trial ends ${formatDate(subscription.trialEndsAt)}`}
                    {subscription.renewsAt &&
                        ` · renews ${formatDate(subscription.renewsAt)}`}
                    {!subscription.renewsAt &&
                        subscription.periodEnd &&
                        ` · current period ends ${formatDate(subscription.periodEnd)}`}
                </span>
            </div>

            <ul className="space-y-1">
                {subscription.plans.map((plan) => (
                    <li
                        key={plan.name}
                        className="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <span className="font-medium">
                            {plan.name}
                            {plan.quantity > 1 && ` × ${plan.quantity}`}
                        </span>
                        <span className="text-sm text-muted-foreground tabular-nums">
                            {formatMoney(
                                plan.priceMinor * plan.quantity,
                                plan.currency,
                            )}{' '}
                            {intervalLabel[plan.interval] ?? plan.interval}
                        </span>
                    </li>
                ))}
            </ul>

            {subscription.cancellationRequestId ? (
                <p className="text-sm text-amber-700 dark:text-amber-300">
                    Cancellation requested. Your access continues until Miles
                    Global Softwares processes it.
                </p>
            ) : (
                <CancelDialog subscription={subscription} />
            )}
        </li>
    );
}

function CancelDialog({ subscription }: { subscription: Subscription }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="ghost" className="-ml-2">
                    Request cancellation
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    {...cancellation.form(subscription.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Request cancellation</DialogTitle>
                                <DialogDescription>
                                    We will confirm before anything changes.
                                    When the subscription ends, its applications
                                    stop working, but your data is kept.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="grid gap-2">
                                <Label htmlFor={`note-${subscription.id}`}>
                                    Anything we should know? (optional)
                                </Label>
                                <textarea
                                    id={`note-${subscription.id}`}
                                    name="note"
                                    rows={3}
                                    maxLength={1000}
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                                <InputError
                                    message={
                                        errors.note ??
                                        errors.request ??
                                        errors.subscription
                                    }
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Keep subscription
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Send request
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

OrganizationSubscription.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Subscription', href: organizationSubscription() },
    ],
};
