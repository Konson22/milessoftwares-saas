import { Form, Head } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Plan = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    billingInterval: string;
    priceMinor: number;
    currency: string;
    trialDays: number;
    isPublic: boolean;
    status: string;
    applicationIds: string[];
    applications: string[];
};

type Props = {
    plans: Plan[];
    applications: { id: string; name: string }[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';

export default function PlansIndex({ plans, applications }: Props) {
    return (
        <>
            <Head title="Plans" />

            <h1 className="text-2xl font-semibold">Plans</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Prices are in the currency&apos;s minor unit (e.g. cents).
                Assigning an active plan to an organization grants its
                applications.
            </p>

            <div className="mt-8 grid gap-10 lg:grid-cols-[1fr_24rem]">
                <ul className="space-y-4">
                    {plans.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No plans yet.
                        </p>
                    )}
                    {plans.map((plan) => (
                        <li key={plan.id} className="rounded-lg border p-4">
                            <div className="flex flex-wrap items-center gap-2">
                                <p className="font-medium">{plan.name}</p>
                                <code className="text-xs text-muted-foreground">
                                    {plan.code}
                                </code>
                                <Badge
                                    variant={
                                        plan.status === 'active'
                                            ? 'secondary'
                                            : 'outline'
                                    }
                                >
                                    {plan.status}
                                </Badge>
                                {!plan.isPublic && (
                                    <Badge variant="outline">private</Badge>
                                )}
                            </div>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {plan.currency}{' '}
                                {(plan.priceMinor / 100).toFixed(2)} /{' '}
                                {plan.billingInterval}
                                {plan.trialDays > 0 &&
                                    ` · ${plan.trialDays}-day trial`}{' '}
                                · {plan.applications.join(', ')}
                            </p>
                            <details className="mt-3">
                                <summary className="cursor-pointer text-sm">
                                    Edit
                                </summary>
                                <PlanForm
                                    action={`/plans/${plan.id}`}
                                    method="put"
                                    plan={plan}
                                    applications={applications}
                                />
                            </details>
                        </li>
                    ))}
                </ul>

                <section>
                    <h2 className="text-lg font-medium">New plan</h2>
                    <PlanForm
                        action="/plans"
                        method="post"
                        applications={applications}
                    />
                </section>
            </div>
        </>
    );
}

function PlanForm({
    action,
    method,
    plan,
    applications,
}: {
    action: string;
    method: 'post' | 'put';
    plan?: Plan;
    applications: { id: string; name: string }[];
}) {
    const id = (field: string) => `${plan?.id ?? 'new'}-${field}`;

    return (
        <Form
            action={action}
            method={method}
            options={{ preserveScroll: true }}
            resetOnSuccess={!plan}
            className="mt-3 space-y-3"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('code')}>Code</Label>
                            <Input
                                id={id('code')}
                                name="code"
                                defaultValue={plan?.code}
                                required
                            />
                            <InputError message={errors.code} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('name')}>Name</Label>
                            <Input
                                id={id('name')}
                                name="name"
                                defaultValue={plan?.name}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('price')}>Price (minor)</Label>
                            <Input
                                id={id('price')}
                                name="price_minor"
                                type="number"
                                min={0}
                                defaultValue={plan?.priceMinor ?? 0}
                                required
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('currency')}>Currency</Label>
                            <Input
                                id={id('currency')}
                                name="currency"
                                maxLength={3}
                                defaultValue={plan?.currency ?? 'USD'}
                                required
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('interval')}>Billing</Label>
                            <select
                                id={id('interval')}
                                name="billing_interval"
                                defaultValue={
                                    plan?.billingInterval ?? 'monthly'
                                }
                                className={selectClass}
                            >
                                <option>monthly</option>
                                <option>yearly</option>
                                <option>custom</option>
                            </select>
                        </div>
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('trial')}>Trial days</Label>
                            <Input
                                id={id('trial')}
                                name="trial_days"
                                type="number"
                                min={0}
                                max={365}
                                defaultValue={plan?.trialDays ?? 0}
                                required
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor={id('status')}>Status</Label>
                            <select
                                id={id('status')}
                                name="status"
                                defaultValue={plan?.status ?? 'draft'}
                                className={selectClass}
                            >
                                <option>draft</option>
                                <option>active</option>
                                <option>retired</option>
                            </select>
                        </div>
                    </div>
                    <fieldset className="space-y-1">
                        <legend className="text-sm font-medium">
                            Applications
                        </legend>
                        {applications.map((application) => (
                            <label
                                key={application.id}
                                className="flex items-center gap-2 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    name="application_ids[]"
                                    value={application.id}
                                    defaultChecked={plan?.applicationIds.includes(
                                        application.id,
                                    )}
                                />
                                {application.name}
                            </label>
                        ))}
                        <InputError message={errors.application_ids} />
                    </fieldset>
                    <label className="flex items-center gap-2 text-sm">
                        <input type="hidden" name="is_public" value="0" />
                        <input
                            type="checkbox"
                            name="is_public"
                            value="1"
                            defaultChecked={plan?.isPublic ?? true}
                        />
                        Show on the public pricing page
                    </label>
                    <Button type="submit" size="sm" disabled={processing}>
                        {plan ? 'Save plan' : 'Create plan'}
                    </Button>
                </>
            )}
        </Form>
    );
}
