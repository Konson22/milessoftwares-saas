import { Head } from '@inertiajs/react';
import Heading from '@/shared/components/heading';
import { Badge } from '@/shared/components/ui/badge';
import { dashboard } from '@/routes';
import { subscription as organizationSubscription } from '@/routes/organization';

type Props = {
    organization: { displayName: string };
    subscriptions: {
        id: string;
        status: string;
        trialEndsAt: string | null;
        startedAt: string | null;
        plans: { name: string; price: string; status: string }[];
    }[];
    entitlements: {
        id: string;
        application: string;
        source: string;
        validUntil: string | null;
    }[];
};

export default function OrganizationSubscription({
    organization,
    subscriptions,
    entitlements,
}: Props) {
    return (
        <>
            <Head title={`${organization.displayName} subscription`} />

            <div className="max-w-2xl space-y-10">
                <Heading
                    title="Subscription"
                    description="Your plans and the applications they include. To change your plan, contact Miles Global Softwares."
                />

                <section className="space-y-3">
                    <h2 className="text-lg font-medium">Plans</h2>
                    {subscriptions.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No subscription yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {subscriptions.map((subscription) => (
                                <li
                                    key={subscription.id}
                                    className="space-y-1 p-3"
                                >
                                    <div className="flex items-center gap-2">
                                        <Badge
                                            variant={
                                                ['active', 'trialing'].includes(
                                                    subscription.status,
                                                )
                                                    ? 'secondary'
                                                    : 'outline'
                                            }
                                        >
                                            {subscription.status}
                                        </Badge>
                                        <span className="text-muted-foreground">
                                            since {subscription.startedAt}
                                        </span>
                                        {subscription.trialEndsAt && (
                                            <span className="text-muted-foreground">
                                                · trial ends{' '}
                                                {subscription.trialEndsAt}
                                            </span>
                                        )}
                                    </div>
                                    {subscription.plans.map((plan) => (
                                        <p key={plan.name}>
                                            <span className="font-medium">
                                                {plan.name}
                                            </span>{' '}
                                            <span className="text-muted-foreground">
                                                · {plan.price}
                                            </span>
                                        </p>
                                    ))}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3">
                    <h2 className="text-lg font-medium">
                        Applications you can use
                    </h2>
                    {entitlements.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            None yet.
                        </p>
                    ) : (
                        <ul className="divide-y rounded-lg border text-sm">
                            {entitlements.map((entitlement) => (
                                <li
                                    key={entitlement.id}
                                    className="flex items-center gap-2 p-3"
                                >
                                    <span className="flex-1 font-medium">
                                        {entitlement.application}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {entitlement.source === 'trial'
                                            ? 'trial'
                                            : entitlement.source === 'manual'
                                              ? 'granted'
                                              : 'plan'}
                                        {entitlement.validUntil &&
                                            ` · until ${entitlement.validUntil.slice(0, 10)}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

OrganizationSubscription.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Subscription', href: organizationSubscription() },
    ],
};
