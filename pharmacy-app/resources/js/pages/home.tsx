import { Head, Link, usePage } from '@inertiajs/react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
            <h1 className="text-2xl font-semibold">
                {branch?.name}, {workspace?.name}
            </h1>
            <p className="mt-1 text-muted-foreground">Welcome, {user?.name}.</p>

            <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                {today && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Today&apos;s takings</CardTitle>
                            <CardDescription>
                                {today.count} sales
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold tabular-nums">
                            <Link href={sales()} className="hover:underline">
                                {formatMoney(
                                    today.totalMinor,
                                    currency ?? 'USD',
                                )}
                            </Link>
                        </CardContent>
                    </Card>
                )}
                {levels && (
                    <>
                        <Card>
                            <CardHeader>
                                <CardTitle>Low stock</CardTitle>
                                <CardDescription>
                                    At or below the reorder level
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold tabular-nums">
                                <Link
                                    href={stock({ query: { filter: 'low' } })}
                                    className="hover:underline"
                                >
                                    {levels.low}
                                </Link>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Expiring soon</CardTitle>
                                <CardDescription>
                                    Within {expiringDays} days
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="text-2xl font-semibold tabular-nums">
                                <Link
                                    href={stock({
                                        query: { filter: 'expiring' },
                                    })}
                                    className="hover:underline"
                                >
                                    {levels.expiring}
                                </Link>
                            </CardContent>
                        </Card>
                    </>
                )}
                {awaitingVerification !== null && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Prescriptions</CardTitle>
                            <CardDescription>
                                Waiting for a pharmacist
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="text-2xl font-semibold tabular-nums">
                            <Link
                                href={prescriptions({
                                    query: { status: 'recorded' },
                                })}
                                className="hover:underline"
                            >
                                {awaitingVerification}
                            </Link>
                        </CardContent>
                    </Card>
                )}
            </div>
        </>
    );
}
