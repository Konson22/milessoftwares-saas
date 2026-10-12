import { Form, Head } from '@inertiajs/react';
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
            <h1 className="text-2xl font-semibold">Where are you working?</h1>
            <p className="mt-1 text-muted-foreground">
                Stock, sales and receipts belong to the branch you choose.
            </p>

            {branches.length === 0 ? (
                <p className="mt-6">
                    You have no pharmacy role in any active branch. Ask your
                    workspace administrator.
                </p>
            ) : (
                <ul className="mt-6 grid gap-3 sm:grid-cols-2">
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
        </>
    );
}
