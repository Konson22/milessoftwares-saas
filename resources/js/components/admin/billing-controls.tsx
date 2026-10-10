import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export type Subscription = {
    id: string;
    status: string;
    trialEndsAt: string | null;
    startedAt: string;
    plans: string[];
};

type Entitlement = {
    id: string;
    application: string;
    feature_key: string | null;
    source: string;
    revoked_at: string | null;
};

type Props = {
    base: string;
    subscriptions: Subscription[];
    entitlements: Entitlement[];
    plans: { id: string; name: string; trial_days: number }[];
    applications: { id: string; name: string }[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';
const live = (status: string) =>
    ['active', 'trialing', 'past_due'].includes(status);

/**
 * Subscriptions and manual entitlements for staff with entitlements.manage.
 * Every change needs a reason, which goes into the organization's audit log.
 */
export default function BillingControls({
    base,
    subscriptions,
    entitlements,
    plans,
    applications,
}: Props) {
    return (
        <div className="space-y-6">
            <div className="space-y-3">
                <h2 className="text-lg font-medium">Subscriptions</h2>
                {subscriptions.length === 0 ? (
                    <p className="text-sm text-muted-foreground">None.</p>
                ) : (
                    <ul className="divide-y rounded-lg border text-sm">
                        {subscriptions.map((subscription) => (
                            <li key={subscription.id} className="space-y-2 p-3">
                                <div className="flex items-center gap-2">
                                    <Badge variant="outline">
                                        {subscription.status}
                                    </Badge>
                                    <span className="flex-1">
                                        {subscription.plans.join(', ')}
                                    </span>
                                    {subscription.trialEndsAt && (
                                        <span className="text-muted-foreground">
                                            trial until{' '}
                                            {subscription.trialEndsAt.slice(
                                                0,
                                                10,
                                            )}
                                        </span>
                                    )}
                                </div>
                                {live(subscription.status) && (
                                    <ReasonForm
                                        action={`${base}/subscriptions/${subscription.id}/cancel`}
                                        label="Cancel subscription"
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                {plans.length > 0 && (
                    <Form
                        action={`${base}/subscriptions`}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-2"
                    >
                        {({ errors, processing }) => (
                            <>
                                <div className="grid min-w-48 flex-1 gap-1.5">
                                    <Label htmlFor="plan_id">Assign plan</Label>
                                    <select
                                        id="plan_id"
                                        name="plan_id"
                                        className={selectClass}
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose a plan
                                        </option>
                                        {plans.map((plan) => (
                                            <option
                                                key={plan.id}
                                                value={plan.id}
                                            >
                                                {plan.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.plan_id} />
                                </div>
                                <label className="flex h-9 items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        name="trial"
                                        value="1"
                                    />{' '}
                                    Start with trial
                                </label>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Assign
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>

            <div className="space-y-3">
                <h2 className="text-lg font-medium">Grant access manually</h2>
                <Form
                    action={`${base}/entitlements`}
                    method="post"
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="space-y-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid grid-cols-2 gap-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="application_id">
                                        Application
                                    </Label>
                                    <select
                                        id="application_id"
                                        name="application_id"
                                        className={selectClass}
                                        required
                                        defaultValue=""
                                    >
                                        <option value="" disabled>
                                            Choose
                                        </option>
                                        {applications.map((application) => (
                                            <option
                                                key={application.id}
                                                value={application.id}
                                            >
                                                {application.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.application_id}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="valid_until">
                                        Until (optional)
                                    </Label>
                                    <Input
                                        id="valid_until"
                                        name="valid_until"
                                        type="date"
                                    />
                                    <InputError message={errors.valid_until} />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="grant-reason">Reason</Label>
                                <Input
                                    id="grant-reason"
                                    name="reason"
                                    required
                                />
                                <InputError message={errors.reason} />
                            </div>
                            <Button
                                type="submit"
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                Grant
                            </Button>
                        </>
                    )}
                </Form>

                {entitlements.some(
                    (entitlement) => entitlement.revoked_at === null,
                ) && (
                    <ul className="divide-y rounded-lg border text-sm">
                        {entitlements
                            .filter(
                                (entitlement) =>
                                    entitlement.revoked_at === null,
                            )
                            .map((entitlement) => (
                                <li
                                    key={entitlement.id}
                                    className="space-y-2 p-3"
                                >
                                    <p>
                                        {entitlement.application}
                                        {entitlement.feature_key &&
                                            ` · ${entitlement.feature_key}`}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · {entitlement.source}
                                        </span>
                                    </p>
                                    <ReasonForm
                                        action={`${base}/entitlements/${entitlement.id}/revoke`}
                                        label="Revoke"
                                    />
                                </li>
                            ))}
                    </ul>
                )}
            </div>
        </div>
    );
}

function ReasonForm({ action, label }: { action: string; label: string }) {
    return (
        <Form
            action={action}
            method="post"
            options={{ preserveScroll: true }}
            className="flex gap-2"
        >
            {({ processing }) => (
                <>
                    <Input
                        name="reason"
                        placeholder="Reason"
                        required
                        aria-label={`Reason to ${label.toLowerCase()}`}
                        className="h-8"
                    />
                    <Button
                        type="submit"
                        size="sm"
                        variant="ghost"
                        disabled={processing}
                    >
                        {label}
                    </Button>
                </>
            )}
        </Form>
    );
}
