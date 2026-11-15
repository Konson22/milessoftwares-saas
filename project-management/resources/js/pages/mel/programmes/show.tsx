import { Form, Head, Link } from '@inertiajs/react';
import { Archive, Link2, Plus } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { SelectField, TextField, selectClass } from '@/mel/components/fields';
import {
    FREQUENCIES,
    PROGRAMME_STATUS,
    UNIT_KINDS,
    formatDate,
} from '@/mel/lib/mel';
import type { Option } from '@/mel/lib/mel';
import { useCan } from '@/lib/access';
import { destroy as revoke, store as grant } from '@/routes/mel/access';
import { show as framework } from '@/routes/mel/frameworks';
import { show as importPage } from '@/routes/mel/import';
import { index as indicators } from '@/routes/mel/indicators';
import {
    generate,
    status as periodStatus,
    store as addPeriod,
} from '@/routes/mel/periods';
import { archive, edit } from '@/routes/mel/programmes';
import { show as report } from '@/routes/mel/reports';
import {
    archive as archiveUnit,
    project as linkProject,
    store as addUnit,
    update as updateUnit,
} from '@/routes/mel/units';

type Unit = {
    id: string;
    code: string;
    name: string;
    kind: string;
    partnerId: string | null;
    partner: string | null;
    locationId: string | null;
    location: string | null;
    archived: boolean;
    project: {
        id: string;
        label: string;
        status: string | null;
        progressPercent: number | null;
        currency: string | null;
        approvedBudgetMinor: number | null;
        spentMinor: number | null;
        milestones: {
            name: string;
            dueOn: string | null;
            reachedOn: string | null;
        }[];
    } | null;
};

type Period = {
    id: string;
    frequency: string;
    label: string;
    startsOn: string;
    endsOn: string;
    dueOn: string;
    status: 'open' | 'closed';
};

type Props = {
    programme: {
        id: string;
        code: string;
        name: string;
        status: string;
        startsOn: string;
        endsOn: string;
        archived: boolean;
        objective: string | null;
        description: string | null;
        currency: string;
        budgetMinor: number | null;
        fundingSummary: string | null;
        manager: string | null;
        locations: string[];
    };
    units: Unit[];
    periods: Period[];
    grants:
        | {
              id: string;
              user: string;
              email: string;
              unit: string | null;
              periodsFrom: string | null;
              periodsTo: string | null;
              expiresOn: string | null;
              grantedBy: string;
          }[]
        | null;
    options: {
        partners: Option[];
        locations: Option[];
        members: Option[];
        unitKinds: Option[];
        projects: Option[];
    } | null;
    can: { manage: boolean; linkProjects: boolean };
};

function money(minor: number | null, currency: string | null): string {
    if (minor === null) {
        return '—';
    }

    return `${currency ?? ''} ${(minor / 100).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    })}`.trim();
}

export default function ProgrammeShow({
    programme,
    units,
    periods,
    grants,
    options,
    can,
}: Props) {
    const allowed = useCan();
    const status = PROGRAMME_STATUS[programme.status];

    return (
        <>
            <Head title={`${programme.code} · ${programme.name}`} />
            <PageHeader
                title={
                    <>
                        <span className="text-muted-foreground">
                            {programme.code}
                        </span>{' '}
                        {programme.name}
                    </>
                }
                badges={
                    <>
                        <StatusBadge tone={status.tone}>
                            {status.label}
                        </StatusBadge>
                        {programme.archived && (
                            <StatusBadge tone="warning">Archived</StatusBadge>
                        )}
                    </>
                }
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link href={framework(programme.id)}>
                                Results framework
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link
                                href={indicators({
                                    query: { programme: programme.id },
                                })}
                            >
                                Indicators
                            </Link>
                        </Button>
                        {allowed('mel.reports.view') && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={report('iptt', {
                                        query: { programme: programme.id },
                                    })}
                                >
                                    Performance
                                </Link>
                            </Button>
                        )}
                        {can.manage && (
                            <Button variant="outline" asChild>
                                <Link href={edit(programme.id)}>Edit</Link>
                            </Button>
                        )}
                        {options !== null && (
                            <Form
                                {...archive.form(programme.id)}
                                options={{ preserveScroll: true }}
                            >
                                {programme.archived && (
                                    <input
                                        type="hidden"
                                        name="restore"
                                        value="1"
                                    />
                                )}
                                <Button type="submit" variant="ghost">
                                    <Archive aria-hidden />
                                    {programme.archived ? 'Restore' : 'Archive'}
                                </Button>
                            </Form>
                        )}
                    </>
                }
            />

            <div className="grid gap-3">
                <Panel>
                    <PanelBody className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Runs">
                            {formatDate(programme.startsOn)} to{' '}
                            {formatDate(programme.endsOn)}
                        </Fact>
                        <Fact label="Manager">{programme.manager ?? '—'}</Fact>
                        <Fact label="Budget">
                            {money(programme.budgetMinor, programme.currency)}
                        </Fact>
                        <Fact label="Locations">
                            {programme.locations.length === 0
                                ? '—'
                                : programme.locations.join('; ')}
                        </Fact>
                        {programme.objective && (
                            <Fact label="Objective" wide>
                                {programme.objective}
                            </Fact>
                        )}
                        {programme.fundingSummary && (
                            <Fact label="Funding" wide>
                                {programme.fundingSummary}
                            </Fact>
                        )}
                        {programme.description && (
                            <Fact label="Description" wide>
                                {programme.description}
                            </Fact>
                        )}
                    </PanelBody>
                </Panel>

                <UnitsPanel
                    programmeId={programme.id}
                    units={units}
                    options={options}
                    can={can}
                />

                <PeriodsPanel
                    programmeId={programme.id}
                    periods={periods}
                    canManage={can.manage}
                    canImport={
                        allowed('mel.data.import') && !programme.archived
                    }
                />

                {grants !== null && options !== null && (
                    <Panel>
                        <PanelHeader
                            title="Programme access"
                            description="People without access to every programme (donors, evaluators, partners, field staff) see only the programmes, units and periods given here. What they can do comes from their M&E role."
                        />
                        {grants.length > 0 && (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Person</TableHead>
                                        <TableHead>Unit</TableHead>
                                        <TableHead>Periods</TableHead>
                                        <TableHead>Until</TableHead>
                                        <TableHead>Given by</TableHead>
                                        <TableHead />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {grants.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell>
                                                <div className="font-medium">
                                                    {item.user}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {item.email}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {item.unit ?? 'All units'}
                                            </TableCell>
                                            <TableCell>
                                                {item.periodsFrom === null &&
                                                item.periodsTo === null
                                                    ? 'All periods'
                                                    : `${item.periodsFrom ? formatDate(item.periodsFrom) : 'Start'} to ${item.periodsTo ? formatDate(item.periodsTo) : 'end'}`}
                                            </TableCell>
                                            <TableCell>
                                                {item.expiresOn
                                                    ? formatDate(item.expiresOn)
                                                    : 'No end'}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground">
                                                {item.grantedBy}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                {can.manage && (
                                                    <Form
                                                        {...revoke.form({
                                                            programme:
                                                                programme.id,
                                                            grant: item.id,
                                                        })}
                                                        options={{
                                                            preserveScroll: true,
                                                        }}
                                                    >
                                                        <Button
                                                            type="submit"
                                                            variant="ghost"
                                                            size="sm"
                                                        >
                                                            Remove
                                                        </Button>
                                                    </Form>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                        {can.manage && (
                            <Form
                                {...grant.form(programme.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="grid gap-3 border-t p-3 sm:grid-cols-3 lg:grid-cols-6"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <SelectField
                                            name="user_id"
                                            label="Person"
                                            options={options.members}
                                            empty="Choose…"
                                            error={errors.user_id}
                                            prefix="grant-"
                                            className="lg:col-span-2"
                                        />
                                        <SelectField
                                            name="unit_id"
                                            label="Unit"
                                            options={units
                                                .filter(
                                                    (unit) => !unit.archived,
                                                )
                                                .map((unit) => ({
                                                    value: unit.id,
                                                    label: `${unit.code} · ${unit.name}`,
                                                }))}
                                            empty="All units"
                                            error={errors.unit_id}
                                            prefix="grant-"
                                        />
                                        <TextField
                                            name="periods_from"
                                            label="Periods from"
                                            type="date"
                                            error={errors.periods_from}
                                            prefix="grant-"
                                        />
                                        <TextField
                                            name="periods_to"
                                            label="Periods to"
                                            type="date"
                                            error={errors.periods_to}
                                            prefix="grant-"
                                        />
                                        <TextField
                                            name="expires_on"
                                            label="Access ends"
                                            type="date"
                                            error={errors.expires_on}
                                            prefix="grant-"
                                        />
                                        <div className="sm:col-span-3 lg:col-span-6">
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Give access
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}
                    </Panel>
                )}
            </div>
        </>
    );
}

function Fact({
    label,
    wide = false,
    children,
}: {
    label: string;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={wide ? 'sm:col-span-2 lg:col-span-4' : ''}>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-0.5 whitespace-pre-line">{children}</div>
        </div>
    );
}

function UnitsPanel({
    programmeId,
    units,
    options,
    can,
}: {
    programmeId: string;
    units: Unit[];
    options: Props['options'];
    can: Props['can'];
}) {
    const [editing, setEditing] = useState<string | null>(null);
    const [adding, setAdding] = useState(false);

    return (
        <Panel>
            <PanelHeader
                title="Reporting units"
                description="Where results are reported: projects, sites or partners. Programme figures combine them by each indicator's rules."
                actions={
                    can.manage &&
                    options !== null &&
                    !adding && (
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setAdding(true)}
                        >
                            <Plus aria-hidden />
                            Add unit
                        </Button>
                    )
                }
            />
            {units.length === 0 && !adding && (
                <p className="p-3 text-sm text-muted-foreground">
                    No units yet. Results are entered for a unit, so add at
                    least one.
                </p>
            )}
            {units.length > 0 && (
                <ul className="divide-y text-sm">
                    {units.map((unit) => (
                        <li key={unit.id} className="px-3 py-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="min-w-0">
                                    <span className="font-medium">
                                        {unit.code}
                                    </span>{' '}
                                    {unit.name}
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {UNIT_KINDS[unit.kind] ?? unit.kind}
                                        {unit.partner && ` · ${unit.partner}`}
                                        {unit.location && ` · ${unit.location}`}
                                    </span>
                                    {unit.archived && (
                                        <StatusBadge className="ml-2">
                                            Archived
                                        </StatusBadge>
                                    )}
                                </div>
                                <div className="flex gap-1">
                                    {can.manage && options !== null && (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    setEditing(
                                                        editing === unit.id
                                                            ? null
                                                            : unit.id,
                                                    )
                                                }
                                            >
                                                Edit
                                            </Button>
                                            <Form
                                                {...archiveUnit.form({
                                                    programme: programmeId,
                                                    unit: unit.id,
                                                })}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                {unit.archived && (
                                                    <input
                                                        type="hidden"
                                                        name="restore"
                                                        value="1"
                                                    />
                                                )}
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="ghost"
                                                >
                                                    {unit.archived
                                                        ? 'Restore'
                                                        : 'Archive'}
                                                </Button>
                                            </Form>
                                        </>
                                    )}
                                </div>
                            </div>

                            {unit.project && (
                                <ProjectSummary project={unit.project} />
                            )}

                            {can.linkProjects &&
                                options !== null &&
                                options.projects.length > 0 && (
                                    <Form
                                        {...linkProject.form({
                                            programme: programmeId,
                                            unit: unit.id,
                                        })}
                                        options={{ preserveScroll: true }}
                                        className="mt-2 flex flex-wrap items-center gap-2"
                                    >
                                        <Link2
                                            className="size-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <select
                                            name="linked_project_id"
                                            aria-label={`Project linked to ${unit.code}`}
                                            defaultValue={
                                                unit.project?.id ?? ''
                                            }
                                            className={`${selectClass} h-8 w-auto max-w-xs`}
                                        >
                                            <option value="">
                                                No linked project
                                            </option>
                                            {options.projects.map((project) => (
                                                <option
                                                    key={project.value}
                                                    value={project.value}
                                                >
                                                    {project.label}
                                                </option>
                                            ))}
                                        </select>
                                        <Button
                                            type="submit"
                                            size="sm"
                                            variant="outline"
                                        >
                                            Link
                                        </Button>
                                    </Form>
                                )}

                            {editing === unit.id && options !== null && (
                                <UnitForm
                                    programmeId={programmeId}
                                    unit={unit}
                                    options={options}
                                    onDone={() => setEditing(null)}
                                />
                            )}
                        </li>
                    ))}
                </ul>
            )}
            {adding && options !== null && (
                <div className="border-t">
                    <UnitForm
                        programmeId={programmeId}
                        unit={null}
                        options={options}
                        onDone={() => setAdding(false)}
                    />
                </div>
            )}
        </Panel>
    );
}

function ProjectSummary({
    project,
}: {
    project: NonNullable<Unit['project']>;
}) {
    const next = project.milestones.find(
        (milestone) => milestone.reachedOn === null,
    );

    return (
        <div className="mt-2 border-l-2 pl-3 text-xs text-muted-foreground">
            <span className="font-medium text-foreground">
                Project: {project.label}
            </span>
            {project.status && ` · ${project.status}`}
            {project.progressPercent !== null &&
                ` · ${project.progressPercent}% done`}
            {project.approvedBudgetMinor !== null &&
                ` · spent ${money(project.spentMinor, project.currency)} of ${money(project.approvedBudgetMinor, project.currency)}`}
            {next &&
                ` · next milestone ${next.name}${next.dueOn ? ` (${formatDate(next.dueOn)})` : ''}`}
            <span className="block">
                Delivery and spending are kept with the project; results are
                reported here.
            </span>
        </div>
    );
}

function UnitForm({
    programmeId,
    unit,
    options,
    onDone,
}: {
    programmeId: string;
    unit: Unit | null;
    options: NonNullable<Props['options']>;
    onDone: () => void;
}) {
    const action = unit
        ? updateUnit.form({ programme: programmeId, unit: unit.id })
        : addUnit.form(programmeId);
    const prefix = `unit-${unit?.id ?? 'new'}-`;

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3 p-3 sm:grid-cols-2 lg:grid-cols-5"
        >
            {({ errors, processing }) => (
                <>
                    <TextField
                        name="code"
                        label="Code"
                        defaultValue={unit?.code}
                        error={errors.code}
                        prefix={prefix}
                        required
                    />
                    <TextField
                        name="name"
                        label="Name"
                        defaultValue={unit?.name}
                        error={errors.name}
                        prefix={prefix}
                        required
                    />
                    <SelectField
                        name="kind"
                        label="Kind"
                        options={options.unitKinds}
                        defaultValue={unit?.kind ?? 'site'}
                        error={errors.kind}
                        prefix={prefix}
                    />
                    <SelectField
                        name="partner_id"
                        label="Implementing partner"
                        options={options.partners}
                        empty="None"
                        defaultValue={unit?.partnerId}
                        error={errors.partner_id}
                        prefix={prefix}
                    />
                    <SelectField
                        name="location_id"
                        label="Location"
                        options={options.locations}
                        empty="None"
                        defaultValue={unit?.locationId}
                        error={errors.location_id}
                        prefix={prefix}
                    />
                    <div className="flex gap-2 sm:col-span-2 lg:col-span-5">
                        <Button type="submit" size="sm" disabled={processing}>
                            {unit ? 'Save unit' : 'Add unit'}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function PeriodsPanel({
    programmeId,
    periods,
    canManage,
    canImport,
}: {
    programmeId: string;
    periods: Period[];
    canManage: boolean;
    canImport: boolean;
}) {
    const [custom, setCustom] = useState(false);
    const frequencies = [...new Set(periods.map((period) => period.frequency))];

    return (
        <Panel>
            <PanelHeader
                title="Reporting periods"
                description="Each indicator reports in periods of its own frequency. Closed periods take no new results."
                actions={
                    canImport && (
                        <Button size="sm" variant="outline" asChild>
                            <Link href={importPage(programmeId)}>
                                Import a sheet
                            </Link>
                        </Button>
                    )
                }
            />
            {periods.length === 0 ? (
                <p className="p-3 text-sm text-muted-foreground">
                    No periods yet.
                </p>
            ) : (
                <div className="divide-y">
                    {frequencies.map((frequency) => (
                        <div key={frequency} className="px-3 py-2">
                            <div className="mb-1 text-xs font-medium text-muted-foreground">
                                {FREQUENCIES[frequency] ?? frequency}
                            </div>
                            <ul className="flex flex-wrap gap-2">
                                {periods
                                    .filter(
                                        (period) =>
                                            period.frequency === frequency,
                                    )
                                    .map((period) => (
                                        <li
                                            key={period.id}
                                            className="flex items-center gap-1 border px-2 py-1 text-sm"
                                            title={`${formatDate(period.startsOn)} to ${formatDate(period.endsOn)}, due ${formatDate(period.dueOn)}`}
                                        >
                                            <span>{period.label}</span>
                                            {period.status === 'closed' && (
                                                <StatusBadge>
                                                    Closed
                                                </StatusBadge>
                                            )}
                                            {canManage && (
                                                <Form
                                                    {...periodStatus.form({
                                                        programme: programmeId,
                                                        period: period.id,
                                                    })}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <input
                                                        type="hidden"
                                                        name="status"
                                                        value={
                                                            period.status ===
                                                            'open'
                                                                ? 'closed'
                                                                : 'open'
                                                        }
                                                    />
                                                    <button
                                                        type="submit"
                                                        className="text-xs text-muted-foreground hover:text-foreground hover:underline"
                                                    >
                                                        {period.status ===
                                                        'open'
                                                            ? 'Close'
                                                            : 'Reopen'}
                                                    </button>
                                                </Form>
                                            )}
                                        </li>
                                    ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}
            {canManage && (
                <div className="grid gap-3 border-t p-3">
                    <Form
                        {...generate.form(programmeId)}
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <SelectField
                                    name="frequency"
                                    label="Add periods for the whole programme"
                                    options={[
                                        'monthly',
                                        'quarterly',
                                        'semiannual',
                                        'annual',
                                    ].map((value) => ({
                                        value,
                                        label: FREQUENCIES[value],
                                    }))}
                                    defaultValue="quarterly"
                                    error={errors.frequency}
                                    prefix="generate-"
                                />
                                <TextField
                                    name="due_after_days"
                                    label="Due, days after each period ends"
                                    type="number"
                                    defaultValue={15}
                                    error={errors.due_after_days}
                                    prefix="generate-"
                                />
                                <Button
                                    type="submit"
                                    variant="outline"
                                    disabled={processing}
                                >
                                    Add periods
                                </Button>
                                {!custom && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setCustom(true)}
                                    >
                                        Add a custom period
                                    </Button>
                                )}
                            </>
                        )}
                    </Form>
                    {custom && (
                        <Form
                            {...addPeriod.form(programmeId)}
                            options={{ preserveScroll: true }}
                            onSuccess={() => setCustom(false)}
                            className="grid gap-3 sm:grid-cols-5"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <TextField
                                        name="label"
                                        label="Name"
                                        placeholder="Baseline survey"
                                        error={errors.label}
                                        prefix="custom-"
                                        required
                                    />
                                    <TextField
                                        name="starts_on"
                                        label="Starts"
                                        type="date"
                                        error={errors.starts_on}
                                        prefix="custom-"
                                        required
                                    />
                                    <TextField
                                        name="ends_on"
                                        label="Ends"
                                        type="date"
                                        error={errors.ends_on}
                                        prefix="custom-"
                                        required
                                    />
                                    <TextField
                                        name="due_on"
                                        label="Due"
                                        type="date"
                                        error={errors.due_on}
                                        prefix="custom-"
                                        required
                                    />
                                    <div className="flex items-end gap-2">
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Add
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setCustom(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    )}
                </div>
            )}
        </Panel>
    );
}
