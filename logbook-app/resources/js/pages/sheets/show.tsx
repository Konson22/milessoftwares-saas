import { Head, Link, router, usePage } from '@inertiajs/react';
import { Copy, NotebookPen, Pencil } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { ActionButton } from '@/components/actions';
import { EntriesTable } from '@/components/entries-table';
import { SelectField, Status, TextField } from '@/components/fields';
import type { EntryRow, Option } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import { create as newEntry, index as entries } from '@/routes/entries';
import { show as logbookShow } from '@/routes/logbooks';
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
    canWrite: boolean;
    canManage: boolean;
    logbooks: Option[];
};

export default function SheetShow({
    sheet,
    entries: rows,
    versions,
    canWrite,
    canManage,
    logbooks,
}: Props) {
    const [copying, setCopying] = useState(false);

    return (
        <>
            <Head title={sheet.name} />
            <PageHeader
                title={sheet.name}
                description={
                    <>
                        <Link
                            href={logbookShow(sheet.logbook.id)}
                            className="hover:underline"
                        >
                            {sheet.logbook.name}
                        </Link>{' '}
                        · {sheet.code} ·{' '}
                        {sheet.version
                            ? `version ${sheet.version}`
                            : 'not published'}{' '}
                        ·{' '}
                        {sheet.reviewRequired
                            ? 'entries are reviewed'
                            : 'no review step'}
                    </>
                }
                badges={<Status value={sheet.status} />}
                actions={
                    <>
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
                    </>
                }
            />

            {sheet.description && (
                <p className="mb-4 text-sm text-muted-foreground">
                    {sheet.description}
                </p>
            )}

            <div className="grid gap-6 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Latest entries"
                        actions={
                            <Button asChild size="sm" variant="ghost">
                                <Link
                                    href={entries({
                                        query: {
                                            scope: 'all',
                                            sheet: sheet.id,
                                        },
                                    })}
                                >
                                    All entries
                                </Link>
                            </Button>
                        }
                    />
                    {rows.length === 0 ? (
                        <EmptyState icon={NotebookPen} title="No entries yet">
                            {sheet.version
                                ? 'Submitted entries appear here.'
                                : 'Publish the template so people can write entries.'}
                        </EmptyState>
                    ) : (
                        <EntriesTable entries={rows} />
                    )}
                </Panel>

                <div className="grid content-start gap-6">
                    {sheet.instructions && (
                        <Panel>
                            <PanelHeader title="Instructions" />
                            <PanelBody>
                                <p className="text-sm whitespace-pre-line">
                                    {sheet.instructions}
                                </p>
                            </PanelBody>
                        </Panel>
                    )}
                    <Panel>
                        <PanelHeader
                            title="Template versions"
                            description="Published versions never change."
                        />
                        <ul className="divide-y">
                            {versions.map((v) => (
                                <li
                                    key={v.id}
                                    className="flex items-center justify-between px-4 py-2 text-sm"
                                >
                                    <span>
                                        Version {v.version}
                                        <span className="block text-xs text-muted-foreground">
                                            {v.fields} fields
                                            {v.publishedAt &&
                                                ` · ${formatDateTime(v.publishedAt)}`}
                                        </span>
                                    </span>
                                    <Status value={v.status} />
                                </li>
                            ))}
                        </ul>
                    </Panel>
                    {canManage && (
                        <Panel>
                            <PanelHeader title="Manage" />
                            <PanelBody className="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setCopying(!copying)}
                                >
                                    <Copy /> Duplicate
                                </Button>
                                {sheet.hasDraft && sheet.version !== null && (
                                    <ActionButton
                                        url={draft.destroy(sheet.id).url}
                                        method="delete"
                                        label="Discard draft"
                                        variant="outline"
                                        confirm="Discard the unpublished changes to this template?"
                                    />
                                )}
                                {sheet.status === 'archived' ? (
                                    <ActionButton
                                        url={restore(sheet.id).url}
                                        label="Restore"
                                        variant="outline"
                                    />
                                ) : (
                                    <ActionButton
                                        url={archive(sheet.id).url}
                                        label="Archive"
                                        variant="outline"
                                        confirm="Archive this log sheet? Its entries are kept and stay readable; nobody can write new ones."
                                    />
                                )}
                                {copying && (
                                    <DuplicateForm
                                        sheetId={sheet.id}
                                        logbookId={sheet.logbook.id}
                                        name={sheet.name}
                                        logbooks={logbooks}
                                    />
                                )}
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            </div>
        </>
    );
}

function DuplicateForm({
    sheetId,
    logbookId,
    name,
    logbooks,
}: {
    sheetId: string;
    logbookId: string;
    name: string;
    logbooks: Option[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [data, setData] = useState({
        name: `${name} (copy)`,
        code: '',
        logbook_id: logbookId,
    });

    return (
        <form
            className="grid w-full gap-3 rounded-md border p-3"
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
            <Button type="submit" size="sm">
                Create the copy
            </Button>
        </form>
    );
}
