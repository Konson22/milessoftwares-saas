import { Link } from '@inertiajs/react';
import { AppWindow } from 'lucide-react';
import { Button } from '@/shared/components/ui/button';
import { settings as organizationSettings } from '@/routes/organization';
import { subscription as organizationSubscription } from '@/routes/organization';

export type CurrentOrganization = {
    displayName: string;
    workspaceUrl: string | null;
    applications: {
        code: string;
        name: string;
        description: string | null;
        href: string;
    }[];
    counts: { members: number; branches: number; pendingInvitations: number };
    isOwner: boolean;
    canManageAccess: boolean;
};

/**
 * The organization the user is working in: its applications (the launcher)
 * and shortcuts. Application links open the workspace host, so they are
 * plain links rather than Inertia visits.
 */
export function CurrentOrganizationPanel({
    current,
}: {
    current: CurrentOrganization;
}) {
    const stats = [
        { label: 'Members', value: current.counts.members },
        { label: 'Branches', value: current.counts.branches },
        {
            label: 'Pending invitations',
            value: current.counts.pendingInvitations,
        },
    ];

    return (
        <section
            className="space-y-4 rounded-xl border p-4"
            aria-labelledby="current-organization"
        >
            <div className="flex flex-wrap items-center gap-2">
                <h2 id="current-organization" className="text-lg font-semibold">
                    {current.displayName}
                </h2>
                <div className="ml-auto flex flex-wrap gap-2">
                    {current.workspaceUrl && (
                        <Button asChild size="sm">
                            <a href={current.workspaceUrl}>Open workspace</a>
                        </Button>
                    )}
                    {current.canManageAccess && current.workspaceUrl && (
                        <Button asChild size="sm" variant="outline">
                            <a
                                href={`${current.workspaceUrl}/manage/invitations`}
                            >
                                Invite people
                            </a>
                        </Button>
                    )}
                    {current.isOwner && (
                        <>
                            <Button asChild size="sm" variant="outline">
                                <Link href={organizationSettings()}>
                                    Settings
                                </Link>
                            </Button>
                            <Button asChild size="sm" variant="outline">
                                <Link href={organizationSubscription()}>
                                    Subscription
                                </Link>
                            </Button>
                        </>
                    )}
                </div>
            </div>

            <dl className="grid grid-cols-3 gap-4">
                {stats.map((stat) => (
                    <div key={stat.label}>
                        <dt className="text-xs text-muted-foreground">
                            {stat.label}
                        </dt>
                        <dd className="text-xl font-semibold tabular-nums">
                            {stat.value}
                        </dd>
                    </div>
                ))}
            </dl>

            <div>
                <h3 className="text-sm font-medium">Applications</h3>
                {current.applications.length === 0 ? (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {current.isOwner
                            ? 'Your organization has no applications yet. Contact sales to subscribe.'
                            : 'No applications have been given to you yet. Ask an administrator for access.'}
                    </p>
                ) : (
                    <ul className="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {current.applications.map((application) => (
                            <li key={application.code}>
                                <a
                                    href={application.href}
                                    className="flex items-start gap-3 rounded-lg border p-3 hover:border-primary"
                                >
                                    <AppWindow
                                        className="mt-0.5 size-5 text-muted-foreground"
                                        aria-hidden
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            {application.name}
                                        </span>
                                        {application.description && (
                                            <span className="block text-sm text-muted-foreground">
                                                {application.description}
                                            </span>
                                        )}
                                    </span>
                                </a>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
