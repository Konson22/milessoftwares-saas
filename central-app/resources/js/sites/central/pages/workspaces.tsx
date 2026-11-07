import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Building2, Plus } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import { workspaces as workspacesRoute } from '@/routes';
import { create as createOrganization } from '@/routes/organizations';

type Workspace = {
    id: string;
    displayName: string;
    status: string;
    /** The business's own address, e.g. acme.milesoftwares.com. */
    address: string | null;
    /** Where Open goes; null while the business cannot be opened. */
    url: string | null;
    isOwner: boolean;
};

/**
 * The account's businesses, on the auth host. Each one opens on its own
 * address, where its dashboard, settings, plans and billing live.
 */
export default function Workspaces({
    workspaces,
}: {
    workspaces: Workspace[];
}) {
    return (
        <>
            <Head title="Your businesses" />

            <PageHeader
                title="Your businesses"
                description="Each business has its own address. Open one to work in it."
                actions={
                    <Button asChild>
                        <Link href={createOrganization()}>
                            <Plus /> New business
                        </Link>
                    </Button>
                }
            />

            {workspaces.length === 0 ? (
                <Panel>
                    <EmptyState
                        icon={Building2}
                        title="You are not part of a business yet"
                        action={
                            <Button asChild size="sm">
                                <Link href={createOrganization()}>
                                    Create a business
                                </Link>
                            </Button>
                        }
                    >
                        Create one for your business, or ask a colleague to
                        invite you.
                    </EmptyState>
                </Panel>
            ) : (
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {workspaces.map((workspace) => (
                        <li
                            key={workspace.id}
                            className="flex flex-col gap-4 rounded-xl border bg-card p-4 shadow-xs"
                        >
                            <div className="flex items-start gap-3">
                                <span className="grid size-11 shrink-0 place-items-center rounded-lg bg-primary/10 text-lg font-semibold text-primary uppercase">
                                    {workspace.displayName.slice(0, 1)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate font-semibold">
                                        {workspace.displayName}
                                    </p>
                                    {workspace.address && (
                                        <p className="truncate text-sm text-muted-foreground">
                                            {workspace.address}
                                        </p>
                                    )}
                                    <div className="mt-1.5 flex flex-wrap gap-1">
                                        {workspace.isOwner && (
                                            <StatusBadge>Owner</StatusBadge>
                                        )}
                                        {workspace.status === 'suspended' && (
                                            <StatusBadge tone="danger">
                                                Suspended
                                            </StatusBadge>
                                        )}
                                    </div>
                                </div>
                            </div>
                            {workspace.url && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="mt-auto"
                                >
                                    <a href={workspace.url}>
                                        Open <ArrowRight />
                                    </a>
                                </Button>
                            )}
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

Workspaces.layout = {
    breadcrumbs: [{ title: 'Your businesses', href: workspacesRoute() }],
};
