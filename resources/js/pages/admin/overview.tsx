import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';

type Service = {
    code: string;
    name: string;
    status: 'ok' | 'degraded' | 'down' | 'unknown';
    version: string | null;
    message: string | null;
    reportedAt: string | null;
    statusSince: string | null;
};

type Props = {
    organizations: Partial<
        Record<'pending' | 'active' | 'suspended' | 'archived', number>
    >;
    pendingSupportRequests: number;
    activeSupportGrants: number;
    services: Service[];
};

const statusStyle: Record<
    Service['status'],
    'secondary' | 'outline' | 'destructive'
> = {
    ok: 'secondary',
    degraded: 'outline',
    down: 'destructive',
    unknown: 'outline',
};

export default function AdminOverview({
    organizations,
    pendingSupportRequests,
    activeSupportGrants,
    services,
}: Props) {
    const tiles = [
        { label: 'Active organizations', value: organizations.active ?? 0 },
        {
            label: 'Suspended organizations',
            value: organizations.suspended ?? 0,
        },
        {
            label: 'Support requests awaiting approval',
            value: pendingSupportRequests,
        },
        { label: 'Support grants in effect', value: activeSupportGrants },
    ];

    return (
        <>
            <Head title="System Administration" />

            <h1 className="text-2xl font-semibold">Overview</h1>

            <dl className="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {tiles.map((tile) => (
                    <div key={tile.label} className="rounded-lg border p-4">
                        <dt className="text-sm text-muted-foreground">
                            {tile.label}
                        </dt>
                        <dd className="mt-1 text-2xl font-semibold tabular-nums">
                            {tile.value}
                        </dd>
                    </div>
                ))}
            </dl>

            <section className="mt-10" aria-labelledby="services-heading">
                <h2 id="services-heading" className="text-lg font-medium">
                    Products and shared modules
                </h2>
                <p className="text-sm text-muted-foreground">
                    Each service reports its own health; no report for five
                    minutes shows as unknown.
                </p>
                {services.length === 0 ? (
                    <p className="mt-3 text-sm text-muted-foreground">
                        No products registered yet.
                    </p>
                ) : (
                    <ul className="mt-3 divide-y rounded-lg border text-sm">
                        {services.map((service) => (
                            <li
                                key={service.code}
                                className="flex flex-wrap items-center gap-3 p-3"
                            >
                                <Badge variant={statusStyle[service.status]}>
                                    {service.status}
                                </Badge>
                                <span className="font-medium">
                                    {service.name}
                                </span>
                                <span className="flex-1 text-muted-foreground">
                                    {service.version && `v${service.version}`}
                                    {service.message && ` · ${service.message}`}
                                    {service.reportedAt &&
                                        ` · reported ${new Date(service.reportedAt).toLocaleString()}`}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </section>
        </>
    );
}
