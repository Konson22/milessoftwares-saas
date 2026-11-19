import { Head, Link } from '@inertiajs/react';
import { BookOpen, Lock, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { LogbookForm } from '@/components/logbook-form';
import { LogbookIcon } from '@/components/logbook-icon';
import { index, show, store } from '@/routes/logbooks';

type Props = {
    archived: boolean;
    logbooks: {
        id: string;
        name: string;
        description: string | null;
        icon: string | null;
        restricted: boolean;
        status: string;
        sheets: number;
        entries: number;
    }[];
    canManage: boolean;
};

export default function Logbooks({ archived, logbooks, canManage }: Props) {
    const [creating, setCreating] = useState(false);

    return (
        <>
            <Head title="Logbooks" />
            <PageHeader
                title={archived ? 'Archived logbooks' : 'Logbooks'}
                description="Each logbook groups related log sheets: IT operations, vehicles, security, the generator room."
                actions={
                    <>
                        <Button asChild variant="ghost">
                            <Link
                                href={index({
                                    query: archived ? {} : { archived: 1 },
                                })}
                            >
                                {archived ? 'Active logbooks' : 'Archived'}
                            </Link>
                        </Button>
                        {canManage && !archived && (
                            <Button onClick={() => setCreating(!creating)}>
                                <Plus /> New logbook
                            </Button>
                        )}
                    </>
                }
            />

            {creating && (
                <Panel className="mb-6">
                    <PanelHeader title="New logbook" />
                    <PanelBody>
                        <LogbookForm
                            initial={{
                                name: '',
                                description: null,
                                icon: 'book',
                                restricted: false,
                            }}
                            url={store().url}
                            method="post"
                            submitLabel="Create logbook"
                        />
                    </PanelBody>
                </Panel>
            )}

            {logbooks.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={BookOpen}
                        title={
                            archived
                                ? 'No archived logbooks'
                                : 'No logbooks yet'
                        }
                        action={
                            canManage &&
                            !archived && (
                                <Button onClick={() => setCreating(true)}>
                                    <Plus /> Create the first logbook
                                </Button>
                            )
                        }
                    >
                        {canManage
                            ? 'Create a logbook, then add log sheets from a starter template or from scratch.'
                            : 'Logbooks you can reach appear here.'}
                    </EmptyState>
                </Panel>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {logbooks.map((l) => (
                        <Link
                            key={l.id}
                            href={show(l.id)}
                            className="group flex flex-col rounded-lg border bg-card shadow-[0_1px_2px_#e3e3e3] transition-[border-color,box-shadow] hover:border-[#c9d8ff] hover:shadow-[0_2px_6px_#d6d6d6] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
                        >
                            <span className="flex items-start gap-3 p-4">
                                <span className="grid size-10 shrink-0 place-items-center rounded-md bg-[#e8efff] text-primary">
                                    <LogbookIcon icon={l.icon} />
                                </span>
                                <span className="grid min-w-0 flex-1 gap-1">
                                    <span className="flex items-center gap-2">
                                        <span className="truncate font-semibold group-hover:text-primary">
                                            {l.name}
                                        </span>
                                        {l.restricted && (
                                            <span className="ml-auto inline-flex shrink-0 items-center gap-1 rounded-sm border px-1.5 text-xs text-muted-foreground">
                                                <Lock
                                                    className="size-3"
                                                    aria-hidden
                                                />
                                                Restricted
                                            </span>
                                        )}
                                    </span>
                                    <span className="line-clamp-2 min-h-10 text-sm text-muted-foreground">
                                        {l.description ?? 'No description.'}
                                    </span>
                                </span>
                            </span>
                            <span className="mt-auto flex items-center gap-4 border-t border-[#ebebeb] bg-[#fafafa] px-4 py-2.5 text-xs text-muted-foreground">
                                <span>
                                    <span className="font-semibold text-foreground tabular-nums">
                                        {l.sheets}
                                    </span>{' '}
                                    {l.sheets === 1
                                        ? 'log sheet'
                                        : 'log sheets'}
                                </span>
                                <span>
                                    <span className="font-semibold text-foreground tabular-nums">
                                        {l.entries}
                                    </span>{' '}
                                    {l.entries === 1 ? 'entry' : 'entries'}
                                </span>
                            </span>
                        </Link>
                    ))}
                </div>
            )}
        </>
    );
}
