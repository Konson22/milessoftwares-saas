import { Form, Head, Link } from '@inertiajs/react';
import {
    AppWindow,
    Blocks,
    Building2,
    Calculator,
    Check,
    Fuel,
    GraduationCap,
    Handshake,
    LayoutDashboard,
    Pencil,
    Pill,
    Plug,
    Puzzle,
    Stethoscope,
    Store,
    Truck,
    Upload,
    Users,
    UtensilsCrossed,
    Warehouse,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
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
import { cn } from '@/shared/lib/utils';

type CatalogVersion = {
    version: string;
    status: string;
    current: boolean;
    publishedAt: string | null;
};

type Application = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    kind: string;
    status: string;
    routePrefix: string | null;
    tagline: string | null;
    features: string;
    sortOrder: number;
    trialEnabled: boolean;
    catalogVersions: CatalogVersion[];
};

type Props = { applications: Application[] };

type StatusFilter = 'all' | (typeof statuses)[number];

const statuses = [
    'available',
    'beta',
    'planned',
    'deprecated',
    'retired',
] as const;

const kinds = [
    'standalone',
    'industry',
    'shared_module',
    'addon',
    'platform',
] as const;

const icons: Record<string, LucideIcon> = {
    pharmacy: Pill,
    retail: Store,
    inventory: Warehouse,
    school: GraduationCap,
    hr: Users,
    finance: Calculator,
    advanced_accounting: Calculator,
    crm: Handshake,
    clinic: Stethoscope,
    restaurant: UtensilsCrossed,
    logistics: Truck,
    petrol: Fuel,
    workspace: LayoutDashboard,
    platform: LayoutDashboard,
};

const kindIcons: Record<string, LucideIcon> = {
    standalone: AppWindow,
    shared_module: Blocks,
    industry: Building2,
    addon: Puzzle,
    platform: LayoutDashboard,
};

const kindLabels: Record<string, string> = {
    standalone: 'Standalone',
    shared_module: 'Shared module',
    industry: 'Industry',
    addon: 'Add-on',
    platform: 'Platform',
};

const statusStyles: Record<
    string,
    { label: string; pill: string; dot: string }
> = {
    available: {
        label: 'Available',
        pill: 'bg-primary text-primary-foreground',
        dot: 'bg-primary-foreground',
    },
    beta: {
        label: 'Beta',
        pill: 'bg-primary/10 text-primary ring-1 ring-primary/25',
        dot: 'bg-primary',
    },
    planned: {
        label: 'Planned',
        pill: 'bg-muted text-muted-foreground ring-1 ring-border',
        dot: 'bg-muted-foreground/60',
    },
    deprecated: {
        label: 'Deprecated',
        pill: 'bg-amber-500/15 text-amber-800 ring-1 ring-amber-500/30 dark:text-amber-300',
        dot: 'bg-amber-500',
    },
    retired: {
        label: 'Retired',
        pill: 'bg-muted text-muted-foreground line-through',
        dot: 'bg-muted-foreground/40',
    },
};

const fieldClass =
    'border-input h-9 w-full rounded-md border bg-transparent px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

const textareaClass =
    'border-input placeholder:text-muted-foreground w-full rounded-md border bg-transparent px-2.5 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

function statusOf(status: string) {
    return (
        statusStyles[status] ?? {
            label: status,
            pill: 'bg-muted text-muted-foreground ring-1 ring-border',
            dot: 'bg-muted-foreground/60',
        }
    );
}

function kindLabel(kind: string) {
    return kindLabels[kind] ?? kind;
}

function featureLines(features: string) {
    return features
        .split('\n')
        .map((feature) => feature.trim())
        .filter((feature) => feature !== '');
}

function formatDate(value: string) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

    if (!match) {
        return value;
    }

    return new Date(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
    ).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function currentCatalog(application: Application) {
    const versions = application.catalogVersions;
    const current =
        versions.find((version) => version.current) ?? versions[0] ?? null;

    return {
        current,
        earlier: current ? versions.length - 1 : 0,
    };
}

export default function ApplicationsIndex({ applications }: Props) {
    const [query, setQuery] = useState('');
    const [status, setStatus] = useState<StatusFilter>('all');
    const [kind, setKind] = useState('all');
    const [editing, setEditing] = useState<Application | null>(null);
    const [session, setSession] = useState(0);
    const [importOpen, setImportOpen] = useState(false);

    const counts = Object.fromEntries(
        statuses.map((item) => [
            item,
            applications.filter((application) => application.status === item)
                .length,
        ]),
    ) as Record<(typeof statuses)[number], number>;
    const presentStatuses = statuses.filter((item) => counts[item] > 0);
    const presentKinds = kinds.filter((item) =>
        applications.some((application) => application.kind === item),
    );
    const needle = query.trim().toLowerCase();
    const visible = applications.filter((application) => {
        if (status !== 'all' && application.status !== status) {
            return false;
        }

        if (kind !== 'all' && application.kind !== kind) {
            return false;
        }

        if (needle === '') {
            return true;
        }

        return [
            application.name,
            application.code,
            application.tagline,
            application.description,
            application.routePrefix,
            kindLabel(application.kind),
            ...featureLines(application.features),
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(needle);
    });

    const openEditor = (application: Application) => {
        setEditing(application);
        setSession((current) => current + 1);
    };

    return (
        <>
            <Head title="Applications" />

            <div className="flex flex-wrap items-end justify-between gap-4">
                <h1 className="text-2xl font-semibold">Applications</h1>
                {applications.length > 0 && (
                    <Button onClick={() => setImportOpen(true)}>
                        <Upload />
                        Import catalog
                    </Button>
                )}
            </div>

            {applications.length === 0 ? (
                <div className="mt-6 rounded-xl border border-dashed bg-card px-6 py-16 text-center">
                    <div className="mx-auto flex size-12 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <AppWindow className="size-5" aria-hidden />
                    </div>
                    <h2 className="mt-4 font-semibold">No applications yet</h2>
                    <p className="mx-auto mt-1 max-w-sm text-sm text-muted-foreground">
                        Import a JSON role catalog. It creates the application
                        if needed and publishes a version.
                    </p>
                    <Button className="mt-4" onClick={() => setImportOpen(true)}>
                        <Upload />
                        Import catalog
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
                            <FilterButton
                                pressed={status === 'all'}
                                onClick={() => setStatus('all')}
                                count={applications.length}
                            >
                                All
                            </FilterButton>
                            {presentStatuses.map((item) => (
                                <FilterButton
                                    key={item}
                                    pressed={status === item}
                                    onClick={() => setStatus(item)}
                                    count={counts[item]}
                                >
                                    {statusOf(item).label}
                                </FilterButton>
                            ))}
                        </div>
                        {presentKinds.length > 1 && (
                            <select
                                value={kind}
                                onChange={(event) =>
                                    setKind(event.target.value)
                                }
                                aria-label="Filter by kind"
                                className={cn(fieldClass, 'sm:w-44')}
                            >
                                <option value="all">All kinds</option>
                                {presentKinds.map((item) => (
                                    <option key={item} value={item}>
                                        {kindLabel(item)}
                                    </option>
                                ))}
                            </select>
                        )}
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search name, code, or tagline"
                            aria-label="Search applications"
                            className="h-9 w-full sm:w-64"
                        />
                    </div>

                    {visible.length === 0 ? (
                        <p className="mt-6 rounded-xl border border-dashed px-6 py-10 text-center text-sm text-muted-foreground">
                            No applications match this view.
                        </p>
                    ) : (
                        <ul className="mt-5 grid gap-4 md:grid-cols-2 2xl:grid-cols-3">
                            {visible.map((application) => (
                                <ApplicationCard
                                    key={application.id}
                                    application={application}
                                    onEdit={() => openEditor(application)}
                                />
                            ))}
                        </ul>
                    )}
                </>
            )}

            <Dialog
                open={editing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setEditing(null);
                    }
                }}
            >
                <DialogContent className="flex max-h-[min(44rem,calc(100dvh-2rem))] flex-col gap-0 overflow-hidden p-0 sm:max-w-xl">
                    {editing && (
                        <ApplicationForm
                            key={`${editing.id}-${session}`}
                            application={editing}
                            onDone={() => setEditing(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={importOpen} onOpenChange={setImportOpen}>
                <DialogContent>
                    <CatalogImport onDone={() => setImportOpen(false)} />
                </DialogContent>
            </Dialog>
        </>
    );
}

function FilterButton({
    pressed,
    onClick,
    count,
    children,
}: {
    pressed: boolean;
    onClick: () => void;
    count: number;
    children: string;
}) {
    return (
        <button
            type="button"
            aria-pressed={pressed}
            onClick={onClick}
            className={cn(
                'rounded-md px-3 py-1 text-sm',
                pressed
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:text-foreground',
            )}
        >
            {children}
            <span className="ml-1.5 tabular-nums opacity-70">{count}</span>
        </button>
    );
}

function ApplicationCard({
    application,
    onEdit,
}: {
    application: Application;
    onEdit: () => void;
}) {
    const Icon =
        icons[application.code] ?? kindIcons[application.kind] ?? AppWindow;
    const status = statusOf(application.status);
    const features = featureLines(application.features);
    const [expanded, setExpanded] = useState(false);
    const shown = expanded ? features : features.slice(0, 3);
    const summary = application.tagline || application.description;
    const editable = application.kind !== 'platform';
    const { current, earlier } = currentCatalog(application);

    return (
        <li
            className={cn(
                'flex flex-col rounded-xl border bg-card shadow-xs transition-shadow hover:shadow-sm',
                application.status === 'retired' && 'opacity-60',
            )}
        >
            <div className="flex items-start gap-3 p-5">
                <div
                    className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-lg',
                        application.kind === 'platform'
                            ? 'bg-amber-500/10 text-amber-700 dark:text-amber-300'
                            : 'bg-primary/10 text-primary',
                    )}
                >
                    <Icon className="size-5" aria-hidden />
                </div>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <h2 className="text-base leading-tight font-semibold">
                            {application.name}
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
                        {application.code}
                    </code>
                </div>
            </div>

            <div className="flex flex-1 flex-col border-t">
                {(summary || features.length > 0) && (
                    <div className="space-y-3 px-5 pt-4">
                        {summary && (
                            <p className="line-clamp-2 text-sm text-muted-foreground">
                                {summary}
                            </p>
                        )}
                        {features.length > 0 && (
                            <ul className="space-y-1.5 text-sm">
                                {shown.map((feature) => (
                                    <li
                                        key={feature}
                                        className="flex items-start gap-2"
                                    >
                                        <Check
                                            className="mt-0.5 size-3.5 shrink-0 text-primary"
                                            aria-hidden
                                        />
                                        <span>{feature}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {features.length > 3 && (
                            <button
                                type="button"
                                className="text-xs font-medium text-primary hover:underline"
                                onClick={() =>
                                    setExpanded((value) => !value)
                                }
                                aria-expanded={expanded}
                            >
                                {expanded
                                    ? 'Show fewer features'
                                    : `${features.length - 3} more features`}
                            </button>
                        )}
                    </div>
                )}
                <dl className="grid flex-1 grid-cols-2 gap-x-4 gap-y-3 px-5 py-4 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Kind
                        </dt>
                        <dd className="mt-0.5">
                            {kindLabel(application.kind)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Workspace path
                        </dt>
                        <dd className="mt-0.5 font-mono text-[13px]">
                            {application.routePrefix ? (
                                `/${application.routePrefix}`
                            ) : (
                                <span className="font-sans text-muted-foreground">
                                    None
                                </span>
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Free trial
                        </dt>
                        <dd
                            className={cn(
                                'mt-0.5',
                                application.trialEnabled
                                    ? 'text-primary'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {application.trialEnabled ? 'Allowed' : 'Off'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Catalog
                        </dt>
                        <dd className="mt-0.5">
                            {current ? (
                                <>
                                    <span className="font-medium">
                                        {current.version}
                                    </span>
                                    <span className="mt-0.5 block text-xs text-muted-foreground">
                                        {current.current
                                            ? 'Current'
                                            : current.status}
                                        {current.publishedAt &&
                                            ` · ${formatDate(current.publishedAt)}`}
                                        {earlier > 0 &&
                                            ` · ${earlier} earlier`}
                                    </span>
                                </>
                            ) : (
                                <span className="text-muted-foreground">
                                    None published
                                </span>
                            )}
                        </dd>
                    </div>
                </dl>
            </div>

            {editable ? (
                <div className="flex flex-wrap gap-2 border-t px-5 py-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onEdit}
                        aria-label={`Edit ${application.name}`}
                    >
                        <Pencil />
                        Edit
                    </Button>
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={`/applications/${application.id}/integration`}
                        >
                            <Plug />
                            Integration
                        </Link>
                    </Button>
                </div>
            ) : (
                <p className="border-t px-5 py-3 text-xs text-muted-foreground">
                    Platform application. It is not sold or integrated on its
                    own.
                </p>
            )}
        </li>
    );
}

function ApplicationForm({
    application,
    onDone,
}: {
    application: Application;
    onDone: () => void;
}) {
    const id = (field: string) => `${application.id}-${field}`;

    return (
        <Form
            action={`/applications/${application.id}`}
            method="put"
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="flex min-h-0 flex-1 flex-col"
        >
            {({ errors, processing }) => (
                <>
                    <DialogHeader className="shrink-0 space-y-1 border-b px-6 py-5 pr-12 text-left">
                        <DialogTitle>Edit {application.name}</DialogTitle>
                        <DialogDescription>
                            <code>{application.code}</code>
                            {' · '}
                            {kindLabel(application.kind)}. The code is fixed.
                            Import a catalog to publish a new version.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">Registry</h3>
                            <div className="grid gap-3 sm:grid-cols-6">
                                <div className="grid gap-1.5 sm:col-span-4">
                                    <Label htmlFor={id('name')}>Name</Label>
                                    <Input
                                        id={id('name')}
                                        name="name"
                                        defaultValue={application.name}
                                        required
                                        maxLength={255}
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor={id('status')}>
                                        Status
                                    </Label>
                                    <select
                                        id={id('status')}
                                        name="status"
                                        defaultValue={application.status}
                                        className={fieldClass}
                                    >
                                        {statuses.map((status) => (
                                            <option key={status} value={status}>
                                                {statusOf(status).label}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={errors.status} />
                                </div>
                                <div className="grid gap-1.5 sm:col-span-4">
                                    <Label htmlFor={id('route')}>
                                        Workspace path
                                    </Label>
                                    <Input
                                        id={id('route')}
                                        name="route_prefix"
                                        defaultValue={
                                            application.routePrefix ?? ''
                                        }
                                        maxLength={32}
                                        pattern="[a-z][a-z0-9-]*"
                                        title="Lowercase letters, digits and -, starting with a letter"
                                        placeholder="pharmacy"
                                        aria-describedby={id('route-hint')}
                                    />
                                    <p
                                        id={id('route-hint')}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Served on the workspace host. Leave
                                        empty when this application has no page
                                        of its own.
                                    </p>
                                    <InputError
                                        message={errors.route_prefix}
                                    />
                                </div>
                                <div className="grid gap-1.5 sm:col-span-2">
                                    <Label htmlFor={id('sort')}>Order</Label>
                                    <Input
                                        id={id('sort')}
                                        name="sort_order"
                                        type="number"
                                        min={0}
                                        max={65535}
                                        required
                                        defaultValue={application.sortOrder}
                                        aria-describedby={id('sort-hint')}
                                    />
                                    <p
                                        id={id('sort-hint')}
                                        className="text-xs text-muted-foreground"
                                    >
                                        Lower numbers are listed first.
                                    </p>
                                    <InputError message={errors.sort_order} />
                                </div>
                            </div>
                        </section>

                        <section className="space-y-3">
                            <h3 className="text-sm font-medium">
                                Public listing
                            </h3>
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('description')}>
                                    Description
                                </Label>
                                <textarea
                                    id={id('description')}
                                    name="description"
                                    rows={3}
                                    maxLength={2000}
                                    defaultValue={application.description ?? ''}
                                    className={textareaClass}
                                />
                                <InputError message={errors.description} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('tagline')}>
                                    Public tagline
                                </Label>
                                <Input
                                    id={id('tagline')}
                                    name="tagline"
                                    maxLength={200}
                                    defaultValue={application.tagline ?? ''}
                                />
                                <InputError message={errors.tagline} />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor={id('features')}>
                                    Public feature list
                                </Label>
                                <textarea
                                    id={id('features')}
                                    name="features"
                                    rows={5}
                                    maxLength={4000}
                                    defaultValue={application.features}
                                    placeholder="One feature per line"
                                    aria-describedby={id('features-hint')}
                                    className={textareaClass}
                                />
                                <p
                                    id={id('features-hint')}
                                    className="text-xs text-muted-foreground"
                                >
                                    One feature per line. Shown on the public
                                    site.
                                </p>
                                <InputError message={errors.features} />
                            </div>
                        </section>

                        <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                            <input
                                type="checkbox"
                                name="trial_enabled"
                                value="1"
                                defaultChecked={application.trialEnabled}
                                className="mt-0.5 size-4 accent-primary"
                            />
                            <span>
                                Free trial allowed
                                <span className="mt-0.5 block text-muted-foreground">
                                    Workspaces may start a free trial while the
                                    status is beta or available.
                                </span>
                            </span>
                        </label>
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
                            Save application
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function CatalogImport({ onDone }: { onDone: () => void }) {
    return (
        <Form
            action="/applications/catalogs"
            method="post"
            resetOnSuccess
            onSuccess={onDone}
            className="grid gap-3"
        >
            {({ errors, processing }) => (
                <>
                    <DialogHeader className="pr-8 text-left">
                        <DialogTitle>Import a role catalog</DialogTitle>
                        <DialogDescription>
                            A JSON catalog creates the application if needed
                            and publishes a new version. Published versions
                            stay immutable.
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        name="catalog"
                        type="file"
                        accept="application/json,.json"
                        required
                        aria-label="Catalog file"
                        className="h-9"
                    />
                    <InputError message={errors.catalog} />
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            Import
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
