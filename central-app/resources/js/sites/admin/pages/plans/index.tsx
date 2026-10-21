import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Plan = {
    id: string;
    productId: string | null;
    product: string | null;
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
    features: {
        key: string;
        applicationId: string | null;
        limit: number | null;
    }[];
};

/** A feature a product declares in its current catalog. */
type Feature = {
    key: string;
    name: string;
    description: string | null;
    status: 'available' | 'beta' | 'deprecated';
    limitUnit: string | null;
    applicationId: string;
};

type ProductOption = { id: string; name: string; status: string };

type Props = {
    plans: Plan[];
    applications: { id: string; name: string }[];
    products: ProductOption[];
    features: Feature[];
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';

export default function PlansIndex({
    plans,
    applications,
    products,
    features,
}: Props) {
    return (
        <>
            <Head title="Plans" />

            <h1 className="text-2xl font-semibold">Plans</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Prices are in the currency&apos;s minor unit (e.g. cents).
                Assigning an active plan to an organization grants its
                applications and features. Feature changes reach organizations
                already on the plan; staff overrides stay.
            </p>

            <div className="mt-5 grid gap-5 lg:grid-cols-[1fr_24rem]">
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
                                {plan.product && ` · ${plan.product}`}
                                {plan.trialDays > 0 &&
                                    ` · ${plan.trialDays}-day trial`}{' '}
                                · {plan.applications.join(', ')}
                            </p>
                            {plan.features.length > 0 && (
                                <div className="mt-2 flex flex-wrap gap-1">
                                    {plan.features.map((feature) => (
                                        <Badge
                                            key={feature.key}
                                            variant="outline"
                                        >
                                            {feature.key}
                                            {feature.limit !== null &&
                                                ` ≤ ${feature.limit}`}
                                        </Badge>
                                    ))}
                                </div>
                            )}
                            <details className="mt-3">
                                <summary className="cursor-pointer text-sm">
                                    Edit
                                </summary>
                                <PlanForm
                                    action={`/plans/${plan.id}`}
                                    method="put"
                                    plan={plan}
                                    applications={applications}
                                    products={products}
                                    features={features}
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
                        products={products}
                        features={features}
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
    products,
    features,
}: {
    action: string;
    method: 'post' | 'put';
    plan?: Plan;
    applications: { id: string; name: string }[];
    products: ProductOption[];
    features: Feature[];
}) {
    const id = (field: string) => `${plan?.id ?? 'new'}-${field}`;
    const [applicationIds, setApplicationIds] = useState<string[]>(
        plan?.applicationIds ?? [],
    );
    // Chosen feature key => limit as typed ('' = no limit).
    const [chosen, setChosen] = useState<Record<string, string>>(() =>
        Object.fromEntries(
            (plan?.features ?? []).map((feature) => [
                feature.key,
                feature.limit === null ? '' : String(feature.limit),
            ]),
        ),
    );
    // Features already on the plan stay listed after their product drops them.
    const offered: Feature[] = [
        ...features.filter(
            (feature) =>
                feature.status !== 'deprecated' || feature.key in chosen,
        ),
        ...(plan?.features ?? [])
            .filter(
                (feature) =>
                    !features.some((declared) => declared.key === feature.key),
            )
            .map((feature) => ({
                key: feature.key,
                name: `${feature.key} (no longer in the catalog)`,
                description: null,
                status: 'deprecated' as const,
                limitUnit: null,
                applicationId: feature.applicationId ?? '',
            })),
    ].filter((feature) => applicationIds.includes(feature.applicationId));
    // Only features of applications still on the plan are sent.
    const submitted = offered.filter((feature) => feature.key in chosen);
    const toggle = (key: string, on: boolean) =>
        setChosen((current) => {
            const next = { ...current };

            if (on) {
                next[key] = '';
            } else {
                delete next[key];
            }

            return next;
        });

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
                                maxLength={64}
                                pattern="[a-z][a-z0-9_\-]*"
                                title="Lowercase letters, digits, - and _, starting with a letter"
                                placeholder="finance-standard"
                                aria-describedby={id('code-hint')}
                            />
                            <p
                                id={id('code-hint')}
                                className="text-xs text-muted-foreground"
                            >
                                Lowercase letters, digits, - and _; starts with
                                a letter.
                            </p>
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
                    <div className="grid gap-1.5">
                        <Label htmlFor={id('product')}>ERP product</Label>
                        <select
                            id={id('product')}
                            name="erp_product_id"
                            defaultValue={plan?.productId ?? ''}
                            className={selectClass}
                        >
                            <option value="">None (applications only)</option>
                            {products.map((product) => (
                                <option key={product.id} value={product.id}>
                                    {product.name} ({product.status})
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.erp_product_id} />
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
                                    checked={applicationIds.includes(
                                        application.id,
                                    )}
                                    onChange={(event) =>
                                        setApplicationIds((current) =>
                                            event.target.checked
                                                ? [...current, application.id]
                                                : current.filter(
                                                      (value) =>
                                                          value !==
                                                          application.id,
                                                  ),
                                        )
                                    }
                                />
                                {application.name}
                            </label>
                        ))}
                        <InputError message={errors.application_ids} />
                    </fieldset>
                    <fieldset className="space-y-1">
                        <legend className="text-sm font-medium">
                            Features
                        </legend>
                        <input type="hidden" name="manage_features" value="1" />
                        {submitted.map((feature, index) => (
                            <input
                                key={feature.key}
                                type="hidden"
                                name={`features[${index}][key]`}
                                value={feature.key}
                            />
                        ))}
                        {offered.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                The chosen applications declare no features.
                            </p>
                        )}
                        {offered.map((feature) => {
                            const index = submitted.indexOf(feature);

                            return (
                                <div
                                    key={feature.key}
                                    className="flex flex-wrap items-center gap-2 text-sm"
                                >
                                    <label
                                        className="flex flex-1 items-center gap-2"
                                        title={feature.description ?? undefined}
                                    >
                                        <input
                                            type="checkbox"
                                            checked={index >= 0}
                                            onChange={(event) =>
                                                toggle(
                                                    feature.key,
                                                    event.target.checked,
                                                )
                                            }
                                        />
                                        {feature.name}
                                        {feature.status === 'beta' && (
                                            <Badge variant="outline">
                                                beta
                                            </Badge>
                                        )}
                                    </label>
                                    {index >= 0 && feature.limitUnit && (
                                        <Input
                                            name={`features[${index}][limit]`}
                                            type="number"
                                            min={0}
                                            placeholder={`No limit (${feature.limitUnit})`}
                                            aria-label={`${feature.name} limit`}
                                            value={chosen[feature.key]}
                                            onChange={(event) =>
                                                setChosen((current) => ({
                                                    ...current,
                                                    [feature.key]:
                                                        event.target.value,
                                                }))
                                            }
                                            className="h-8 w-40"
                                        />
                                    )}
                                </div>
                            );
                        })}
                        <InputError
                            message={
                                Object.entries(errors).find(([field]) =>
                                    field.startsWith('features'),
                                )?.[1]
                            }
                        />
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
