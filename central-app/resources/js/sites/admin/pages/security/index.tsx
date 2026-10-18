import { Form, Head, Link } from '@inertiajs/react';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';

type Page<T> = {
    data: T[];
    links: { url: string | null; label: string; active: boolean }[];
};

type Props = {
    filters: { event: string; action: string };
    eventTypes: string[];
    authenticationEvents: Page<{
        id: string;
        event: string;
        user: string | null;
        host: string | null;
        ip: string | null;
        occurredAt: string;
    }>;
    auditLogs: Page<{
        sequence: number;
        action: string;
        actorType: string;
        actorId: string | null;
        organizationId: string | null;
        subject: string | null;
        occurredAt: string;
    }>;
    chainCheck: {
        intact: boolean;
        firstBrokenSequence: number | null;
        checkedAt: string;
    } | null;
};

const when = (iso: string) => new Date(iso).toLocaleString();

export default function Security({
    filters,
    eventTypes,
    authenticationEvents,
    auditLogs,
    chainCheck,
}: Props) {
    return (
        <>
            <Head title="Security" />

            <h1 className="text-2xl font-semibold">Security</h1>

            <section className="mt-4 flex flex-wrap items-center gap-4 rounded-lg border p-4">
                <div className="flex-1">
                    <h2 className="font-medium">Audit chain</h2>
                    <p className="text-sm text-muted-foreground">
                        Each audit record is hash-chained to the previous one.
                        Verification walks the whole chain.
                    </p>
                    {chainCheck && (
                        <p
                            role="status"
                            className={`mt-2 text-sm font-medium ${chainCheck.intact ? 'text-green-700 dark:text-green-400' : 'text-destructive'}`}
                        >
                            {chainCheck.intact
                                ? `Intact (checked ${when(chainCheck.checkedAt)})`
                                : `Broken from record #${chainCheck.firstBrokenSequence} — escalate now`}
                        </p>
                    )}
                </div>
                <Form action="/security/verify-chain" method="post">
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Verify chain
                        </Button>
                    )}
                </Form>
            </section>

            <div className="mt-5 grid gap-5 xl:grid-cols-2">
                <section>
                    <h2 className="text-lg font-medium">Sign-in events</h2>
                    <Form
                        action="/security"
                        method="get"
                        className="mt-3 flex gap-2"
                    >
                        <select
                            name="event"
                            defaultValue={filters.event}
                            aria-label="Event type"
                            className="h-9 rounded-md border border-input bg-transparent px-2 text-sm"
                        >
                            <option value="">All events</option>
                            {eventTypes.map((type) => (
                                <option key={type}>{type}</option>
                            ))}
                        </select>
                        <input
                            type="hidden"
                            name="action"
                            value={filters.action}
                        />
                        <Button type="submit" size="sm" variant="outline">
                            Filter
                        </Button>
                    </Form>
                    <ul className="mt-3 divide-y rounded-lg border text-sm">
                        {authenticationEvents.data.map((record) => (
                            <li key={record.id} className="p-3">
                                <span className="font-medium">
                                    {record.event}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    {record.user ?? 'unknown account'} ·{' '}
                                    {record.ip} · {record.host} ·{' '}
                                    {when(record.occurredAt)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <Pagination links={authenticationEvents.links} />
                </section>

                <section>
                    <h2 className="text-lg font-medium">Audit log</h2>
                    <Form
                        action="/security"
                        method="get"
                        className="mt-3 flex gap-2"
                    >
                        <Input
                            name="action"
                            defaultValue={filters.action}
                            placeholder="Action prefix, e.g. support."
                            aria-label="Action prefix"
                            className="h-9 max-w-xs"
                        />
                        <input
                            type="hidden"
                            name="event"
                            value={filters.event}
                        />
                        <Button type="submit" size="sm" variant="outline">
                            Filter
                        </Button>
                    </Form>
                    <ul className="mt-3 divide-y rounded-lg border text-sm">
                        {auditLogs.data.map((log) => (
                            <li key={log.sequence} className="p-3">
                                <span className="text-muted-foreground">
                                    #{log.sequence}
                                </span>{' '}
                                <span className="font-medium">
                                    {log.action}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    by {log.actorType}
                                    {log.organizationId &&
                                        ` · org ${log.organizationId.slice(0, 8)}`}{' '}
                                    · {when(log.occurredAt)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <Pagination links={auditLogs.links} />
                </section>
            </div>
        </>
    );
}

function Pagination({ links }: { links: Page<unknown>['links'] }) {
    return (
        <nav className="mt-3 flex flex-wrap gap-1" aria-label="Pages">
            {links.map((link) =>
                link.url ? (
                    <Link
                        key={link.label}
                        href={link.url}
                        preserveScroll
                        className={`rounded px-2 py-1 text-sm ${link.active ? 'bg-muted font-medium' : ''}`}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : null,
            )}
        </nav>
    );
}
