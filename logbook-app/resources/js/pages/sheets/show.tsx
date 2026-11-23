import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArrowRight,
    BookOpen,
    Boxes,
    CalendarDays,
    ClipboardCheck,
    Copy,
    FileText,
    History,
    ListChecks,
    NotebookPen,
    Pencil,
    RotateCcw,
    TriangleAlert,
    X,
} from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';
import { useState } from 'react';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import { EntriesTable } from '@/components/entries-table';
import { useConfirm } from '@/components/dialogs';
import { Check, SelectField, Status, TextField } from '@/components/fields';
import { PageHeader } from '@/components/page-header';
import type { EntryRow, Option } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import { create as newEntry, index as entries } from '@/routes/entries';
import { index as exceptionsIndex } from '@/routes/exceptions';
import { show as logbookShow } from '@/routes/logbooks';
import { show as resourceShow } from '@/routes/resources';
import { index as reviewsIndex } from '@/routes/reviews';
import * as sheetResources from '@/routes/sheets/resources';
import { archive, duplicate, restore, template } from '@/routes/sheets';
import * as draft from '@/routes/sheets/draft';

type Props = {
    sheet: {
        id: string;
        name: string;
        code: string;
        description: string | null;
        instructions: string | null;
        status: string;
        reviewRequired: boolean;
        version: number | null;
        hasDraft: boolean;
        logbook: { id: string; name: string; status: string };
    };
    entries: EntryRow[];
    versions: {
        id: string;
        version: number;
        status: string;
        fields: number;
        publishedAt: string | null;
    }[];
    fields: {
        name: string;
        label: string;
        type: string;
        required: boolean;
        unit: string | null;
        range: [string | number | null, string | number | null] | null;
    }[];
    stats: {
        total: number;
        lastWeek: number;
        awaiting: number;
        returned: number;
        lastEntryAt: string | null;
        openExceptions: number;
        seriousExceptions: number;
    };
    canWrite: boolean;
    canManage: boolean;
    logbooks: Option[];
    /** ADR-0030: how entries on this sheet are recorded. */
    recordingMode: 'on_demand' | 'scheduled' | 'event';
    /** ADR-0031: the field naming the asset, the assets linked, and those a manager may link. */
    assetField: { label: string; type: string | null } | null;
    assets: {
        id: string;
        label: string;
        type: string;
        status: string;
        location: string | null;
    }[];
    assetOptions: Option[];
};

const RECORDING: Record<Props['recordingMode'], string> = {
    on_demand: 'Recorded on demand',
    scheduled: 'Recorded on a schedule',
    event: 'Recorded per activity',
};

const TONES = {
    neutral: { bg: '#eef0f2', fg: '#3d4651' },
    clear: { bg: '#e6f2e6', fg: '#0b650a' },
    watch: { bg: '#fcefdc', fg: '#8a4b00' },
    alert: { bg: '#fbe5e7', fg: '#a10d19' },
} as const;

/**
 * One log sheet: what it records, how busy it is, its latest entries and
 * the versions of its template.
 */
export default function SheetShow({
    sheet,
    entries: rows,
    versions,
    fields,
    stats,
    canWrite,
    canManage,
    logbooks,
    recordingMode,
    assetField,
    assets,
    assetOptions,
}: Props) {
    const [copying, setCopying] = useState(false);
    const archived = sheet.status === 'archived';

    return (
        <>
            <Head title={sheet.name} />
            <PageHeader
                title={sheet.name}
                actions={
                    <>
                        {canManage && (
                            <Button asChild variant="outline">
                                <Link href={template(sheet.id)}>
                                    <Pencil />{' '}
                                    {sheet.hasDraft
                                        ? 'Continue the draft'
                                        : 'Edit template'}
                                </Link>
                            </Button>
                        )}
                        {canWrite && (
                            <Button asChild>
                                <Link
                                    href={newEntry({
                                        query: { sheet: sheet.id },
                                    })}
                                >
                                    <NotebookPen /> New entry
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-5">
                {/* What this sheet is. */}
                <section className="flex flex-col gap-4 rounded-lg border bg-card p-5 shadow-[0_1px_2px_#e3e3e3] sm:flex-row sm:items-start">
                    <span className="grid h-14 min-w-16 shrink-0 place-items-center rounded-xl bg-[#16202c] px-3 font-mono text-base font-semibold text-white">
                        {sheet.code}
                    </span>
                    <div className="grid min-w-0 flex-1 gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <Status value={sheet.status} />
                            <Link
                                href={logbookShow(sheet.logbook.id)}
                                className="inline-flex items-center gap-1 rounded-full bg-[#f1f3f5] px-2 py-0.5 text-xs font-medium text-[#3d4651] hover:bg-[#e6e9ec]"
                            >
                                <BookOpen className="size-3" />
                                {sheet.logbook.name}
                            </Link>
                            <Chip>
                                {sheet.version
                                    ? `Version ${sheet.version}`
                                    : 'Not published'}
                            </Chip>
                            <Chip>
                                {sheet.reviewRequired
                                    ? 'Entries are reviewed'
                                    : 'No review step'}
                            </Chip>
                            <Chip>{RECORDING[recordingMode]}</Chip>
                            {sheet.hasDraft && canManage && (
                                <span className="rounded-full bg-[#fcefdc] px-2 py-0.5 text-xs font-semibold text-[#8a4b00]">
                                    Unpublished changes
                                </span>
                            )}
                        </div>
                        <p
                            className={`text-sm ${sheet.description ? '' : 'text-muted-foreground'}`}
                        >
                            {sheet.description ?? 'No description yet.'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {stats.lastEntryAt
                                ? `Last entry ${formatDateTime(stats.lastEntryAt)}`
                                : 'Nothing written yet'}
                        </p>
                    </div>
                </section>

                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <Stat
                        icon={NotebookPen}
                        label="Entries"
                        value={stats.total}
                        tone="neutral"
                    />
                    <Stat
                        icon={CalendarDays}
                        label="In the last 7 days"
                        value={stats.lastWeek}
                        tone="neutral"
                    />
                    <Stat
                        icon={ClipboardCheck}
                        label="Waiting for review"
                        value={stats.awaiting}
                        note={
                            stats.returned > 0
                                ? `${stats.returned} returned for correction`
                                : undefined
                        }
                        tone={stats.awaiting > 0 ? 'watch' : 'clear'}
                        href={
                            sheet.reviewRequired
                                ? reviewsIndex().url
                                : undefined
                        }
                    />
                    <Stat
                        icon={TriangleAlert}
                        label="Open exceptions"
                        value={stats.openExceptions}
                        note={
                            stats.seriousExceptions > 0
                                ? `${stats.seriousExceptions} high or critical`
                                : undefined
                        }
                        tone={
                            stats.seriousExceptions > 0
                                ? 'alert'
                                : stats.openExceptions > 0
                                  ? 'watch'
                                  : 'clear'
                        }
                        href={exceptionsIndex().url}
                    />
                </div>

                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_22rem] lg:items-start">
                    <Card
                        title="Latest entries"
                        icon={NotebookPen}
                        actions={
                            stats.total > 0 && (
                                <Button asChild size="sm" variant="ghost">
                                    <Link
                                        href={entries({
                                            query: {
                                                scope: 'all',
                                                sheet: sheet.id,
                                            },
                                        })}
                                    >
                                        All {stats.total.toLocaleString()}{' '}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            )
                        }
                    >
                        {rows.length === 0 ? (
                            <div className="grid justify-items-center gap-3 px-6 py-12 text-center">
                                <span className="grid size-12 place-items-center rounded-xl bg-[#eef0f2] text-[#3d4651]">
                                    <NotebookPen className="size-6" />
                                </span>
                                <p className="text-sm font-semibold">
                                    No entries yet
                                </p>
                                <p className="max-w-sm text-sm text-muted-foreground">
                                    {sheet.version
                                        ? 'Submitted entries appear here.'
                                        : 'Publish the template so people can write entries.'}
                                </p>
                                {canWrite && (
                                    <Button asChild>
                                        <Link
                                            href={newEntry({
                                                query: { sheet: sheet.id },
                                            })}
                                        >
                                            <NotebookPen /> Write the first
                                            entry
                                        </Link>
                                    </Button>
                                )}
                                {!sheet.version && canManage && (
                                    <Button asChild>
                                        <Link href={template(sheet.id)}>
                                            <Pencil /> Build the template
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        ) : (
                            <EntriesTable entries={rows} />
                        )}
                    </Card>

                    <div className="grid content-start gap-5">
                        {sheet.instructions && (
                            <Card title="Instructions" icon={FileText}>
                                <p className="px-4 py-3 text-sm whitespace-pre-line">
                                    {sheet.instructions}
                                </p>
                            </Card>
                        )}

                        <Card
                            title="What it records"
                            icon={ListChecks}
                            count={fields.length}
                        >
                            {fields.length === 0 ? (
                                <p className="px-4 py-4 text-sm text-muted-foreground">
                                    No published fields yet.
                                </p>
                            ) : (
                                <ul className="max-h-[22rem] divide-y divide-[#f0f0f0] overflow-y-auto">
                                    {fields.map((f) => (
                                        <li
                                            key={f.name}
                                            className="grid gap-0.5 px-4 py-2"
                                        >
                                            <span className="flex items-baseline justify-between gap-2 text-sm">
                                                <span className="truncate font-medium">
                                                    {f.label}
                                                    {f.required && (
                                                        <span
                                                            className="ml-0.5 text-[#a10d19]"
                                                            title="Required"
                                                        >
                                                            *
                                                        </span>
                                                    )}
                                                </span>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {f.type}
                                                    {f.unit && ` · ${f.unit}`}
                                                </span>
                                            </span>
                                            {f.range && (
                                                <span className="text-xs text-muted-foreground">
                                                    Normal range{' '}
                                                    {f.range[0] ?? '…'} –{' '}
                                                    {f.range[1] ?? '…'}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {fields.some((f) => f.required) && (
                                <p className="border-t border-[#ebebeb] px-4 py-2 text-xs text-muted-foreground">
                                    <span className="text-[#a10d19]">*</span>{' '}
                                    required
                                </p>
                            )}
                        </Card>

                        <Card
                            title="Template versions"
                            icon={History}
                            count={versions.length}
                        >
                            <ol className="grid gap-0 px-4 py-3">
                                {versions.map((v, i) => (
                                    <li key={v.id} className="flex gap-3">
                                        <span className="flex flex-col items-center">
                                            <span
                                                className={`mt-1 size-2.5 shrink-0 rounded-full ${v.status === 'published' ? 'bg-[#0e8a6c]' : v.status === 'draft' ? 'bg-[#d97706]' : 'bg-[#b0b6bd]'}`}
                                                aria-hidden
                                            />
                                            {i < versions.length - 1 && (
                                                <span className="w-px flex-1 bg-[#e3e6e9]" />
                                            )}
                                        </span>
                                        <span className="flex min-w-0 flex-1 items-start justify-between gap-2 pb-3">
                                            <span className="grid">
                                                <span className="text-sm font-medium">
                                                    Version {v.version}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {v.fields}{' '}
                                                    {v.fields === 1
                                                        ? 'field'
                                                        : 'fields'}
                                                    {v.publishedAt &&
                                                        ` · ${formatDateTime(v.publishedAt)}`}
                                                </span>
                                            </span>
                                            <Status value={v.status} />
                                        </span>
                                    </li>
                                ))}
                            </ol>
                            <p className="border-t border-[#ebebeb] px-4 py-2 text-xs text-muted-foreground">
                                Published versions never change; each entry
                                keeps the version it was written with.
                            </p>
                        </Card>

                        {assetField && (
                            <AssetsCard
                                sheetId={sheet.id}
                                field={assetField}
                                assets={assets}
                                options={assetOptions}
                                canManage={canManage}
                            />
                        )}

                        {canManage && (
                            <Card title="Manage">
                                <div className="grid gap-2 p-4">
                                    <Button
                                        variant={
                                            copying ? 'secondary' : 'outline'
                                        }
                                        className="justify-start"
                                        onClick={() => setCopying(!copying)}
                                        aria-expanded={copying}
                                    >
                                        <Copy /> Duplicate this log sheet
                                    </Button>
                                    {copying && (
                                        <DuplicateForm
                                            sheetId={sheet.id}
                                            logbookId={sheet.logbook.id}
                                            name={sheet.name}
                                            logbooks={logbooks}
                                            onCancel={() => setCopying(false)}
                                        />
                                    )}
                                    {sheet.hasDraft &&
                                        sheet.version !== null && (
                                            <ActionButton
                                                url={
                                                    draft.destroy(sheet.id).url
                                                }
                                                method="delete"
                                                label="Discard unpublished changes"
                                                variant="outline"
                                                confirm="Discard the unpublished changes to this template?"
                                            />
                                        )}
                                    {archived ? (
                                        <ActionButton
                                            url={restore(sheet.id).url}
                                            label="Restore log sheet"
                                            variant="outline"
                                        />
                                    ) : (
                                        <ActionButton
                                            url={archive(sheet.id).url}
                                            label="Archive log sheet"
                                            variant="outline"
                                            confirm="Archive this log sheet? Its entries are kept and stay readable; nobody can write new ones."
                                        />
                                    )}
                                    <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                                        {archived ? (
                                            <RotateCcw className="mt-0.5 size-3 shrink-0" />
                                        ) : (
                                            <Archive className="mt-0.5 size-3 shrink-0" />
                                        )}
                                        {archived
                                            ? 'Restoring lets people write entries again.'
                                            : 'Archiving keeps every entry readable but stops new ones.'}
                                    </p>
                                </div>
                            </Card>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

/**
 * ADR-0031: the assets this sheet records. Linked: entries offer only
 * these. None linked: any active asset of the field's category.
 */
function AssetsCard({
    sheetId,
    field,
    assets,
    options,
    canManage,
}: {
    sheetId: string;
    field: { label: string; type: string | null };
    assets: Props['assets'];
    options: Option[];
    canManage: boolean;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const confirm = useConfirm();
    const [picked, setPicked] = useState<string[]>([]);
    const kind = field.type ? field.type.toLowerCase() : 'resource';

    return (
        <Card title="Assets" icon={Boxes} count={assets.length}>
            <p className="px-4 pt-3 text-xs text-muted-foreground">
                {`Named in "${field.label}". `}
                {assets.length === 0
                    ? `Not limited: any active ${kind} is offered.`
                    : 'Only these are offered on new entries.'}
            </p>
            {assets.length > 0 && (
                <ul className="divide-y divide-[#ebebeb] px-4 py-2">
                    {assets.map((a) => (
                        <li
                            key={a.id}
                            className="flex items-center justify-between gap-2 py-1.5 text-sm"
                        >
                            <span className="min-w-0">
                                <Link
                                    href={resourceShow(a.id)}
                                    className="font-medium hover:underline"
                                >
                                    {a.label}
                                </Link>
                                {a.location && (
                                    <span className="block text-xs text-muted-foreground">
                                        {a.location}
                                    </span>
                                )}
                            </span>
                            <span className="flex items-center gap-1">
                                {a.status !== 'active' && (
                                    <Status value={a.status} />
                                )}
                                {canManage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-label={`Unlink ${a.label}`}
                                        onClick={async () => {
                                            if (
                                                await confirm({
                                                    title: `Unlink ${a.label}?`,
                                                    message:
                                                        'Past entries keep naming it. New entries on this sheet will no longer offer it.',
                                                    confirmLabel: 'Unlink',
                                                })
                                            ) {
                                                router.delete(
                                                    sheetResources.destroy({
                                                        sheet: sheetId,
                                                        resource: a.id,
                                                    }).url,
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        <X />
                                    </Button>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
            {(errors.link || errors.resource_ids) && (
                <p className="px-4 text-xs text-destructive">
                    {errors.link ?? errors.resource_ids}
                </p>
            )}
            {canManage && options.length > 0 && (
                <form
                    className="grid gap-2 border-t border-[#ebebeb] p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.post(
                            sheetResources.store(sheetId).url,
                            { resource_ids: picked },
                            {
                                preserveScroll: true,
                                onSuccess: () => setPicked([]),
                            },
                        );
                    }}
                >
                    <p className="text-xs font-medium">Link assets</p>
                    <div className="grid max-h-48 gap-1 overflow-y-auto">
                        {options.map((o) => (
                            <Check
                                key={o.value}
                                label={o.label}
                                checked={picked.includes(o.value)}
                                onChange={(on) =>
                                    setPicked(
                                        on
                                            ? [...picked, o.value]
                                            : picked.filter(
                                                  (x) => x !== o.value,
                                              ),
                                    )
                                }
                            />
                        ))}
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        variant="outline"
                        disabled={picked.length === 0}
                    >
                        {picked.length === 0
                            ? 'Link assets'
                            : `Link ${picked.length} ${picked.length === 1 ? 'asset' : 'assets'}`}
                    </Button>
                </form>
            )}
        </Card>
    );
}

function Chip({ children }: { children: ReactNode }) {
    return (
        <span className="rounded-full bg-[#f1f3f5] px-2 py-0.5 text-xs font-medium text-[#3d4651]">
            {children}
        </span>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
    note,
    tone,
    href,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value: number;
    note?: string;
    tone: keyof typeof TONES;
    href?: string;
}) {
    const t = TONES[tone];
    const body = (
        <>
            <span
                className="grid size-10 shrink-0 place-items-center rounded-lg"
                style={{ backgroundColor: t.bg, color: t.fg }}
            >
                <Icon className="size-5" />
            </span>
            <span className="grid min-w-0 flex-1">
                <span className="truncate text-xs font-medium text-muted-foreground">
                    {label}
                </span>
                <span className="text-2xl leading-tight font-semibold tabular-nums">
                    {value.toLocaleString()}
                </span>
                {note && (
                    <span className="truncate text-xs text-muted-foreground">
                        {note}
                    </span>
                )}
            </span>
            {href && (
                <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
            )}
        </>
    );
    const className =
        'flex items-center gap-3 rounded-lg border bg-card p-4 shadow-[0_1px_2px_#e3e3e3]';

    return href ? (
        <Link href={href} className={`${className} hover:border-[#b9c0c7]`}>
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

function Card({
    title,
    icon: Icon,
    count,
    actions,
    children,
}: {
    title: string;
    icon?: ComponentType<{ className?: string }>;
    count?: number;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="overflow-hidden rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3]">
            <header className="flex items-center justify-between gap-2 border-b border-[#ebebeb] px-4 py-3">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    {Icon && <Icon className="size-4 text-muted-foreground" />}
                    {title}
                    {count !== undefined && (
                        <span className="font-normal text-muted-foreground tabular-nums">
                            {count}
                        </span>
                    )}
                </h2>
                {actions}
            </header>
            {children}
        </section>
    );
}

function DuplicateForm({
    sheetId,
    logbookId,
    name,
    logbooks,
    onCancel,
}: {
    sheetId: string;
    logbookId: string;
    name: string;
    logbooks: Option[];
    onCancel: () => void;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: `${name} (copy)`,
        code: '',
        logbook_id: logbookId,
    });

    return (
        <form
            className="grid gap-3 rounded-lg border border-[#e3e6e9] bg-[#f8f9fa] p-3"
            onSubmit={(e) => {
                e.preventDefault();
                router.post(duplicate(sheetId).url, data);
            }}
        >
            <TextField
                label="Name"
                value={data.name}
                onChange={(v) => setData({ ...data, name: v })}
                error={errors.name}
            />
            <TextField
                label="Code"
                value={data.code}
                onChange={(v) => setData({ ...data, code: v.toUpperCase() })}
                error={errors.code}
                required
            />
            <SelectField
                label="Into logbook"
                value={data.logbook_id}
                onChange={(v) => setData({ ...data, logbook_id: v })}
                options={logbooks}
            />
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    onClick={onCancel}
                >
                    <X /> Cancel
                </Button>
                <Button type="submit" size="sm">
                    <Copy /> Create the copy
                </Button>
            </div>
        </form>
    );
}
