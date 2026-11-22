import { Head, Link } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { EntriesTable } from '@/components/entries-table';
import { Status } from '@/components/fields';
import type { EntryRow } from '@/lib/logbook';
import { formatDateTime } from '@/lib/logbook';
import { show } from '@/routes/entries';

type Props = {
    pending: (EntryRow & { revision: number })[];
    decided: {
        id: string;
        entryId: string;
        reference: string;
        sheet: string;
        decision: string;
        revision: number;
        decidedAt: string;
    }[];
};

export default function Reviews({ pending, decided }: Props) {
    return (
        <>
            <Head title="Reviews" />
            <PageHeader
                title="Reviews"
                description="Submitted entries waiting on your review, oldest first."
            />
            <Panel>
                <PanelHeader title={`Waiting on review (${pending.length})`} />
                {pending.length === 0 ? (
                    <EmptyState icon={ClipboardCheck} title="Nothing waiting">
                        Submitted entries you can review appear here.
                    </EmptyState>
                ) : (
                    <EntriesTable entries={pending} />
                )}
            </Panel>
            {decided.length > 0 && (
                <Panel className="mt-6">
                    <PanelHeader title="Your recent decisions" />
                    <ul className="divide-y">
                        {decided.map((d) => (
                            <li
                                key={d.id}
                                className="flex items-center justify-between gap-3 px-4 py-2 text-sm"
                            >
                                <span>
                                    <Link
                                        href={show(d.entryId)}
                                        className="font-medium hover:underline"
                                    >
                                        {d.reference}
                                    </Link>{' '}
                                    · {d.sheet} · revision {d.revision}
                                    <span className="block text-xs text-muted-foreground">
                                        {formatDateTime(d.decidedAt)}
                                    </span>
                                </span>
                                <Status value={d.decision} />
                            </li>
                        ))}
                    </ul>
                </Panel>
            )}
        </>
    );
}
