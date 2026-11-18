import { Head, Link } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';

type Props = {
    /** url: the branch's workspace (with the page an old link asked for). */
    branches: { id: string; name: string; city: string | null; url: string }[];
    /** The branch last worked in, if any. */
    current: string | null;
    /** Why there is nothing to choose, if so. */
    reason: 'no_branches' | 'no_role' | null;
    /** Owners only: where branches are added in the workspace. */
    structureUrl: string | null;
};

export default function ChooseBranch({
    branches,
    current,
    reason,
    structureUrl,
}: Props) {
    return (
        <>
            <Head title="Choose a branch" />
            <PageHeader
                title="Where are you working?"
                description="Tables, orders, bills and stock belong to one branch. Open the one you work in: each branch keeps its own tab."
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    {branches.length === 0 && reason === 'no_branches' ? (
                        <EmptyState
                            icon={Building2}
                            title="Your organization has no branches yet"
                            action={
                                structureUrl && (
                                    <Button asChild>
                                        <a href={structureUrl}>Add a branch</a>
                                    </Button>
                                )
                            }
                        >
                            {structureUrl
                                ? 'The restaurant keeps tables, orders and bills per branch. Add your first branch in the workspace, then come back.'
                                : 'The restaurant keeps tables, orders and bills per branch. Ask your workspace administrator to add one.'}
                        </EmptyState>
                    ) : branches.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title="No restaurant role in any active branch"
                        >
                            Ask your workspace administrator for a restaurant
                            role in the branch you work in.
                        </EmptyState>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {branches.map((branch) => (
                                <li key={branch.id}>
                                    <Button
                                        asChild
                                        variant={
                                            branch.id === current
                                                ? 'default'
                                                : 'outline'
                                        }
                                        className="h-auto w-full flex-col items-start py-3"
                                    >
                                        <Link href={branch.url}>
                                            <span className="font-medium">
                                                {branch.name}
                                            </span>
                                            {branch.city && (
                                                <span className="text-xs font-normal">
                                                    {branch.city}
                                                </span>
                                            )}
                                        </Link>
                                    </Button>
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
