import { Form, Head, Link } from '@inertiajs/react';
import BillingControls from '@/sites/admin/components/billing-controls';
import type { Subscription } from '@/sites/admin/components/billing-controls';
import FeatureControls from '@/sites/admin/components/feature-controls';
import type { OrganizationFeature } from '@/sites/admin/components/feature-controls';
import {
    InvoiceControls,
    PlanRequestControls,
} from '@/sites/admin/components/invoice-controls';
import type { OrganizationBilling } from '@/sites/admin/components/invoice-controls';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Props = {
    organization: {
        id: string;
        displayName: string;
        legalName: string;
        slug: string | null;
        status: string;
        countryCode: string;
        currency: string;
        createdAt: string | null;
        suspendedAt: string | null;
    };
    entitlements: {
        id: string;
        application: string;
        feature_key: string | null;
        source: string;
        effect: 'grant' | 'deny';
        reason: string | null;
        created_by: string | null;
        valid_from: string;
        valid_until: string | null;
        revoked_at: string | null;
    }[];
    features: OrganizationFeature[];
    supportGrants: {
        id: string;
        requestedBy: string;
        approvedBy: string | null;
        reason: string;
        scope: string;
        status: string;
        expiresAt: string;
    }[];
    subscriptions: Subscription[];
    billing: OrganizationBilling;
    plans: { id: string; name: string; trial_days: number }[];
    applications: { id: string; name: string }[];
    activeGrant: { id: string; expiresAt: string } | null;
    can: {
        suspend: boolean;
        requestSupport: boolean;
        manageEntitlements: boolean;
        manageBilling: boolean;
    };
};

const formatDate = (iso: string) => new Date(iso).toLocaleString();

export default function OrganizationShow({
    organization,
    entitlements,
    features,
    supportGrants,
    subscriptions,
    billing,
    plans,
    applications,
    activeGrant,
    can,
}: Props) {
    const base = `/organizations/${organization.id}`;

    return (
        <>
            <Head title={organization.displayName} />

            <Link
                href="/organizations"
                className="text-sm text-muted-foreground underline"
            >
                All organizations
            </Link>

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <h1 className="text-2xl font-semibold">
                    {organization.displayName}
                </h1>
                <Badge
                    variant={
                        organization.status === 'active'
                            ? 'secondary'
                            : 'destructive'
                    }
                >
                    {organization.status}
                </Badge>
            </div>
            <p className="text-sm text-muted-foreground">
                {organization.legalName} · {organization.slug} ·{' '}
                {organization.countryCode} · {organization.currency} · created{' '}
                {organization.createdAt}
            </p>

            <div className="mt-5 grid gap-5 lg:grid-cols-2">
                <section className="space-y-4">
                    <h2 className="text-lg font-medium">Customer data</h2>
                    {activeGrant ? (
                        <div className="space-y-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10">
                            <p>
                                Your support access is in effect until{' '}
                                {formatDate(activeGrant.expiresAt)}. Every view
                                is recorded.
                            </p>
                            <div className="flex gap-2">
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`${base}/customer/members`}>
                                        Members
                                    </Link>
                                </Button>
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`${base}/customer/structure`}>
                                        Structure
                                    </Link>
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Hidden. Request support access below; another staff
                            member must approve it.
                        </p>
                    )}

                    {can.requestSupport && !activeGrant && (
                        <Form
                            action={`${base}/support-access`}
                            method="post"
                            resetOnSuccess
                            className="space-y-3 rounded-lg border p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="reason">Reason</Label>
                                        <Input
                                            id="reason"
                                            name="reason"
                                            required
                                            minLength={10}
                                        />
                                        <InputError message={errors.reason} />
                                    </div>
                                    <div className="grid grid-cols-3 gap-2">
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="ticket_reference">
                                                Ticket
                                            </Label>
                                            <Input
                                                id="ticket_reference"
                                                name="ticket_reference"
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="scope">
                                                Access
                                            </Label>
                                            <select
                                                id="scope"
                                                name="scope"
                                                className="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                                            >
                                                <option value="read_only">
                                                    View only
                                                </option>
                                                <option value="configuration">
                                                    Configuration
                                                </option>
                                            </select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label htmlFor="hours">
                                                Hours (max 72)
                                            </Label>
                                            <Input
                                                id="hours"
                                                name="hours"
                                                type="number"
                                                min={1}
                                                max={72}
                                                defaultValue={4}
                                                required
                                            />
                                            <InputError
                                                message={errors.hours}
                                            />
                                        </div>
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Request support access
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}

                    {supportGrants.length > 0 && (
                        <ul className="divide-y rounded-lg border text-sm">
                            {supportGrants.map((grant) => (
                                <li key={grant.id} className="p-3">
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">
                                            {grant.status}
                                        </Badge>
                                        <span>
                                            {grant.requestedBy}
                                            {grant.approvedBy &&
                                                `, approved by ${grant.approvedBy}`}
                                        </span>
                                    </div>
                                    <p className="mt-1 text-muted-foreground">
                                        {grant.reason} · {grant.scope} · until{' '}
                                        {formatDate(grant.expiresAt)}
                                    </p>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-3">
                    <div className="space-y-3">
                        <h2 className="text-lg font-medium">Entitlements</h2>
                        {entitlements.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                None.
                            </p>
                        ) : (
                            <ul className="divide-y rounded-lg border text-sm">
                                {entitlements.map((entitlement) => (
                                    <li
                                        key={entitlement.id}
                                        className="flex items-center gap-2 p-3"
                                    >
                                        <span className="flex-1">
                                            {entitlement.application}
                                            {entitlement.feature_key &&
                                                ` · ${entitlement.feature_key}`}
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {entitlement.source}
                                                {entitlement.effect ===
                                                    'deny' && ' denial'}
                                                {entitlement.created_by &&
                                                    ` · ${entitlement.created_by}`}
                                                {entitlement.reason &&
                                                    ` · ${entitlement.reason}`}
                                            </span>
                                        </span>
                                        {entitlement.revoked_at ? (
                                            <Badge variant="outline">
                                                revoked
                                            </Badge>
                                        ) : (
                                            entitlement.valid_until && (
                                                <span className="text-muted-foreground">
                                                    until{' '}
                                                    {entitlement.valid_until.slice(
                                                        0,
                                                        10,
                                                    )}
                                                </span>
                                            )
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <FeatureControls
                        base={base}
                        features={features}
                        canManage={can.manageEntitlements}
                    />

                    {can.manageEntitlements && (
                        <BillingControls
                            base={base}
                            subscriptions={subscriptions}
                            entitlements={entitlements}
                            plans={plans}
                            applications={applications}
                        />
                    )}

                    {can.manageEntitlements && (
                        <PlanRequestControls
                            base={base}
                            requests={billing.requests}
                        />
                    )}

                    {can.manageBilling && (
                        <InvoiceControls
                            base={base}
                            billing={billing}
                            subscriptions={subscriptions}
                            currency={organization.currency}
                        />
                    )}

                    {can.suspend && (
                        <Form
                            action={`${base}/status`}
                            method="put"
                            className="space-y-3 rounded-lg border p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="status"
                                        value={
                                            organization.status === 'suspended'
                                                ? 'active'
                                                : 'suspended'
                                        }
                                    />
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="status-reason">
                                            Reason
                                        </Label>
                                        <Input
                                            id="status-reason"
                                            name="reason"
                                            required
                                        />
                                        <InputError message={errors.reason} />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant={
                                            organization.status === 'suspended'
                                                ? 'outline'
                                                : 'destructive'
                                        }
                                        disabled={processing}
                                    >
                                        {organization.status === 'suspended'
                                            ? 'Reactivate organization'
                                            : 'Suspend organization'}
                                    </Button>
                                </>
                            )}
                        </Form>
                    )}
                </section>
            </div>
        </>
    );
}
