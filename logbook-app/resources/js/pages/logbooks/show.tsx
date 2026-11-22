import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Building2,
    FilePlus2,
    NotebookPen,
    Plus,
    Sparkles,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { ActionButton } from '@/components/actions';
import { Check, SelectField, Status, TextField } from '@/components/fields';
import { LogbookForm, type DepartmentOption } from '@/components/logbook-form';
import { LogbookIcon } from '@/components/logbook-icon';
import type { Option } from '@/lib/logbook';
import { formatDateTime, ROLE_LABELS } from '@/lib/logbook';
import { create as newEntry } from '@/routes/entries';
import { archive, restore, update } from '@/routes/logbooks';
import * as accessRoutes from '@/routes/logbooks/access';
import {
    show as sheetShow,
    starters,
    store as storeSheet,
} from '@/routes/sheets';
import { useConfirm } from '@/components/dialogs';

type Props = {
    logbook: {
        id: string;
        name: string;
        description: string | null;
        icon: string | null;
        restricted: boolean;
        status: string;
        createdAt: string;
        creator: string;
        departmentId: string | null;
        department: string | null;
    };
    sheets: {
        id: string;
        name: string;
        code: string;
        description: string | null;
        status: string;
        reviewRequired: boolean;
        version: number | null;
        hasDraft: boolean;
        entries: number;
        lastEntryAt: string | null;
        canWrite: boolean;
    }[];
    /** The logbook's access list. Not `access`: that shared prop holds the member's own permissions. */
    grants:
        | {
              id: string;
              user: string;
              email: string;
              role: string;
              sheet: string | null;
          }[]
        | null;
    members: Option[];
    canManage: boolean;
    departments: DepartmentOption[];
    canFileUnderWorkspace: boolean;
    hasEntries: boolean;
};

export default function LogbookShow({
    logbook,
    sheets,
    grants,
    members,
    canManage,
    departments,
    canFileUnderWorkspace,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    const active = logbook.status === 'active';

    return (
        <>
            <Head title={logbook.name} />
            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        <LogbookIcon icon={logbook.icon} className="size-6" />
                        {logbook.name}
                    </span>
                }
                description={logbook.description ?? undefined}
                badges={
                    <>
                        <Status value={logbook.status} />
                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                            <Building2 className="size-3" />{' '}
                            {logbook.department ?? 'Whole workspace'}
                        </span>
                    </>
                }
                actions={
                    canManage && (
                        <>
                            {active && (
                                <>
                                    <Button asChild variant="outline">
                                        <Link href={starters(logbook.id)}>
                                            <Sparkles /> From a starter template
                                        </Link>
                                    </Button>
                                    <Button onClick={() => setAdding(!adding)}>
                                        <Plus /> New log sheet
                                    </Button>
                                </>
                            )}
                            <Button
                                variant="ghost"
                                onClick={() => setEditing(!editing)}
                            >
                                Settings
                            </Button>
                        </>
                    )
                }
            />

            {editing && canManage && (
                <Panel className="mb-6">
                    <PanelHeader
                        title="Logbook settings"
                        actions={
                            active ? (
                                <ActionButton
                                    url={archive(logbook.id).url}
                                    label="Archive logbook"
                                    variant="outline"
                                    confirm="Archive this logbook? Its records are kept and stay readable; nobody can write new entries."
                                />
                            ) : (
                                <ActionButton
                                    url={restore(logbook.id).url}
                                    label="Restore logbook"
                                    variant="outline"
                                />
                            )
                        }
                    />
                    <PanelBody>
                        <LogbookForm
                            initial={logbook}
                            departments={departments}
                            canFileUnderWorkspace={canFileUnderWorkspace}
                            url={update(logbook.id).url}
                            method="put"
                            submitLabel="Save"
                            onDone={() => setEditing(false)}
                        />
                    </PanelBody>
                </Panel>
            )}

            {adding && <NewSheet logbookId={logbook.id} />}

            <Panel>
                <PanelHeader title="Log sheets" />
                {sheets.length === 0 ? (
                    <EmptyState
                        icon={FilePlus2}
                        title="No log sheets yet"
                        action={
                            canManage &&
                            active && (
                                <Button asChild>
                                    <Link href={starters(logbook.id)}>
                                        <Sparkles /> Start from a template
                                    </Link>
                                </Button>
                            )
                        }
                    >
                        A log sheet is the form people fill in: a daily activity
                        log, a generator log, a vehicle log.
                    </EmptyState>
                ) : (
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Log sheet</TableHead>
                                    <TableHead>Version</TableHead>
                                    <TableHead>Review</TableHead>
                                    <TableHead className="text-right">
                                        Entries
                                    </TableHead>
                                    <TableHead>Last entry</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sheets.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell>
                                            <Link
                                                href={sheetShow(s.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {s.name}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {s.code}
                                                {s.description &&
                                                    ` · ${s.description}`}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {s.version ? `v${s.version}` : '—'}
                                            {s.hasDraft && canManage && (
                                                <span className="block text-xs text-amber-700">
                                                    draft changes
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {s.reviewRequired
                                                ? 'Required'
                                                : 'None'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {s.entries}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {formatDateTime(s.lastEntryAt)}
                                        </TableCell>
                                        <TableCell>
                                            <Status value={s.status} />
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {s.canWrite && (
                                                <Button
                                                    asChild
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    <Link
                                                        href={newEntry({
                                                            query: {
                                                                sheet: s.id,
                                                            },
                                                        })}
                                                    >
                                                        <NotebookPen /> New
                                                        entry
                                                    </Link>
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
            </Panel>

            {grants !== null && (
                <AccessPanel
                    logbookId={logbook.id}
                    access={grants}
                    members={members}
                    sheets={sheets.map((s) => ({
                        value: s.id,
                        label: `${s.code} · ${s.name}`,
                    }))}
                />
            )}

            <p className="mt-4 text-xs text-muted-foreground">
                Created by {logbook.creator},{' '}
                {formatDateTime(logbook.createdAt)}.
            </p>
        </>
    );
}

function NewSheet({ logbookId }: { logbookId: string }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: '',
        code: '',
        review_required: true,
    });
    const [busy, setBusy] = useState(false);

    return (
        <Panel className="mb-6">
            <PanelHeader
                title="New log sheet"
                description="Name it and give it a short code; you add its fields next."
            />
            <PanelBody>
                <form
                    className="grid gap-4 sm:grid-cols-3 sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setBusy(true);
                        router.post(storeSheet(logbookId).url, data, {
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    <TextField
                        label="Name"
                        value={data.name}
                        onChange={(name) => setData({ ...data, name })}
                        error={errors.name}
                        placeholder="Generator Operation Log"
                        required
                    />
                    <TextField
                        label="Code"
                        value={data.code}
                        onChange={(code) =>
                            setData({ ...data, code: code.toUpperCase() })
                        }
                        error={errors.code}
                        hint="Starts every reference: GEN-2026-000001"
                        placeholder="GEN"
                        required
                    />
                    <div className="grid gap-3">
                        <Check
                            label="Entries are reviewed by a supervisor"
                            checked={data.review_required}
                            onChange={(review_required) =>
                                setData({ ...data, review_required })
                            }
                        />
                        <Button type="submit" disabled={busy}>
                            Create and add fields
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

function AccessPanel({
    logbookId,
    access,
    members,
    sheets,
}: {
    logbookId: string;
    access: NonNullable<Props['grants']>;
    members: Option[];
    sheets: Option[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const ask = useConfirm();
    const [data, setData] = useState({
        user_id: '',
        role: 'contributor',
        log_sheet_id: '',
    });
    const [busy, setBusy] = useState(false);

    return (
        <Panel className="mt-6">
            <PanelHeader
                title={`Members (${access.length})`}
                description="Only the people added here reach this logbook (managers of its department always do). Each needs a Logbook role that allows what they are added as, for this logbook's department."
            />
            <PanelBody className="grid gap-4">
                {access.length === 0 && (
                    <p className="rounded-md border border-dashed px-3 py-4 text-sm text-muted-foreground">
                        Nobody is a member yet, so only managers see this
                        logbook. Add the people who write, review or read it.
                    </p>
                )}
                {access.length > 0 && (
                    <ul className="divide-y rounded-md border">
                        {access.map((g) => (
                            <li
                                key={g.id}
                                className="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                            >
                                <span>
                                    <span className="font-medium">
                                        {g.user}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        {g.email}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {ROLE_LABELS[g.role]} ·{' '}
                                        {g.sheet ?? 'whole logbook'}
                                    </span>
                                </span>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    aria-label={`Remove ${g.user}`}
                                    onClick={async () => {
                                        if (
                                            await ask({
                                                title: `Remove ${g.user} from this logbook?`,
                                                message:
                                                    'They no longer reach it. Their entries stay.',
                                                confirmLabel: 'Remove',
                                                destructive: true,
                                            })
                                        ) {
                                            router.delete(
                                                accessRoutes.destroy(g.id).url,
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
                <form
                    className="grid gap-3 sm:grid-cols-4 sm:items-end"
                    onSubmit={(e) => {
                        e.preventDefault();
                        setBusy(true);
                        router.post(accessRoutes.store(logbookId).url, data, {
                            preserveScroll: true,
                            onSuccess: () => setData({ ...data, user_id: '' }),
                            onFinish: () => setBusy(false),
                        });
                    }}
                >
                    <SelectField
                        label="Member"
                        value={data.user_id}
                        onChange={(user_id) => setData({ ...data, user_id })}
                        options={members}
                        empty="Choose…"
                        error={errors.user_id}
                        hint="People who have opened Logbook in this workspace."
                    />
                    <SelectField
                        label="As"
                        value={data.role}
                        onChange={(role) => setData({ ...data, role })}
                        options={Object.entries(ROLE_LABELS).map(
                            ([value, label]) => ({ value, label }),
                        )}
                        error={errors.role}
                    />
                    <SelectField
                        label="On"
                        value={data.log_sheet_id}
                        onChange={(log_sheet_id) =>
                            setData({ ...data, log_sheet_id })
                        }
                        options={sheets}
                        empty="The whole logbook"
                        error={errors.log_sheet_id}
                    />
                    <Button
                        type="submit"
                        disabled={busy || data.user_id === ''}
                    >
                        Add
                    </Button>
                </form>
            </PanelBody>
        </Panel>
    );
}
