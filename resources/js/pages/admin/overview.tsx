import { Head } from '@inertiajs/react';

type Props = {
    organizations: Partial<
        Record<'pending' | 'active' | 'suspended' | 'archived', number>
    >;
    pendingSupportRequests: number;
    activeSupportGrants: number;
};

export default function AdminOverview({
    organizations,
    pendingSupportRequests,
    activeSupportGrants,
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
        </>
    );
}
