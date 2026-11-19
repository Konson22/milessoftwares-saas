import { Head, Link } from '@inertiajs/react';
import { NotebookPen } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import type { WritableSheet } from '@/lib/logbook';
import { create } from '@/routes/entries';

export default function Pick({ sheets }: { sheets: WritableSheet[] }) {
    const byLogbook = sheets.reduce<Record<string, WritableSheet[]>>(
        (groups, sheet) => {
            (groups[sheet.logbook] ??= []).push(sheet);

            return groups;
        },
        {},
    );

    return (
        <>
            <Head title="New entry" />
            <PageHeader
                title="New entry"
                description="Choose the log sheet to write on."
            />
            {sheets.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={NotebookPen}
                        title="No log sheet open to you"
                    >
                        You can write on published log sheets of logbooks you
                        contribute to. Ask a logbook manager for access.
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-6">
                    {Object.entries(byLogbook).map(([logbook, list]) => (
                        <section key={logbook}>
                            <h2 className="mb-2 text-sm font-semibold text-muted-foreground">
                                {logbook}
                            </h2>
                            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {list.map((sheet) => (
                                    <Link
                                        key={sheet.id}
                                        href={create({
                                            query: { sheet: sheet.id },
                                        })}
                                        className="rounded-lg border bg-card p-4 hover:border-primary"
                                    >
                                        <span className="block font-medium">
                                            {sheet.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {sheet.code}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </>
    );
}
