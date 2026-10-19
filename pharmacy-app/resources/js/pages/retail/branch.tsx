import { Form, Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { update } from '@/routes/retail/branch';

type Props = {
    branches: { id: string; name: string; city: string | null }[];
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
                description="Stock, sales and tills belong to the branch you choose."
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
                                ? 'The shop keeps stock and sales per branch. Add your first branch in the workspace, then come back.'
                                : 'The shop keeps stock and sales per branch. Ask your workspace administrator to add one.'}
                        </EmptyState>
                    ) : branches.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title="No retail role in any active branch"
                        >
                            Ask your workspace administrator for a retail role
                            in the branch you work in.
                        </EmptyState>
                    ) : (
                        <ul className="grid gap-3 sm:grid-cols-2">
                            {branches.map((branch) => (
                                <li key={branch.id}>
                                    <Form {...update.form()}>
                                        <input
                                            type="hidden"
                                            name="branch_id"
                                            value={branch.id}
                                        />
                                        <Button
                                            type="submit"
                                            variant={
                                                branch.id === current
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="h-auto w-full flex-col items-start py-3"
                                        >
                                            <span className="font-medium">
                                                {branch.name}
                                            </span>
                                            {branch.city && (
                                                <span className="text-xs opacity-80">
                                                    {branch.city}
                                                </span>
                                            )}
                                        </Button>
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
