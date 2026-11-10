import { Head, Link, usePage } from '@inertiajs/react';
import { Droplets, Fuel, ReceiptText, ShieldCheck, Users } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { StatCard } from '@miles/ui/components/stat-card';
import { useMoney } from '@/lib/use-money';
import { index as sales } from '@/routes/sales';
import { index as settings } from '@/routes/settings';
import { index as shifts } from '@/routes/shifts';
import { index as tanks } from '@/routes/tanks';

type Props = {
    station: {
        code: string;
        hours: string | null;
        collectionMode: string;
        active: boolean;
        main: boolean;
    } | null;
    shift: { id: string; number: string; status: string } | null;
    today: { count: number; total_minor: number; milli: number } | null;
    lowTanks: number | null;
    pendingApprovals: number | null;
    creditMinor: number | null;
    canSetup: boolean;
};

export default function Home({
    station,
    shift,
    today,
    lowTanks,
    pendingApprovals,
    creditMinor,
    canSetup,
}: Props) {
    const { workspace, user, branch, currency } = usePage().props;
    const money = useMoney();

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={branch?.name ?? 'Petrol Station'}
                description={`Welcome, ${user?.name}.${workspace ? ` ${workspace.name}.` : ''}${station ? ` Station ${station.code}.` : ''}${station?.main ? ' Main branch.' : ''}`}
            />

            {station === null && canSetup && (
                <p className="mb-4 text-sm">
                    This branch is not a station yet.{' '}
                    <Link href={settings()} className="underline">
                        Set it up
                    </Link>
                    .
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <StatCard
                    label="Open shift"
                    value={shift?.number ?? 'None'}
                    hint={shift ? shift.status : 'Open a shift before selling'}
                    icon={Fuel}
                    accent="blue"
                    href={shifts()}
                />
                {today && (
                    <StatCard
                        label="Today's sales"
                        value={money(today.total_minor)}
                        hint={`${today.count} receipts`}
                        icon={ReceiptText}
                        accent="blue"
                        href={sales()}
                    />
                )}
                {lowTanks !== null && (
                    <StatCard
                        label="Low tanks"
                        value={lowTanks}
                        hint="At or below the station threshold"
                        icon={Droplets}
                        accent="amber"
                        href={tanks()}
                    />
                )}
                {pendingApprovals !== null && (
                    <StatCard
                        label="Closings to approve"
                        value={pendingApprovals}
                        hint="Submitted or under review"
                        icon={ShieldCheck}
                        accent="amber"
                        href={shifts()}
                    />
                )}
                {creditMinor !== null && (
                    <StatCard
                        label="Credit outstanding"
                        value={money(creditMinor)}
                        hint={`Across ${workspace?.name ?? 'the company'}`}
                        icon={Users}
                        accent="blue"
                    />
                )}
            </div>
            {currency === null && station?.hours && (
                <p className="mt-4 text-sm text-muted-foreground">
                    Hours: {station.hours}
                </p>
            )}
        </>
    );
}
