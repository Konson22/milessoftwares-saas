import { Form, Head, Link } from '@inertiajs/react';
import { Building2, ChevronRight, Plus } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@/shared/components/ui/button';
import OrganizationController from '@/actions/App/Sites/Central/Http/Controllers/Dashboard/OrganizationController';
import { dashboard } from '@/routes';
import { create as createOrganization } from '@/routes/organizations';

type Workspace = {
    id: string;
    name: string;
    address: string | null;
    isOwner: boolean;
    isCurrent: boolean;
};

type Props = {
    trialDays: number;
    /** Name of the application the visitor chose on the public site. */
    application: string | null;
    selected: string | null;
    workspaces: Workspace[];
};

/**
 * First step of a free trial for someone who already has workspaces: the
 * trial goes to one of them, or to a new one.
 */
export default function TrialWorkspace({
    trialDays,
    application,
    selected,
    workspaces,
}: Props) {
    return (
        <>
            <Head title="Choose a workspace" />

            <PageHeader
                title={`Start your ${trialDays}-day free trial`}
                description={`You already have ${workspaces.length === 1 ? 'a workspace' : 'workspaces'}. Choose where ${application ?? 'the trial'} should run, or create a new workspace for it.`}
            />

            <div className="grid gap-3 lg:grid-cols-2">
                <Panel aria-labelledby="existing">
                    <PanelHeader
                        title={
                            <span id="existing">Use an existing workspace</span>
                        }
                        description="Only a workspace's owner can start a trial for it."
                    />
                    <ul className="divide-y">
                        {workspaces.map((workspace) => (
                            <li
                                key={workspace.id}
                                className="flex items-center gap-3 p-4"
                            >
                                <span className="grid size-9 shrink-0 place-items-center rounded-md bg-primary/10 text-primary">
                                    <Building2 className="size-4" aria-hidden />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span className="font-medium">
                                            {workspace.name}
                                        </span>
                                        {workspace.isCurrent && (
                                            <StatusBadge tone="info">
                                                Current
                                            </StatusBadge>
                                        )}
                                        {workspace.isOwner && (
                                            <StatusBadge>Owner</StatusBadge>
                                        )}
                                    </div>
                                    {workspace.address && (
                                        <p className="truncate text-sm text-muted-foreground">
                                            {workspace.address}
                                        </p>
                                    )}
                                </div>
                                <Form
                                    {...OrganizationController.switch.form(
                                        workspace.id,
                                    )}
                                >
                                    {({ processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="trial"
                                                value={selected ?? ''}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Use this
                                                <ChevronRight aria-hidden />
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                </Panel>

                <Panel aria-labelledby="new" className="h-fit">
                    <PanelHeader
                        title={<span id="new">Create a new workspace</span>}
                        description={`A separate business with its own address and data. ${application ?? 'The trial'} starts there as soon as it is created.`}
                    />
                    <div className="p-4">
                        <Button asChild className="w-full">
                            <Link
                                href={createOrganization({
                                    query: selected
                                        ? { app: selected, new: 1 }
                                        : { new: 1 },
                                })}
                            >
                                <Plus aria-hidden />
                                New workspace
                            </Link>
                        </Button>
                    </div>
                </Panel>
            </div>
        </>
    );
}

TrialWorkspace.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Free trial', href: '/trial' },
    ],
};
