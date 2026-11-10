import { Form, Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { update } from '@/routes/branch';

type Props = {
    branches: { id: string; name: string; city: string | null }[];
    current: string | null;
    reason: 'no_branches' | 'no_role' | null;
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
            <Head title="Choose a station" />
            <PageHeader
                title="Where are you working?"
                description="Tanks, pumps, shifts and sales belong to the station you choose."
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
                            A station is a branch. Add one in the workspace,
                            then set it up here.
                        </EmptyState>
                    ) : branches.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title="No petrol role in any active branch"
                        >
                            Ask your workspace administrator for a role in the
                            station you work in.
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
                                                <span className="text-xs font-normal opacity-80">
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
