import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { OpenBranch } from '@/components/open-branch';
import { formatMoney } from '@/lib/money';

type Props = {
    currency: string;
    branches: { id: string; name: string }[];
    drinks: {
        id: string;
        name: string;
        unit: string;
        reorderLevel: number;
        byBranch: Record<string, number>;
        valueMinor: number;
    }[];
};

/**
 * The drinks every branch holds, side by side: what is low, and what has
 * gone below zero (sold beyond what was counted: count it). Stock is
 * received and counted in each branch.
 */
export default function ManagementInventory({
    currency,
    branches,
    drinks,
}: Props) {
    const total = drinks.reduce((sum, drink) => sum + drink.valueMinor, 0);

    return (
        <>
            <Head title="Inventory" />
            <PageHeader
                title="Inventory"
                description={`Drinks in every branch, worth ${formatMoney(total, currency)} at cost. Deliveries and counts are recorded in each branch.`}
            />

            <Panel>
                <PanelBody className="overflow-x-auto">
                    {drinks.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No drinks on the menu keep stock yet.
                        </p>
                    ) : (
                        <table className="w-full min-w-[36rem] text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="py-2 font-medium">Drink</th>
                                    {branches.map((branch) => (
                                        <th
                                            key={branch.id}
                                            className="py-2 text-right font-medium"
                                        >
                                            <OpenBranch
                                                branchId={branch.id}
                                                name={branch.name}
                                            />
                                        </th>
                                    ))}
                                    <th className="py-2 text-right font-medium">
                                        Value
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {drinks.map((drink) => (
                                    <tr key={drink.id}>
                                        <td className="py-2">
                                            <span className="font-medium">
                                                {drink.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                Reorder at {drink.reorderLevel}{' '}
                                                {drink.unit}s
                                            </span>
                                        </td>
                                        {branches.map((branch) => {
                                            const quantity =
                                                drink.byBranch[branch.id] ?? 0;

                                            return (
                                                <td
                                                    key={branch.id}
                                                    className={`py-2 text-right tabular-nums ${quantity < 0 ? 'font-semibold text-red-700' : quantity <= drink.reorderLevel ? 'font-semibold text-amber-800' : ''}`}
                                                >
                                                    {quantity}
                                                    {quantity < 0 && (
                                                        <span className="block text-xs font-normal">
                                                            Count it
                                                        </span>
                                                    )}
                                                    {quantity >= 0 &&
                                                        quantity <=
                                                            drink.reorderLevel && (
                                                            <span className="block text-xs font-normal">
                                                                Low
                                                            </span>
                                                        )}
                                                </td>
                                            );
                                        })}
                                        <td className="py-2 text-right tabular-nums">
                                            {formatMoney(
                                                drink.valueMinor,
                                                currency,
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
