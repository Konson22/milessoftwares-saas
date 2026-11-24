import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { useConfirm } from '@/components/dialogs';
import { Badge, SelectField, Status, TextField } from '@/components/fields';
import { Toggle } from '@/components/work';
import { show as entryShow } from '@/routes/entries';
import { show as exceptionShow } from '@/routes/exceptions';
import { show as investigationShow } from '@/routes/investigations';
import { index as reportsIndex } from '@/routes/reports';
import { starter } from '@/routes/resources';
import * as resourceSheets from '@/routes/resources/sheets';
import { show as sheetShow } from '@/routes/sheets';
import { show as taskShow } from '@/routes/tasks';

export type AssetIdentity = {
    id: string;
    code: string;
    name: string;
    type: string;
    category: string | null;
    kind: 'asset' | 'monitored_location';
    status: 'active' | 'inactive' | 'retired';
    description: string | null;
    location: string | null;
    manufacturer: string | null;
    model: string | null;
    serialNumber: string | null;
    responsible: string | null;
    metadata: { label: string; value: string; unit?: string }[];
    template: { name: string; version: number } | null;
};

export type AssetOperations = {
    sheets: {
        id: string;
        code: string;
        name: string;
        logbook: string;
        status: string;
        mode: string;
        visible: boolean;
        canUnlink: boolean;
    }[];
    linkable: { value: string; label: string }[];
    suggested: { key: string; name: string; code: string }[];
    logbooks: { value: string; label: string }[];
    schedules: {
        id: string;
        sheet: string;
        state: string;
        times: string;
        frequency: string;
    }[];
    checks: {
        expected: number;
        completed: number;
        late: number;
        missed: number;
        excused: number;
        awaitingReview: number;
        open: number;
        rate: number | null;
    };
    missed: { id: string; sheet: string; dueAt: string }[];
    records: {
        id: string;
        reference: string;
        sheet: string;
        occurredAt: string;
        status: string;
        creator: string;
        outOfRange: number;
        fuel: number | null;
    }[];
    recordCount: number;
    exceptions: {
        id: string;
        reference: string;
        title: string;
        severity: string;
        status: string;
        at: string;
    }[];
    tasks: {
        id: string;
        reference: string;
        title: string;
        status: string;
        dueAt: string | null;
    }[];
    investigations: {
        id: string;
        reference: string;
        title: string;
        status: string;
    }[];
};

/**
 * The asset's operational overview (ADR-0031 §9): what it is, the log
 * sheets that record it, its expected checks over the last 30 days, and
 * its latest records, exceptions, tasks and investigations.
 */
export function AssetOverview({
    resource,
    ops,
    canManage,
}: {
    resource: AssetIdentity;
    ops: AssetOperations;
    canManage: boolean;
}) {
    const confirm = useConfirm();
    const retired = resource.status === 'retired';
    const identity: [string, string | null][] = [
        ['Category', resource.type],
        [
            'Kind',
            resource.kind === 'monitored_location'
                ? 'Monitored location'
                : 'Physical asset',
        ],
        ['Location', resource.location],
        ['Responsible', resource.responsible],
        ['Manufacturer', resource.manufacturer],
        ['Model', resource.model],
        ['Serial number', resource.serialNumber],
        [
            'Template',
            resource.template
                ? `${resource.template.name} (version ${resource.template.version})`
                : null,
        ],
        ...resource.metadata.map(
            (m) =>
                [m.label, m.unit ? `${m.value} ${m.unit}` : m.value] as [
                    string,
                    string,
                ],
        ),
    ];

    return (
        <div className="grid gap-6">
            <div className="grid gap-4 sm:grid-cols-4">
                <Figure label="Records" value={ops.recordCount} />
                <Figure
                    label="Checks expected (30 days)"
                    value={ops.checks.expected}
                    note={
                        ops.checks.rate === null
                            ? 'None scheduled'
                            : `${ops.checks.rate}% done`
                    }
                />
                <Figure
                    label="Missed (30 days)"
                    value={ops.checks.missed}
                    danger={ops.checks.missed > 0}
                />
                <Figure
                    label="Open exceptions"
                    value={
                        ops.exceptions.filter(
                            (x) => !['closed', 'resolved'].includes(x.status),
                        ).length
                    }
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Log sheets that record it"
                        description="Entries on these sheets name this asset. Once a sheet has linked assets, only those are offered on it."
                    />
                    {ops.sheets.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            Not linked to a log sheet yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {ops.sheets.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm"
                                >
                                    <span className="min-w-0">
                                        {s.visible ? (
                                            <Link
                                                href={sheetShow(s.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {s.code} · {s.name}
                                            </Link>
                                        ) : (
                                            <span className="font-medium">
                                                {s.code} · {s.name}
                                            </span>
                                        )}
                                        <span className="block text-muted-foreground">
                                            {s.logbook} · {s.mode}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-2">
                                        <Status value={s.status} />
                                        {s.canUnlink && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={async () => {
                                                    if (
                                                        await confirm({
                                                            title: `Unlink ${s.code}?`,
                                                            message:
                                                                'Its past entries keep naming this asset. New entries on the sheet will no longer offer it.',
                                                            confirmLabel:
                                                                'Unlink',
                                                        })
                                                    ) {
                                                        router.delete(
                                                            resourceSheets.destroy(
                                                                {
                                                                    resource:
                                                                        resource.id,
                                                                    sheet: s.id,
                                                                },
                                                            ).url,
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        );
                                                    }
                                                }}
                                            >
                                                Unlink
                                            </Button>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {canManage && !retired && (
                        <div className="flex flex-wrap gap-2 border-t px-4 py-3">
                            {ops.linkable.length > 0 && (
                                <Toggle
                                    label="Link a log sheet"
                                    variant="outline"
                                >
                                    {(close) => (
                                        <LinkSheet
                                            resourceId={resource.id}
                                            options={ops.linkable}
                                            onDone={close}
                                        />
                                    )}
                                </Toggle>
                            )}
                            {ops.logbooks.length > 0 && (
                                <Toggle
                                    label="Create a log sheet for it"
                                    variant="outline"
                                >
                                    {(close) => (
                                        <FromStarter
                                            resource={resource}
                                            suggested={ops.suggested}
                                            logbooks={ops.logbooks}
                                            onDone={close}
                                        />
                                    )}
                                </Toggle>
                            )}
                        </div>
                    )}
                </Panel>

                <Panel className="self-start">
                    <PanelHeader title="Identity" />
                    <PanelBody>
                        <dl className="grid gap-2 text-sm">
                            {identity
                                .filter(([, v]) => v !== null && v !== '')
                                .map(([k, v]) => (
                                    <div
                                        key={k}
                                        className="flex justify-between gap-3"
                                    >
                                        <dt className="text-muted-foreground">
                                            {k}
                                        </dt>
                                        <dd className="text-right">{v}</dd>
                                    </div>
                                ))}
                        </dl>
                        {resource.description && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                {resource.description}
                            </p>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Panel>
                    <PanelHeader
                        title="Schedules"
                        description="Expected readings for this asset."
                    />
                    {ops.schedules.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            Recorded on demand: no schedule for this asset.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {ops.schedules.map((s) => (
                                <li
                                    key={s.id}
                                    className="flex justify-between gap-2 px-4 py-2 text-sm"
                                >
                                    <span>
                                        {s.sheet}
                                        <span className="block text-muted-foreground">
                                            {s.frequency} · due {s.times}
                                        </span>
                                    </span>
                                    <Status value={s.state} />
                                </li>
                            ))}
                        </ul>
                    )}
                    {ops.missed.length > 0 && (
                        <div className="border-t px-4 py-3 text-sm">
                            <p className="font-medium">Recently missed</p>
                            <ul className="mt-1 text-muted-foreground">
                                {ops.missed.map((m) => (
                                    <li key={m.id}>
                                        {m.sheet} · due {m.dueAt}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Latest records"
                        actions={
                            <Button asChild size="sm" variant="outline">
                                <Link
                                    href={reportsIndex({
                                        query: {
                                            view: 'table',
                                            resource: resource.id,
                                        },
                                    })}
                                >
                                    All in reports
                                </Link>
                            </Button>
                        }
                    />
                    {ops.records.length === 0 ? (
                        <p className="px-4 py-3 text-sm text-muted-foreground">
                            Nothing recorded yet.
                        </p>
                    ) : (
                        <ul className="divide-y">
                            {ops.records.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex justify-between gap-2 px-4 py-2 text-sm"
                                >
                                    <span className="min-w-0">
                                        <Link
                                            href={entryShow(r.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {r.reference}
                                        </Link>
                                        <span className="block text-muted-foreground">
                                            {r.sheet} · {r.occurredAt} ·{' '}
                                            {r.creator}
                                            {r.fuel !== null &&
                                                ` · ${r.fuel} L estimated`}
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-1">
                                        {r.outOfRange > 0 && (
                                            <Badge tone="danger">
                                                {r.outOfRange} out of range
                                            </Badge>
                                        )}
                                        <Status value={r.status} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>

            <div className="grid gap-6 lg:grid-cols-3">
                <SmallList
                    title="Exceptions"
                    empty="No exceptions."
                    rows={ops.exceptions.map((x) => ({
                        id: x.id,
                        href: exceptionShow(x.id).url,
                        title: `${x.reference} · ${x.title}`,
                        sub: `${x.severity} · ${x.at}`,
                        status: x.status,
                    }))}
                />
                <SmallList
                    title="Corrective tasks"
                    empty="No tasks."
                    rows={ops.tasks.map((t) => ({
                        id: t.id,
                        href: taskShow(t.id).url,
                        title: `${t.reference} · ${t.title}`,
                        sub: t.dueAt ? `due ${t.dueAt}` : '',
                        status: t.status,
                    }))}
                />
                <SmallList
                    title="Investigations"
                    empty="No investigations."
                    rows={ops.investigations.map((i) => ({
                        id: i.id,
                        href: investigationShow(i.id).url,
                        title: `${i.reference} · ${i.title}`,
                        sub: '',
                        status: i.status,
                    }))}
                />
            </div>
        </div>
    );
}

function Figure({
    label,
    value,
    note,
    danger,
}: {
    label: string;
    value: number;
    note?: string;
    danger?: boolean;
}) {
    return (
        <Panel>
            <PanelBody>
                <p className="text-xs text-muted-foreground">{label}</p>
                <p
                    className={`mt-1 text-xl font-semibold ${danger ? 'text-[#c01c1c]' : ''}`}
                >
                    {value}
                </p>
                {note && (
                    <p className="text-xs text-muted-foreground">{note}</p>
                )}
            </PanelBody>
        </Panel>
    );
}

function SmallList({
    title,
    empty,
    rows,
}: {
    title: string;
    empty: string;
    rows: {
        id: string;
        href: string;
        title: string;
        sub: string;
        status: string;
    }[];
}) {
    return (
        <Panel>
            <PanelHeader title={title} />
            {rows.length === 0 ? (
                <p className="px-4 py-3 text-sm text-muted-foreground">
                    {empty}
                </p>
            ) : (
                <ul className="divide-y">
                    {rows.map((r) => (
                        <li
                            key={r.id}
                            className="flex justify-between gap-2 px-4 py-2 text-sm"
                        >
                            <span className="min-w-0">
                                <Link
                                    href={r.href}
                                    className="font-medium hover:underline"
                                >
                                    {r.title}
                                </Link>
                                {r.sub && (
                                    <span className="block text-muted-foreground">
                                        {r.sub}
                                    </span>
                                )}
                            </span>
                            <Status value={r.status} />
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

function LinkSheet({
    resourceId,
    options,
    onDone,
}: {
    resourceId: string;
    options: { value: string; label: string }[];
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [sheet, setSheet] = useState(options[0]?.value ?? '');

    return (
        <form
            className="grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(
                    resourceSheets.store(resourceId).url,
                    { sheet_id: sheet },
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <SelectField
                label="Log sheet"
                value={sheet}
                onChange={setSheet}
                options={options}
                error={errors.link ?? errors.sheet_id}
                hint="Only sheets that name an asset of this category are listed."
            />
            <Button type="submit">Link</Button>
        </form>
    );
}

function FromStarter({
    resource,
    suggested,
    logbooks,
    onDone,
}: {
    resource: AssetIdentity;
    suggested: { key: string; name: string; code: string }[];
    logbooks: { value: string; label: string }[];
    onDone: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const first = suggested[0];
    const codeFor = (s: { code: string } | undefined) =>
        ((s?.code ?? 'LOG') + resource.code.replace(/[^A-Za-z0-9]/g, '')).slice(
            0,
            12,
        );
    const [data, setData] = useState({
        template: first?.key ?? '',
        logbook_id: logbooks[0]?.value ?? '',
        name: first ? `${first.name} · ${resource.code}` : '',
        code: codeFor(first),
    });

    if (suggested.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                This asset's template suggests no starter log sheet. Create a
                sheet from the log sheet starters, then link it here.
            </p>
        );
    }

    return (
        <form
            className="grid gap-3 sm:grid-cols-2"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(starter(resource.id).url, data, {
                    onSuccess: onDone,
                });
            }}
        >
            <SelectField
                label="Starter"
                value={data.template}
                onChange={(template) => {
                    const s = suggested.find((x) => x.key === template);
                    setData({
                        ...data,
                        template,
                        name: s ? `${s.name} · ${resource.code}` : data.name,
                        code: codeFor(s),
                    });
                }}
                options={suggested.map((s) => ({
                    value: s.key,
                    label: s.name,
                }))}
                error={errors.template}
            />
            <SelectField
                label="Logbook"
                value={data.logbook_id}
                onChange={(logbook_id) => setData({ ...data, logbook_id })}
                options={logbooks}
                error={errors.logbook_id}
            />
            <TextField
                label="Sheet name"
                value={data.name}
                onChange={(name) => setData({ ...data, name })}
                error={errors.name}
            />
            <TextField
                label="Sheet code"
                value={data.code}
                onChange={(code) => setData({ ...data, code })}
                error={errors.code}
                hint="Letters and digits, starting with a letter."
            />
            <div className="sm:col-span-2">
                <Button type="submit">Create and link</Button>
                <p className="mt-2 text-xs text-muted-foreground">
                    The sheet opens as a draft so you can review its fields,
                    then publish it.
                </p>
            </div>
        </form>
    );
}
