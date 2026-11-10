import { Form, Head } from '@inertiajs/react';
import { Check, CreditCard, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/shared/components/input-error';
import { Button } from '@/shared/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/shared/components/ui/dialog';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';
import { formatMoney, intervalLabel } from '@/shared/lib/billing';
import { cn } from '@/shared/lib/utils';

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

type ApplicationOption = { id: string; name: string };

type Props = {
    plans: Plan[];
    applications: ApplicationOption[];
    products: ProductOption[];
    features: Feature[];
};

type StatusFilter = 'all' | 'active' | 'draft' | 'retired';

const statusStyles: Record<
    string,
    { label: string; pill: string; dot: string }
> = {
    active: {
        label: 'Active',
        pill: 'bg-primary text-primary-foreground',
        dot: 'bg-primary-foreground',
    },
    draft: {
        label: 'Draft',
        pill: 'bg-muted text-muted-foreground ring-1 ring-border',
        dot: 'bg-muted-foreground/60',
    },
    retired: {
        label: 'Retired',
        pill: 'bg-muted text-muted-foreground line-through',
        dot: 'bg-muted-foreground/40',
    },
};

const filters: { key: StatusFilter; label: string }[] = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'draft', label: 'Draft' },
    { key: 'retired', label: 'Retired' },
];

const selectClass =
    'border-input h-8 w-full rounded-md border bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'border-input placeholder:text-muted-foreground min-h-16 w-full rounded-md border bg-transparent px-2.5 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function statusOf(status: string) {
    return (
        statusStyles[status] ?? {
            label: status,
            pill: 'bg-muted text-muted-foreground ring-1 ring-border',
            dot: 'bg-muted-foreground/60',
        }
    );
}

function priceLabel(plan: Pick<Plan, 'priceMinor' | 'currency'>) {
    return plan.priceMinor === 0
        ? 'Free'
        : formatMoney(plan.priceMinor, plan.currency);
}

function featureName(key: string, catalog: Feature[]) {
    return catalog.find((feature) => feature.key === key)?.name ?? key;
}

export default function PlansIndex({
    plans,
    applications,
    products,
    features,
}: Props) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [open, setOpen] = useState(false);
    const [plan, setPlan] = useState<Plan | undefined>(undefined);
    const [session, setSession] = useState(0);

    const counts = {
        all: plans.length,
        active: plans.filter((item) => item.status === 'active').length,
        draft: plans.filter((item) => item.status === 'draft').length,
        retired: plans.filter((item) => item.status === 'retired').length,
    };
    const needle = query.trim().toLowerCase();
    const visible = plans.filter((item) => {
        if (status !== 'all' && item.status !== status) {
            return false;
        }

        if (needle === '') {
            return true;
        }

        return [item.name, item.code, item.product, ...item.applications]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(needle);
    });

    const openEditor = (next?: Plan) => {
        setPlan(next);
        setSession((current) => current + 1);
        setOpen(true);
    };

    return (
        <>
            <Head title="Plans" />

            <div className="flex flex-wrap items-end justify-between gap-4">
                <div className="max-w-2xl">
                    <h1 className="text-2xl font-semibold">Plans</h1>
                    <p className="mt-1 text-sm text-muted-foreground">
                        What an organization can buy. An active plan grants its
                        applications and features. Later feature changes follow
                        the plan; staff overrides stay.
                    </p>
                </div>
                {plans.length > 0 && (
                    <div className="flex flex-wrap items-end gap-2">
                        <dl className="flex gap-2">
                            {(
                                [
                                    ['active', 'Active'],
                                    ['draft', 'Draft'],
                                    ['retired', 'Retired'],
                                ] as const
                            )
                                .filter(([key]) => counts[key] > 0)
                                .map(([key, label]) => (
                                    <div
                                        key={key}
                                        className="min-w-20 rounded-lg border bg-card px-3 py-2"
                                    >
                                        <dt className="text-xs text-muted-foreground">
                                            {label}
                                        </dt>
                                        <dd className="text-xl font-semibold tabular-nums">
                                            {counts[key]}
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                        <Button onClick={() => openEditor()}>
                            <Plus />
                            New plan
                        </Button>
                    </div>
                )}
            </div>

            {plans.length === 0 ? (
                <div className="mt-6 rounded-xl border border-dashed bg-card px-6 py-16 text-center">
                    <div className="mx-auto flex size-12 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <CreditCard className="size-5" aria-hidden />
                    </div>
                    <h2 className="mt-4 font-semibold">No plans yet</h2>
                    <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                        Create a plan to sell an ERP product, or a set of
                        applications on their own.
                    </p>
                    <Button className="mt-4" onClick={() => openEditor()}>
                        <Plus />
                        New plan
                    </Button>
                </div>
            ) : (
                <>
                    <div className="mt-5 flex flex-wrap items-center gap-2">
                        <div
                            className="flex rounded-lg border bg-card p-1"
                            role="group"
                            aria-label="Filter by status"
                        >
                            {filters.map((filter) => (
                                <button
                                    key={filter.key}
                                    type="button"
                                    aria-pressed={status === filter.key}
                                    onClick={() => setStatus(filter.key)}
                                    className={cn(
                                        'rounded-md px-3 py-1 text-sm',
                                        status === filter.key
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {filter.label}
                                    <span className="ml-1.5 tabular-nums opacity-70">
                                        {counts[filter.key]}
                                    </span>
                                </button>
                            ))}
                        </div>
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search name, code, or product"
                            aria-label="Search plans"
                            className="h-9 w-full sm:w-64"
                        />
                    </div>

                    {visible.length === 0 ? (
                        <p className="mt-6 rounded-xl border border-dashed px-6 py-10 text-center text-sm text-muted-foreground">
                            No plans match this view.
                        </p>
                    ) : (
                        <ul className="mt-5 grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                            {visible.map((item) => (
                                <PlanCard
                                    key={item.id}
                                    plan={item}
                                    features={features}
                                    onEdit={() => openEditor(item)}
                                />
                            ))}
                        </ul>
                    )}
                </>
            )}

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="flex max-h-[min(44rem,calc(100dvh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-2xl">
                    <PlanForm
                        key={`${plan?.id ?? 'create'}-${session}`}
                        action={plan ? `/plans/${plan.id}` : '/plans'}
                        method={plan ? 'put' : 'post'}
                        plan={plan}
                        applications={applications}
                        products={products}
                        features={features}
                        onDone={() => setOpen(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}

function PlanCard({
    plan,
    features,
    onEdit,
}: {
    plan: Plan;
    features: Feature[];
    onEdit: () => void;
}) {
    const status = statusOf(plan.status);

    return (
        <li
            className={cn(
                'flex flex-col rounded-xl border bg-card shadow-xs transition-shadow hover:shadow-sm',
                plan.status === 'retired' && 'opacity-60',
            )}
        >
            <div className="flex items-start gap-3 p-5">
                <div className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <CreditCard className="size-5" aria-hidden />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <h2 className="text-base leading-tight font-semibold">
                            {plan.name}
                        </h2>
                        <span
                            className={cn(
                                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium',
                                status.pill,
                            )}
                        >
                            <span
                                className={cn(
                                    'size-1.5 rounded-full',
                                    status.dot,
                                )}
                                aria-hidden
                            />
                            {status.label}
                        </span>
                    </div>
                    <code className="mt-0.5 block truncate text-xs text-muted-foreground">
                        {plan.code}
                    </code>
                </div>
            </div>

            <div className="border-t px-5 py-4">
                <p className="flex flex-wrap items-baseline gap-x-2">
                    <span className="text-2xl font-semibold tracking-tight tabular-nums">
                        {priceLabel(plan)}
                    </span>
                    {plan.priceMinor > 0 && (
                        <span className="text-sm text-muted-foreground">
                            {intervalLabel[plan.billingInterval] ??
                                plan.billingInterval}
                        </span>
                    )}
                </p>
                <div className="mt-3 flex flex-wrap gap-1.5">
                    <span
                        className={cn(
                            'rounded-md px-2 py-0.5 text-xs font-medium',
                            plan.product
                                ? 'bg-primary/10 text-primary'
                                : 'bg-muted text-muted-foreground',
                        )}
                    >
                        {plan.product ?? 'Applications only'}
                    </span>
                    {plan.applications.length > 1 && (
                        <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            Bundle
                        </span>
                    )}
                    <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                        {plan.isPublic ? 'Public' : 'Private'}
                    </span>
                    {plan.trialDays > 0 && (
                        <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            {plan.trialDays}-day trial
                        </span>
                    )}
                </div>
                {plan.description && (
                    <p className="mt-3 line-clamp-2 text-sm text-muted-foreground">
                        {plan.description}
                    </p>
                )}
            </div>

            <div className="grid flex-1 gap-4 border-t p-5 text-sm sm:grid-cols-2">
                <section>
                    <h3 className="text-xs font-medium text-muted-foreground">
                        Applications
                    </h3>
                    <ul className="mt-2 space-y-1.5">
                        {plan.applications.map((application) => (
                            <li
                                key={application}
                                className="flex items-center gap-2"
                            >
                                <Check
                                    className="size-3.5 shrink-0 text-primary"
                                    aria-hidden
                                />
                                <span className="min-w-0 truncate">
                                    {application}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
                <section>
                    <h3 className="text-xs font-medium text-muted-foreground">
                        Features
                    </h3>
                    {plan.features.length === 0 ? (
                        <p className="mt-2 text-muted-foreground">
                            None on this plan.
                        </p>
                    ) : (
                        <ul className="mt-2 flex flex-wrap gap-1">
                            {plan.features.map((feature) => (
                                <li
                                    key={feature.key}
                                    className="rounded-md bg-muted px-1.5 py-0.5 text-xs"
                                    title={feature.key}
                                >
                                    {featureName(feature.key, features)}
                                    {feature.limit !== null &&
                                        ` ≤ ${feature.limit}`}
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>

            <div className="border-t px-5 py-3">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={onEdit}
                    aria-label={`Edit ${plan.name}`}
                >
                    <Pencil />
                    Edit
                </Button>
            </div>
        </li>
    );
}

function PlanForm({
    action,
    method,
    plan,
    applications,
    products,
    features,
    onDone,
}: {
    action: string;
    method: 'post' | 'put';
    plan?: Plan;
    applications: ApplicationOption[];
    products: ProductOption[];
    features: Feature[];
    onDone: () => void;
}) {
    const id = (field: string) => `${plan?.id ?? 'new'}-${field}`;
    const [applicationIds, setApplicationIds] = useState<string[]>(
        plan?.applicationIds ?? [],
    );
    const [price, setPrice] = useState(String(plan?.priceMinor ?? 0));
    const [currency, setCurrency] = useState(plan?.currency ?? 'USD');
    const [interval, setInterval] = useState(
        plan?.billingInterval ?? 'monthly',
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
    const amount = Number(price);
    const preview =
        price.trim() === '' || Number.isNaN(amount)
            ? '—'
            : amount === 0
              ? 'Free'
              : `${formatMoney(amount, currency)} ${intervalLabel[interval] ?? interval}`;
    const knownApplications = new Set(
        applications.map((application) => application.id),
    );
    const groups = [
        ...applications
            .filter((application) =>
                offered.some(
                    (feature) => feature.applicationId === application.id,
                ),
            )
            .map((application) => ({
                id: application.id,
                name: application.name,
                features: offered.filter(
                    (feature) => feature.applicationId === application.id,
                ),
            })),
        ...(() => {
            const stray = offered.filter(
                (feature) => !knownApplications.has(feature.applicationId),
            );

            return stray.length === 0
                ? []
                : [
                      {
                          id: 'other',
                          name: 'No longer in the catalog',
                          features: stray,
                      },
                  ];
        })(),
    ];

    return (
        <Form
            action={action}
            method={method}
            options={{ preserveScroll: true }}
            resetOnSuccess={!plan}
            onSuccess={onDone}
            className="flex min-h-0 flex-1 flex-col"
        >
            {({ errors, processing }) => (
                <>
                    <DialogHeader className="shrink-0 space-y-1 border-b px-6 py-5 pr-12 text-left">
                        <DialogTitle>
                            {plan ? `Edit ${plan.name}` : 'New plan'}
                        </DialogTitle>
                        <DialogDescription>
                            Customers see {preview}. Prices are entered in the
                            currency&apos;s minor unit, such as cents.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Identity</h3>
                            <div className="grid gap-3 sm:grid-cols-2">
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
                                        Lowercase letters, digits, - and _;
                                        starts with a letter.
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
                                        placeholder="Finance Standard"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('description')}>
                                    Description
                                </Label>
                                <textarea
                                    id={id('description')}
                                    name="description"
                                    rows={2}
                                    maxLength={2000}
                                    defaultValue={plan?.description ?? ''}
                                    placeholder="Shown on the pricing page"
                                    className={textareaClass}
                                />
                                <InputError message={errors.description} />
                            </div>
                        </section>

                        <section className="space-y-3 border-t pt-6">
                            <h3 className="text-sm font-medium">Price</h3>
                            <div className="grid gap-3 sm:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor={id('price')}>
                                        Amount (minor)
                                    </Label>
                                    <Input
                                        id={id('price')}
                                        name="price_minor"
                                        type="number"
                                        min={0}
                                        value={price}
                                        onChange={(event) =>
                                            setPrice(event.target.value)
                                        }
                                        required
                                    />
                                    <InputError message={errors.price_minor} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={id('currency')}>
                                        Currency
                                    </Label>
                                    <Input
                                        id={id('currency')}
                                        name="currency"
                                        maxLength={3}
                                        value={currency}
                                        onChange={(event) =>
                                            setCurrency(
                                                event.target.value.toUpperCase(),
                                            )
                                        }
                                        required
                                    />
                                    <InputError message={errors.currency} />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={id('interval')}>
                                        Billing
                                    </Label>
                                    <select
                                        id={id('interval')}
                                        name="billing_interval"
                                        value={interval}
                                        onChange={(event) =>
                                            setInterval(event.target.value)
                                        }
                                        className={selectClass}
                                    >
                                        <option value="monthly">Monthly</option>
                                        <option value="yearly">Yearly</option>
                                        <option value="custom">Custom</option>
                                    </select>
                                    <InputError
                                        message={errors.billing_interval}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor={id('trial')}>
                                        Trial days
                                    </Label>
                                    <Input
                                        id={id('trial')}
                                        name="trial_days"
                                        type="number"
                                        min={0}
                                        max={365}
                                        defaultValue={plan?.trialDays ?? 0}
                                        required
                                    />
                                    <InputError message={errors.trial_days} />
                                </div>
                            </div>
                        </section>

                        <section className="space-y-3 border-t pt-6">
                            <h3 className="text-sm font-medium">Catalog</h3>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="grid gap-1.5">
                                    <Label htmlFor={id('product')}>
                                        ERP product
                                    </Label>
                                    <select
                                        id={id('product')}
                                        name="erp_product_id"
                                        defaultValue={plan?.productId ?? ''}
                                        className={selectClass}
                                    >
                                        <option value="">
                                            None (applications only)
                                        </option>
                                        {products.map((product) => (
                                            <option
                                                key={product.id}
                                                value={product.id}
                                            >
                                                {product.name} ({product.status}
                                                )
                                            </option>
                                        ))}
                                    </select>
                                    <InputError
                                        message={errors.erp_product_id}
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
                                        <option value="draft">Draft</option>
                                        <option value="active">Active</option>
                                        <option value="retired">Retired</option>
                                    </select>
                                    <InputError message={errors.status} />
                                </div>
                            </div>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="is_public"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="is_public"
                                    value="1"
                                    defaultChecked={plan?.isPublic ?? true}
                                    className="mt-0.5 size-4 accent-primary"
                                />
                                <span>
                                    Show on the public pricing page
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        Private plans can still be assigned to
                                        an organization.
                                    </span>
                                </span>
                            </label>
                        </section>

                        <section className="space-y-3 border-t pt-6">
                            <h3 className="text-sm font-medium">
                                Applications
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                Choose at least one. Features below follow the
                                applications on the plan.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {applications.map((application) => {
                                    const checked = applicationIds.includes(
                                        application.id,
                                    );

                                    return (
                                        <label
                                            key={application.id}
                                            className={cn(
                                                'flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring',
                                                checked
                                                    ? 'border-primary bg-primary/10 font-medium text-primary'
                                                    : 'hover:bg-muted/60',
                                            )}
                                        >
                                            <input
                                                type="checkbox"
                                                name="application_ids[]"
                                                value={application.id}
                                                checked={checked}
                                                onChange={(event) =>
                                                    setApplicationIds(
                                                        (current) =>
                                                            event.target.checked
                                                                ? [
                                                                      ...current,
                                                                      application.id,
                                                                  ]
                                                                : current.filter(
                                                                      (value) =>
                                                                          value !==
                                                                          application.id,
                                                                  ),
                                                    )
                                                }
                                                className="size-3.5 accent-primary"
                                            />
                                            {application.name}
                                        </label>
                                    );
                                })}
                            </div>
                            <InputError message={errors.application_ids} />
                        </section>

                        <section className="space-y-3 border-t pt-6">
                            <h3 className="text-sm font-medium">Features</h3>
                            <input
                                type="hidden"
                                name="manage_features"
                                value="1"
                            />
                            {submitted.map((feature, index) => (
                                <input
                                    key={feature.key}
                                    type="hidden"
                                    name={`features[${index}][key]`}
                                    value={feature.key}
                                />
                            ))}
                            {applicationIds.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Choose an application to see its features.
                                </p>
                            )}
                            {applicationIds.length > 0 &&
                                offered.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        The chosen applications declare no
                                        features.
                                    </p>
                                )}
                            {groups.map((group) => (
                                <div key={group.id} className="space-y-2">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        {group.name}
                                    </p>
                                    <ul className="space-y-2">
                                        {group.features.map((feature) => {
                                            const index =
                                                submitted.indexOf(feature);

                                            return (
                                                <li
                                                    key={feature.key}
                                                    className="flex flex-wrap items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                                                >
                                                    <label
                                                        className="flex min-w-0 flex-1 items-center gap-2"
                                                        title={
                                                            feature.description ??
                                                            undefined
                                                        }
                                                    >
                                                        <input
                                                            type="checkbox"
                                                            checked={index >= 0}
                                                            onChange={(event) =>
                                                                toggle(
                                                                    feature.key,
                                                                    event.target
                                                                        .checked,
                                                                )
                                                            }
                                                            className="size-3.5 accent-primary"
                                                        />
                                                        <span className="min-w-0">
                                                            {feature.name}
                                                            {feature.status ===
                                                                'beta' && (
                                                                <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                                                                    Beta
                                                                </span>
                                                            )}
                                                            {feature.status ===
                                                                'deprecated' && (
                                                                <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                                                                    Deprecated
                                                                </span>
                                                            )}
                                                        </span>
                                                    </label>
                                                    {index >= 0 &&
                                                        feature.limitUnit && (
                                                            <Input
                                                                name={`features[${index}][limit]`}
                                                                type="number"
                                                                min={0}
                                                                placeholder={`No limit (${feature.limitUnit})`}
                                                                aria-label={`${feature.name} limit`}
                                                                value={
                                                                    chosen[
                                                                        feature
                                                                            .key
                                                                    ]
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    setChosen(
                                                                        (
                                                                            current,
                                                                        ) => ({
                                                                            ...current,
                                                                            [feature.key]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        }),
                                                                    )
                                                                }
                                                                className="h-8 w-44"
                                                            />
                                                        )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            ))}
                            <InputError
                                message={
                                    Object.entries(errors).find(([field]) =>
                                        field.startsWith('features'),
                                    )?.[1]
                                }
                            />
                        </section>
                    </div>

                    <div className="flex shrink-0 justify-end gap-2 border-t px-6 py-4">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {plan ? 'Save plan' : 'Create plan'}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
