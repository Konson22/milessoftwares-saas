import { Head, usePage } from '@inertiajs/react';
import { AlertTriangle, Clock, Package, ReceiptText } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { StatCard } from '@miles/ui/components/stat-card';
import { formatMoney } from '@/lib/money';
import { index as sales } from '@/routes/retail/sales';
import { index as stock } from '@/routes/retail/stock';

type Props = {
    sales: { count: number; totalMinor: number } | null;
    stock: { low: number; expiring: number } | null;
    expiringDays: number;
};

/**
 * The shop's branch at a glance; each card only for members who may see it.
 */
export default function RetailHome({
    sales: today,
    stock: levels,
    expiringDays,
}: Props) {
    const { workspace, user, branch, currency } = usePage().props;

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={branch?.name ?? 'Retail'}
                description={`Welcome, ${user?.name}.${workspace ? ` ${workspace.name}.` : ''}`}
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {today && (
                    <StatCard
                        label="Today's takings"
                        value={formatMoney(today.totalMinor, currency ?? 'USD')}
                        hint={`${today.count} sales`}
                        icon={ReceiptText}
                        accent="blue"
                        href={sales()}
                    />
                )}
                {levels && (
                    <StatCard
                        label="Low stock"
                        value={levels.low}
                        hint="At or below the reorder level"
                        icon={Package}
                        accent="amber"
                        href={stock({ query: { filter: 'low' } })}
                    />
                )}
                {/* Only products that track expiry dates can expire. */}
                {levels && levels.expiring > 0 && (
                    <StatCard
                        label="Expiring soon"
                        value={levels.expiring}
                        hint={`Within ${expiringDays} days`}
                        icon={AlertTriangle}
                        accent="red"
                        href={stock({ query: { filter: 'expiring' } })}
                    />
                )}
            </div>

            {!today && !levels && (
                <p className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <Clock className="size-4" aria-hidden />
                    Nothing to show for your permissions in this branch.
                </p>
            )}
        </>
    );
}
