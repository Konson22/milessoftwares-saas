import { Head } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { OpenBranch } from '@/components/open-branch';
import { formatMoney } from '@/lib/money';

type Props = {
    currency: string;
    branches: {
        id: string;
        name: string;
        code: string | null;
        city: string | null;
        netMinor?: number;
        orders?: number;
        pendingTickets?: number;
        openOrders?: number;
        lowStock?: number;
        status?: 'trading' | 'quiet';
    }[];
    /** Where branches are added and changed (the workspace), if allowed. */
    structureUrl: string | null;
};

/**
 * Every branch, today, each opening its own workspace in a new tab.
 */
export default function ManagementBranches({
    currency,
    branches,
    structureUrl,
}: Props) {
    const money = (minor: number) => formatMoney(minor, currency);

    return (
        <>
            <Head title="All branches" />
            <PageHeader
                title="All branches"
                description="Today in each branch. A branch opens in a new tab, beside this one."
                actions={
                    structureUrl && (
                        <Button asChild size="sm" variant="outline">
                            <a href={structureUrl}>Add or change branches</a>
                        </Button>
                    )
                }
            />

            {branches.length === 0 ? (
                <Panel>
                    <PanelBody>
                        <EmptyState icon={Building2} title="No branches yet">
                            Branches are added in the workspace, for every app.
                        </EmptyState>
                    </PanelBody>
                </Panel>
            ) : (
                <ul className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {branches.map((branch) => (
                        <li key={branch.id}>
                            <Panel className="h-full">
                                <PanelBody className="flex h-full flex-col gap-4">
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <OpenBranch
                                                branchId={branch.id}
                                                name={branch.name}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {[branch.code, branch.city]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </div>
                                        <span
                                            className={`shrink-0 rounded-md border px-2 py-0.5 text-xs font-medium ${branch.status === 'trading' ? 'border-green-300 bg-green-50 text-green-800' : 'border-border bg-muted text-muted-foreground'}`}
                                        >
                                            {branch.status === 'trading'
                                                ? 'Till open'
                                                : 'No till open'}
                                        </span>
                                    </div>
                                    <dl className="grid grid-cols-2 gap-3 text-sm">
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Net sales today
                                            </dt>
                                            <dd className="font-semibold tabular-nums">
                                                {money(branch.netMinor ?? 0)}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Orders paid
                                            </dt>
                                            <dd className="font-semibold tabular-nums">
                                                {branch.orders ?? 0}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Open now
                                            </dt>
                                            <dd className="tabular-nums">
                                                {branch.openOrders ?? 0} orders
                                                · {branch.pendingTickets ?? 0}{' '}
                                                in kitchen
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="text-muted-foreground">
                                                Drinks low
                                            </dt>
                                            <dd
                                                className={`tabular-nums ${(branch.lowStock ?? 0) > 0 ? 'text-amber-800' : ''}`}
                                            >
                                                {branch.lowStock ?? 0}
                                            </dd>
                                        </div>
                                    </dl>
                                    <div className="mt-auto">
                                        <OpenBranch
                                            branchId={branch.id}
                                            name={branch.name}
                                            button
                                        >
                                            Open {branch.name}
                                        </OpenBranch>
                                    </div>
                                </PanelBody>
                            </Panel>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
