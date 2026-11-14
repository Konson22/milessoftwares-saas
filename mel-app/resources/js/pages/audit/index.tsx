import { Head, Link, router } from '@inertiajs/react';
import { History } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { selectClass } from '@/components/fields';
import { formatDateTime } from '@/lib/mel';
import { index } from '@/routes/audit';

type Props = {
    type: string | null;
    types: string[];
    events: {
        data: {
            id: string;
            action: string;
            subjectType: string;
            subject: string | null;
            user: string | null;
            details: Record<string, unknown> | null;
            at: string;
        }[];
        links: { url: string | null; label: string; active: boolean }[];
        current_page: number;
        last_page: number;
    };
};

function describe(details: Record<string, unknown> | null): string {
    if (!details) {
        return '';
    }

    return Object.entries(details)
        .filter(([, value]) => value !== null && value !== '')
        .map(
            ([key, value]) =>
                `${key.replaceAll('_', ' ')}: ${
                    typeof value === 'string' ||
                    typeof value === 'number' ||
                    typeof value === 'boolean'
                        ? String(value)
                        : Array.isArray(value)
                          ? value.map((item) => JSON.stringify(item)).join(', ')
                          : JSON.stringify(value)
                }`,
        )
        .join(' · ');
}

/** Laravel's pager labels, as text: "&laquo; Previous" → "« Previous". */
function label(text: string): string {
    return text.replaceAll('&laquo;', '«').replaceAll('&raquo;', '»');
}

export default function Audit({ type, types, events }: Props) {
    return (
        <>
            <Head title="Audit trail" />
            <PageHeader title="Audit trail" />

            <Panel>
                <PanelToolbar>
                    <select
                        aria-label="What changed"
                        value={type ?? ''}
                        onChange={(event) =>
                            router.get(
                                index.url({
                                    query: event.target.value
                                        ? { type: event.target.value }
                                        : {},
                                }),
                            )
                        }
                        className={`${selectClass} w-auto`}
                    >
                        <option value="">Everything</option>
                        {types.map((item) => (
                            <option key={item} value={item}>
                                {item.replaceAll('_', ' ')}
                            </option>
                        ))}
                    </select>
                    <span className="text-xs text-muted-foreground">
                        Kept for good: entries cannot be changed or removed.
                    </span>
                </PanelToolbar>

                {events.data.length === 0 ? (
                    <EmptyState icon={History} title="Nothing yet">
                        Changes to programmes, indicators and results are
                        recorded here.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>Details</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.data.map((event) => (
                                <TableRow key={event.id}>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(event.at)}
                                    </TableCell>
                                    <TableCell>
                                        {event.user ?? 'System'}
                                    </TableCell>
                                    <TableCell>
                                        <span className="font-medium">
                                            {event.action.replaceAll(
                                                /[._]/g,
                                                ' ',
                                            )}
                                        </span>
                                        {event.subject && (
                                            <div className="text-xs text-muted-foreground">
                                                {event.subject}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-md text-xs text-muted-foreground">
                                        {describe(event.details)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                {events.last_page > 1 && (
                    <nav
                        aria-label="Pages"
                        className="flex flex-wrap gap-1 border-t px-3 py-2 text-sm"
                    >
                        {events.links.map((link, position) =>
                            link.url ? (
                                <Link
                                    key={position}
                                    href={link.url}
                                    preserveScroll
                                    className={`px-2 py-1 ${link.active ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'}`}
                                >
                                    {label(link.label)}
                                </Link>
                            ) : (
                                <span
                                    key={position}
                                    className="px-2 py-1 text-muted-foreground"
                                >
                                    {label(link.label)}
                                </span>
                            ),
                        )}
                    </nav>
                )}
            </Panel>
        </>
    );
}
