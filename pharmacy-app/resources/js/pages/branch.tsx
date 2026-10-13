import { Form, Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { update } from '@/routes/branch';

type Props = {
    branches: { id: string; name: string; city: string | null }[];
    current: string | null;
};

export default function ChooseBranch({ branches, current }: Props) {
    return (
        <>
            <Head title="Choose a branch" />
            <PageHeader
                title="Where are you working?"
                description="Stock, sales and receipts belong to the branch you choose."
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    {branches.length === 0 ? (
                        <EmptyState
                            icon={Building2}
                            title="No pharmacy role in any active branch"
                        >
                            Ask your workspace administrator.
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
