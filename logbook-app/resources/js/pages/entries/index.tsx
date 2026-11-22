import { Head, Link } from '@inertiajs/react';
import { NotebookPen, Plus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import type { FilterOptions, Filters } from '@/components/entries-table';
import {
    EntriesTable,
    EntryFilters,
    Pagination,
} from '@/components/entries-table';
import type { EntryRow, Paginated, WritableSheet } from '@/lib/logbook';
import { create, index } from '@/routes/entries';

type Props = {
    scope: 'mine' | 'all';
    filters: Filters;
    entries: Paginated<EntryRow>;
    options: FilterOptions;
    writableSheets: WritableSheet[];
};

export default function Entries({
    scope,
    filters,
    entries,
    options,
    writableSheets,
}: Props) {
    const tab = (value: 'mine' | 'all', label: string) => (
        <Link
            href={index({ query: value === 'all' ? { scope: 'all' } : {} })}
            className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                scope === value
                    ? 'bg-primary text-primary-foreground'
                    : 'text-muted-foreground hover:bg-muted'
            }`}
            aria-current={scope === value ? 'page' : undefined}
        >
            {label}
        </Link>
    );

    return (
        <>
            <Head title={scope === 'mine' ? 'My entries' : 'All entries'} />
            <PageHeader
                title={scope === 'mine' ? 'My entries' : 'All entries'}
                description={
                    scope === 'mine'
                        ? 'Your drafts, returned entries and everything you submitted.'
                        : 'Every submitted entry you can read.'
                }
                actions={
                    writableSheets.length > 0 && (
                        <Button asChild>
                            <Link href={create()}>
                                <Plus /> New entry
                            </Link>
                        </Button>
                    )
                }
            />
            <div className="mb-4 flex gap-1">
                {tab('mine', 'Mine')}
                {tab('all', 'All I can read')}
            </div>
            <Panel>
                <PanelToolbar>
                    <EntryFilters
                        url={index().url}
                        filters={filters}
                        options={options}
                        extra={{ scope: scope === 'all' ? 'all' : undefined }}
                    />
                </PanelToolbar>
                {entries.data.length === 0 ? (
                    <EmptyState icon={NotebookPen} title="No entries">
                        {scope === 'mine'
                            ? 'Entries you write appear here, drafts first.'
                            : 'No submitted entry matches these filters.'}
                    </EmptyState>
                ) : (
                    <>
                        <EntriesTable
                            entries={entries.data}
                            showCreator={scope === 'all'}
                        />
                        <Pagination page={entries} />
                    </>
                )}
            </Panel>
        </>
    );
}
