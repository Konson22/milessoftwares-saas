import { Head, useForm } from '@inertiajs/react';
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
import { selectClass } from '@/mel/components/fields';
import { COLLECTED } from '@/mel/lib/collect';
import type { CollectedStatus } from '@/mel/lib/collect';
import type { Option } from '@/mel/lib/mel';
import { formatDateTime } from '@/mel/lib/mel';
import { download, store } from '@/routes/mel/collect/exports';

type Props = {
    exports: {
        id: string;
        form: string;
        format: 'csv' | 'xlsx';
        answers: 'current' | 'original';
        status: 'queued' | 'ready' | 'failed' | 'expired';
        rows: number | null;
        personal: boolean;
        createdAt: string;
        expiresAt: string | null;
        downloads: number;
    }[];
    forms: Option[];
    statuses: CollectedStatus[];
    personal: boolean;
};

const STATUS_TONE = {
    queued: 'info',
    ready: 'success',
    failed: 'danger',
    expired: 'neutral',
} as const;

export default function Exports({ exports, forms, statuses, personal }: Props) {
    const form = useForm<{
        form: string;
        format: 'csv' | 'xlsx';
        answers: 'current' | 'original';
        statuses: string[];
        from: string;
        to: string;
    }>({
        form: forms[0]?.value ?? '',
        format: 'xlsx',
        answers: 'current',
        statuses: ['accepted'],
        from: '',
        to: '',
    });

    return (
        <>
            <Head title="Exports" />
            <PageHeader
                title="Exports"
                description="Collected data as a spreadsheet: one row per submission. Files are kept for 7 days, and only you can download yours."
            />
            <Panel className="mb-3">
                <PanelHeader
                    title="New export"
                    description={
                        personal
                            ? 'Personal answers are included: handle the file accordingly. Photos are never exported.'
                            : 'Personal answers are left out: you do not have access to personal data.'
                    }
                />
                <PanelBody>
                    <form
                        className="grid gap-3 md:grid-cols-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform(({ form: _form, ...data }) => ({
                                ...data,
                                from: data.from || null,
                                to: data.to || null,
                            }));
                            form.submit(store(form.data.form));
                        }}
                    >
                        <label className="grid gap-1 text-sm">
                            <span>Form</span>
                            <select
                                className={selectClass}
                                value={form.data.form}
                                onChange={(e) =>
                                    form.setData('form', e.target.value)
                                }
                            >
                                {forms.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            <span>Format</span>
                            <select
                                className={selectClass}
                                value={form.data.format}
                                onChange={(e) =>
                                    form.setData(
                                        'format',
                                        e.target.value as 'csv' | 'xlsx',
                                    )
                                }
                            >
                                <option value="xlsx">Excel (.xlsx)</option>
                                <option value="csv">CSV</option>
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            <span>Answers</span>
                            <select
                                className={selectClass}
                                value={form.data.answers}
                                onChange={(e) =>
                                    form.setData(
                                        'answers',
                                        e.target.value as
                                            | 'current'
                                            | 'original',
                                    )
                                }
                            >
                                <option value="current">
                                    With corrections applied
                                </option>
                                <option value="original">As collected</option>
                            </select>
                        </label>
                        <fieldset className="grid gap-1 text-sm md:col-span-2">
                            <legend className="mb-1">Statuses</legend>
                            <div className="flex flex-wrap gap-3">
                                {statuses.map((status) => (
                                    <label
                                        key={status}
                                        className="flex items-center gap-1.5"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.data.statuses.includes(
                                                status,
                                            )}
                                            onChange={(e) =>
                                                form.setData(
                                                    'statuses',
                                                    e.target.checked
                                                        ? [
                                                              ...form.data
                                                                  .statuses,
                                                              status,
                                                          ]
                                                        : form.data.statuses.filter(
                                                              (s) =>
                                                                  s !== status,
                                                          ),
                                                )
                                            }
                                        />
                                        {COLLECTED[status].label}
                                    </label>
                                ))}
                            </div>
                        </fieldset>
                        <div className="grid grid-cols-2 gap-2">
                            <label className="grid gap-1 text-sm">
                                <span>Completed from</span>
                                <input
                                    type="date"
                                    className={selectClass}
                                    value={form.data.from}
                                    onChange={(e) =>
                                        form.setData('from', e.target.value)
                                    }
                                />
                            </label>
                            <label className="grid gap-1 text-sm">
                                <span>to</span>
                                <input
                                    type="date"
                                    className={selectClass}
                                    value={form.data.to}
                                    onChange={(e) =>
                                        form.setData('to', e.target.value)
                                    }
                                />
                            </label>
                        </div>
                        {Object.values(form.errors).map((message) => (
                            <p
                                key={message}
                                className="text-sm text-destructive md:col-span-3"
                            >
                                {message}
                            </p>
                        ))}
                        <div className="md:col-span-3">
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || form.data.form === ''
                                }
                            >
                                Make the export
                            </Button>
                        </div>
                    </form>
                </PanelBody>
            </Panel>
            <Panel>
                <PanelHeader title="Your exports" />
                {exports.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        None yet.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Form</TableHead>
                                <TableHead>Made</TableHead>
                                <TableHead>Rows</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {exports.map((row) => (
                                <TableRow key={row.id}>
                                    <TableCell>
                                        {row.form}
                                        <div className="text-xs text-muted-foreground">
                                            {row.format.toUpperCase()} ·{' '}
                                            {row.answers === 'original'
                                                ? 'as collected'
                                                : 'corrected'}
                                            {row.personal &&
                                                ' · includes personal data'}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDateTime(row.createdAt)}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {row.rows ?? '—'}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={STATUS_TONE[row.status]}
                                        >
                                            {row.status === 'ready'
                                                ? `Until ${formatDateTime(row.expiresAt)}`
                                                : row.status}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {row.status === 'ready' && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                asChild
                                            >
                                                <a href={download.url(row.id)}>
                                                    Download
                                                </a>
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
