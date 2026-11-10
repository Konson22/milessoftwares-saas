import { Head, Link, router } from '@inertiajs/react';
import { ScrollText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Select } from '@/components/field';
import { formatDateTime } from '@/lib/status';
import { index } from '@/routes/admin/audit';
import type { Option } from '@/types';

type AuditEvent = {
    id: string;
    action: string;
    by: string | null;
    subject: string | null;
    properties: Record<string, unknown> | null;
    ip: string | null;
    at: string;
};

/** "invitation.accepted" → "Invitation accepted". */
function describe(action: string): string {
    const text = action.replace(/[._]/g, ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}

/** A value in a change, readable. */
function shown(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return 'nothing';
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/** What an event says: each change as field: before → after, then the rest. */
function details(properties: Record<string, unknown>): string {
    const { before, after, ...rest } = properties;
    const parts: string[] = [];
    if (
        after &&
        typeof after === 'object' &&
        !Array.isArray(after) &&
        (before === undefined ||
            (typeof before === 'object' && !Array.isArray(before)))
    ) {
        const was = (before ?? {}) as Record<string, unknown>;
        for (const [field, value] of Object.entries(after)) {
            parts.push(`${field}: ${shown(was[field])} → ${shown(value)}`);
        }
    } else if (before !== undefined || after !== undefined) {
        parts.push(`${shown(before)} → ${shown(after)}`);
    }
    if (Object.keys(rest).length > 0) {
        parts.push(JSON.stringify(rest));
    }

    return parts.join('; ');
}

/**
 * Administration → Audit log: what happened here and who did it.
 */
export default function Audit({
    events,
    members,
    filters,
}: {
    events: {
        data: AuditEvent[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
    members: Option[];
    filters: { user: string };
}) {
    const page = (number: number) =>
        index({
            query: {
                ...(filters.user ? { user: filters.user } : {}),
                page: number,
            },
        });

    return (
        <>
            <Head title="Audit log" />
            <PageHeader
                title="Audit log"
                description="Sign-ins, membership and role changes, settings: newest first. Entries cannot be edited or deleted."
            />

            <Panel>
                <PanelToolbar>
                    <label
                        htmlFor="user"
                        className="text-sm text-muted-foreground"
                    >
                        Member
                    </label>
                    <Select
                        id="user"
                        className="w-56"
                        value={filters.user}
                        placeholder="Everyone"
                        options={members}
                        onChange={(event) =>
                            router.get(
                                index({
                                    query: event.target.value
                                        ? { user: event.target.value }
                                        : {},
                                }).url,
                                {},
                                { preserveState: true },
                            )
                        }
                    />
                    <span className="ml-auto text-sm text-muted-foreground">
                        {events.total}{' '}
                        {events.total === 1 ? 'entry' : 'entries'}
                    </span>
                </PanelToolbar>

                {events.data.length === 0 ? (
                    <EmptyState
                        icon={ScrollText}
                        title="Nothing recorded yet"
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When</TableHead>
                                <TableHead>What</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>Details</TableHead>
                                <TableHead>IP</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {events.data.map((event) => (
                                <TableRow key={event.id}>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(event.at)}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {describe(event.action)}
                                    </TableCell>
                                    <TableCell>
                                        {event.by ?? 'System'}
                                    </TableCell>
                                    <TableCell className="max-w-md">
                                        {event.properties &&
                                        Object.keys(event.properties).length >
                                            0 ? (
                                            <code className="block truncate text-xs text-muted-foreground">
                                                {details(event.properties)}
                                            </code>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-xs text-muted-foreground">
                                        {event.ip ?? '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}

                {events.lastPage > 1 && (
                    <div className="flex items-center justify-between border-t px-3 py-2 text-sm">
                        <span className="text-muted-foreground">
                            Page {events.currentPage} of {events.lastPage}
                        </span>
                        <div className="flex gap-2">
                            {events.currentPage > 1 && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={page(events.currentPage - 1)}>
                                        Newer
                                    </Link>
                                </Button>
                            )}
                            {events.currentPage < events.lastPage && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={page(events.currentPage + 1)}>
                                        Older
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>
                )}
            </Panel>
        </>
    );
}
