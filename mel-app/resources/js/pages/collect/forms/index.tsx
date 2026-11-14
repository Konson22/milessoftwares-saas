import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
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
import { selectClass } from '@/components/fields';
import { FORM_STATUS, PURPOSES } from '@/lib/collect';
import type { FormStatus } from '@/lib/collect';
import { formatDate } from '@/lib/mel';
import type { Option } from '@/lib/mel';
import { create, index, show } from '@/routes/collect/forms';

type Props = {
    filters: { show: string; q: string; programme: string | null };
    forms: {
        id: string;
        name: string;
        purpose: string;
        status: FormStatus;
        programme: string | null;
        unit: string | null;
        version: number | null;
        unpublishedChanges: boolean;
        submissions: number;
        toReview: number;
        collectors: number;
        updatedAt: string;
    }[];
    programmes: Option[];
    canCreate: boolean;
};

const SHOW: { value: string; label: string }[] = [
    { value: 'current', label: 'Current' },
    { value: 'draft', label: 'Drafts' },
    { value: 'published', label: 'Published' },
    { value: 'archived', label: 'Archived' },
];

export default function Forms({ filters, forms, programmes, canCreate }: Props) {
    const [search, setSearch] = useState(filters.q);
    const filter = (changes: Partial<Props['filters']>) =>
        router.get(
            index.url({
                query: Object.fromEntries(
                    Object.entries({ ...filters, ...changes }).filter(
                        ([key, value]) =>
                            value !== null &&
                            value !== '' &&
                            !(key === 'show' && value === 'current'),
                    ),
                ),
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );

    const add = canCreate ? (
        <Button asChild>
            <Link href={create()}>New form</Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="Forms & surveys" />
            <PageHeader
                title="Forms & surveys"
                description="Questionnaires collected in the field, offline or online, and reviewed here."
                actions={add}
            />

            <Panel>
                <PanelToolbar className="flex flex-wrap items-center gap-2">
                    <nav className="flex gap-1 text-sm" aria-label="Show">
                        {SHOW.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => filter({ show: option.value })}
                                className={`px-2 py-1 ${filters.show === option.value ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'}`}
                                aria-pressed={filters.show === option.value}
                            >
                                {option.label}
                            </button>
                        ))}
                    </nav>
                    <form
                        className="ml-auto flex flex-wrap gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            filter({ q: search });
                        }}
                    >
                        {programmes.length > 0 && (
                            <select
                                aria-label="Programme"
                                className={`${selectClass} w-auto`}
                                value={filters.programme ?? ''}
                                onChange={(event) =>
                                    filter({ programme: event.target.value })
                                }
                            >
                                <option value="">All programmes</option>
                                {programmes.map((programme) => (
                                    <option
                                        key={programme.value}
                                        value={programme.value}
                                    >
                                        {programme.label}
                                    </option>
                                ))}
                            </select>
                        )}
                        <SearchField
                            aria-label="Search forms"
                            placeholder="Search forms"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </form>
                </PanelToolbar>

                {forms.length === 0 ? (
                    <EmptyState
                        icon={ClipboardList}
                        title={
                            filters.q !== '' || filters.show !== 'current'
                                ? 'No forms match'
                                : 'No forms yet'
                        }
                        action={filters.show === 'current' ? add : undefined}
                    >
                        {canCreate
                            ? 'Build a questionnaire, publish it, and assign it to the people collecting in the field.'
                            : 'Forms you can see appear here.'}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Form</TableHead>
                                <TableHead>Programme</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Collectors
                                </TableHead>
                                <TableHead className="text-right">
                                    Submissions
                                </TableHead>
                                <TableHead className="text-right">
                                    To review
                                </TableHead>
                                <TableHead>Changed</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {forms.map((form) => (
                                <TableRow key={form.id}>
                                    <TableCell>
                                        <Link
                                            href={show(form.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {form.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {PURPOSES[form.purpose] ??
                                                form.purpose}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {form.programme ?? 'None'}
                                        {form.unit && ` · ${form.unit}`}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1">
                                            <StatusBadge
                                                tone={
                                                    FORM_STATUS[form.status]
                                                        .tone
                                                }
                                            >
                                                {FORM_STATUS[form.status].label}
                                                {form.version !== null &&
                                                    form.status !== 'draft' &&
                                                    ` · v${form.version}`}
                                            </StatusBadge>
                                            {form.unpublishedChanges &&
                                                form.version !== null && (
                                                    <StatusBadge tone="warning">
                                                        Unpublished changes
                                                    </StatusBadge>
                                                )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {form.collectors}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {form.submissions}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {form.toReview > 0 ? (
                                            <span className="font-medium">
                                                {form.toReview}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                0
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(form.updatedAt)}
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
