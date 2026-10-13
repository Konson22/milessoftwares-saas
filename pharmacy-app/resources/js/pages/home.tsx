import { Head, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Clock,
    FileText,
    Package,
    ReceiptText,
} from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/stat-card';
import { formatMoney } from '@/lib/money';
import { index as prescriptions } from '@/routes/prescriptions';
import { index as sales } from '@/routes/sales';
import { index as stock } from '@/routes/stock';

type Props = {
    sales: { count: number; totalMinor: number } | null;
    stock: { low: number; expiring: number } | null;
    awaitingVerification: number | null;
    expiringDays: number;
};

/**
 * The branch at a glance; each card only for members who may see it.
 */
export default function Home({
    sales: today,
    stock: levels,
    awaitingVerification,
    expiringDays,
}: Props) {
    const { workspace, user, branch, currency } = usePage().props;

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={`${branch?.name ?? 'Pharmacy'}`}
                description={`Welcome, ${user?.name}.${workspace ? ` ${workspace.name}.` : ''}`}
            />

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
                    <>
                        <StatCard
                            label="Low stock"
                            value={levels.low}
                            hint="At or below the reorder level"
                            icon={Package}
                            accent="amber"
                            href={stock({ query: { filter: 'low' } })}
                        />
                        <StatCard
                            label="Expiring soon"
                            value={levels.expiring}
                            hint={`Within ${expiringDays} days`}
                            icon={AlertTriangle}
                            accent="red"
                            href={stock({ query: { filter: 'expiring' } })}
                        />
                    </>
                )}
                {awaitingVerification !== null && (
                    <StatCard
                        label="Prescriptions"
                        value={awaitingVerification}
                        hint="Waiting for a pharmacist"
                        icon={FileText}
                        accent="violet"
                        href={prescriptions({
                            query: { status: 'recorded' },
                        })}
                    />
                )}
            </div>

            {!today && !levels && awaitingVerification === null && (
                <p className="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <Clock className="size-4" aria-hidden />
                    Nothing to show for your permissions in this branch.
                </p>
            )}
        </>
    );
}
