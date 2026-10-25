import { Head, usePage } from '@inertiajs/react';
import { ChefHat, Clock, LayoutGrid, Package, ReceiptText } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { StatCard } from '@miles/ui/components/stat-card';
import { useCanHere } from '@/lib/access';
import { formatMoney } from '@/lib/money';
import { floor, kitchen } from '@/routes';
import { index as bills } from '@/routes/bills';
import { index as stock } from '@/routes/stock';

type Props = {
    /** Null when the member does not take orders here. */
    openOrders: number | null;
    takings: { count: number; totalMinor: number } | null;
    lowStock: number | null;
};

/**
 * The branch at a glance; each card only for members who may see it.
 */
export default function Home({ openOrders, takings, lowStock }: Props) {
    const { workspace, user, branch, currency } = usePage().props;
    const canHere = useCanHere();
    const cooks = canHere('restaurant.kitchen.view');

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={branch?.name ?? 'Restaurant'}
                description={`Welcome, ${user?.name}.${workspace ? ` ${workspace.name}.` : ''}`}
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {openOrders !== null && (
                    <StatCard
                        label="Floor"
                        value={openOrders}
                        hint="Orders open: seat guests and take orders"
                        icon={LayoutGrid}
                        accent="blue"
                        href={floor()}
                    />
                )}
                {cooks && (
                    <StatCard
                        label="Kitchen"
                        value="Tickets"
                        hint="What to cook, oldest first"
                        icon={ChefHat}
                        accent="amber"
                        href={kitchen()}
                    />
                )}
                {takings && (
                    <StatCard
                        label="Today's takings"
                        value={formatMoney(
                            takings.totalMinor,
                            currency ?? 'USD',
                        )}
                        hint={`${takings.count} bills`}
                        icon={ReceiptText}
                        accent="blue"
                        href={bills()}
                    />
                )}
                {lowStock !== null && (
                    <StatCard
                        label="Drinks running low"
                        value={lowStock}
                        hint="At or below the reorder level"
                        icon={Package}
                        accent="amber"
                        href={stock({ query: { filter: 'low' } })}
                    />
                )}
            </div>

            {openOrders === null && !takings && lowStock === null && !cooks && (
                <p className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <Clock className="size-4" aria-hidden />
                    Nothing to show for your permissions in this branch.
                </p>
            )}
        </>
    );
}
