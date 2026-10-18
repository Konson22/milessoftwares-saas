import { Form, Head } from '@inertiajs/react';
import { Check, Package } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
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
import { plans as organizationPlans } from '@/routes/organization';
import { store as storeRequest } from '@/routes/organization/plan-requests';
import { RequestHistory } from '@/sites/central/components/request-history';
import type { PlanRequestRow } from '@/sites/central/components/request-history';
import { formatMoney, intervalLabel } from '@/shared/lib/billing';

type Plan = {
    id: string;
    name: string;
    description: string | null;
    interval: string;
    priceMinor: number;
    currency: string;
    trialDays: number;
    isBundle: boolean;
    applications: string[];
    subscribed: boolean;
    requestId: string | null;
};

type Props = {
    organization: { displayName: string };
    plans: Plan[];
    requests: PlanRequestRow[];
    salesEmail: string | null;
};

export default function OrganizationPlans({
    organization,
    plans,
    requests,
    salesEmail,
}: Props) {
    return (
        <>
            <Head title={`Plans for ${organization.displayName}`} />

            <PageHeader
                title="Plans"
                description="Choose what your organization needs. Send a request and Miles Global Softwares will set it up and send you an invoice."
            />

            <div className="space-y-3">
                {plans.length === 0 ? (
                    <Panel>
                        <EmptyState icon={Package} title="No plans on offer">
                            {salesEmail
                                ? `Write to ${salesEmail} and we will put together a plan for you.`
                                : 'Contact Miles Global Softwares and we will put together a plan for you.'}
                        </EmptyState>
                    </Panel>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {plans.map((plan) => (
                            <PlanCard key={plan.id} plan={plan} />
                        ))}
                    </ul>
                )}

                <RequestHistory requests={requests} />

                {salesEmail && plans.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Need something different, such as more branches or a
                        custom bundle? Write to{' '}
                        <a className="underline" href={`mailto:${salesEmail}`}>
                            {salesEmail}
                        </a>
                        .
                    </p>
                )}
            </div>
        </>
    );
}

function PlanCard({ plan }: { plan: Plan }) {
    return (
        <li className="flex flex-col rounded-lg border bg-card p-5">
            <div className="flex items-start justify-between gap-2">
                <h2 className="font-semibold">{plan.name}</h2>
                {plan.subscribed ? (
                    <StatusBadge tone="success">Your plan</StatusBadge>
                ) : plan.requestId ? (
                    <StatusBadge tone="warning">Requested</StatusBadge>
                ) : (
                    plan.isBundle && (
                        <StatusBadge tone="info">Bundle</StatusBadge>
                    )
                )}
            </div>

            <p className="mt-3">
                <span className="text-2xl font-semibold tracking-tight tabular-nums">
                    {formatMoney(plan.priceMinor, plan.currency)}
                </span>{' '}
                <span className="text-sm text-muted-foreground">
                    {intervalLabel[plan.interval] ?? plan.interval}
                </span>
            </p>

            {plan.description && (
                <p className="mt-2 text-sm text-muted-foreground">
                    {plan.description}
                </p>
            )}

            <ul className="mt-4 space-y-1.5 text-sm">
                {plan.applications.map((application) => (
                    <li key={application} className="flex items-center gap-2">
                        <Check
                            className="size-4 shrink-0 text-emerald-600"
                            aria-hidden
                        />
                        {application}
                    </li>
                ))}
                {plan.trialDays > 0 && (
                    <li className="flex items-center gap-2 text-muted-foreground">
                        <Check className="size-4 shrink-0" aria-hidden />
                        {plan.trialDays}-day free trial available
                    </li>
                )}
            </ul>

            <div className="mt-auto pt-5">
                {plan.subscribed ? (
                    <Button className="w-full" variant="outline" disabled>
                        Subscribed
                    </Button>
                ) : plan.requestId ? (
                    <Button className="w-full" variant="outline" disabled>
                        Request sent
                    </Button>
                ) : (
                    <RequestDialog plan={plan} />
                )}
            </div>
        </li>
    );
}

function RequestDialog({ plan }: { plan: Plan }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="w-full">Request this plan</Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    {...storeRequest.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Request {plan.name}</DialogTitle>
                                <DialogDescription>
                                    Nothing is charged yet. We will confirm the
                                    plan with you, switch it on and send an
                                    invoice for{' '}
                                    {formatMoney(
                                        plan.priceMinor,
                                        plan.currency,
                                    )}{' '}
                                    {intervalLabel[plan.interval] ??
                                        plan.interval}
                                    .
                                </DialogDescription>
                            </DialogHeader>
                            <input
                                type="hidden"
                                name="plan_id"
                                value={plan.id}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor={`note-${plan.id}`}>
                                    Notes for our team (optional)
                                </Label>
                                <textarea
                                    id={`note-${plan.id}`}
                                    name="note"
                                    rows={3}
                                    maxLength={1000}
                                    placeholder="e.g. number of branches, when you want to start"
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                />
                                <InputError
                                    message={
                                        errors.note ??
                                        errors.plan_id ??
                                        errors.request
                                    }
                                />
                            </div>
                            <DialogFooter>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
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

OrganizationPlans.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Plans', href: organizationPlans() },
    ],
};
