import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    IdCard,
    Package,
    PackagePlus,
    ReceiptText,
    Route,
    Truck,
    Wallet,
    Wrench,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { useCan } from '@/lib/access';
import { useMoney } from '@/lib/use-money';
import { create as bookShipment, index as shipments } from '@/routes/shipments';
import { create as planTrip, index as trips } from '@/routes/trips';
import { index as vehicles } from '@/routes/vehicles';
import { index as drivers } from '@/routes/drivers';
import { index as invoices, create as newInvoice } from '@/routes/invoices';

type Props = {
    shipments: {
        waiting: number;
        assigned: number;
        inTransit: number;
        deliveredToday: number;
    } | null;
    trips: { planned: number; onTheRoad: number } | null;
    fleet: {
        available: number;
        inMaintenance: number;
        licencesDue: number;
    } | null;
    billing: {
        toInvoice: number;
        outstandingMinor: number;
        overdue: number;
    } | null;
};

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mt-6">
            <h2 className="mb-2 text-sm font-semibold text-muted-foreground">
                {title}
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {children}
            </div>
        </section>
    );
}

/**
 * Today at a glance, in the sections the member may see.
 */
export default function Home({
    shipments: work,
    trips: road,
    fleet,
    billing,
}: Props) {
    const { product, workspace, user } = usePage().props;
    const can = useCan();
    const money = useMoney();

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={`${product.name} for ${workspace?.name ?? ''}`}
                description={`Welcome, ${user?.name ?? ''}.`}
                actions={
                    <>
                        {can('logistics.shipments.manage') && (
                            <Button asChild>
                                <Link href={bookShipment()}>
                                    <PackagePlus
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Book a shipment
                                </Link>
                            </Button>
                        )}
                        {can('logistics.trips.manage') && (
                            <Button variant="outline" asChild>
                                <Link href={planTrip()}>
                                    <Route className="size-4" aria-hidden />
                                    Plan a trip
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            {work && (
                <Section title="Shipments">
                    <StatCard
                        label="Waiting for a trip"
                        value={work.waiting}
                        icon={Clock}
                        accent="slate"
                        href={shipments({ query: { status: 'booked' } })}
                    />
                    <StatCard
                        label="On planned trips"
                        value={work.assigned}
                        icon={Package}
                        href={shipments({ query: { status: 'assigned' } })}
                    />
                    <StatCard
                        label="In transit"
                        value={work.inTransit}
                        icon={Truck}
                        accent="amber"
                        href={shipments({ query: { status: 'in_transit' } })}
                    />
                    <StatCard
                        label="Delivered today"
                        value={work.deliveredToday}
                        icon={CheckCircle2}
                        accent="emerald"
                        href={shipments({ query: { status: 'delivered' } })}
                    />
                </Section>
            )}

            {(road || fleet) && (
                <Section title="Trips and fleet">
                    {road && (
                        <>
                            <StatCard
                                label="Trips planned"
                                value={road.planned}
                                icon={Route}
                                href={trips({ query: { status: 'planned' } })}
                            />
                            <StatCard
                                label="Trips on the road"
                                value={road.onTheRoad}
                                icon={Truck}
                                accent="amber"
                                href={trips({
                                    query: { status: 'in_progress' },
                                })}
                            />
                        </>
                    )}
                    {fleet && (
                        <>
                            <StatCard
                                label="Vehicles available"
                                value={fleet.available}
                                hint={
                                    fleet.inMaintenance > 0
                                        ? `${fleet.inMaintenance} in maintenance`
                                        : undefined
                                }
                                icon={fleet.inMaintenance > 0 ? Wrench : Truck}
                                accent="emerald"
                                href={vehicles()}
                            />
                            <StatCard
                                label="Driving licences due"
                                value={fleet.licencesDue}
                                hint="Expired or expiring within 30 days"
                                icon={IdCard}
                                accent={fleet.licencesDue > 0 ? 'red' : 'slate'}
                                href={drivers()}
                            />
                        </>
                    )}
                </Section>
            )}

            {billing && (
                <Section title="Billing">
                    <StatCard
                        label="Delivered, not yet invoiced"
                        value={billing.toInvoice}
                        icon={ReceiptText}
                        href={
                            can('logistics.billing.manage')
                                ? newInvoice()
                                : invoices()
                        }
                    />
                    <StatCard
                        label="Owed by customers"
                        value={money(billing.outstandingMinor)}
                        icon={Wallet}
                        accent="violet"
                        href={invoices({ query: { status: 'issued' } })}
                    />
                    <StatCard
                        label="Overdue invoices"
                        value={billing.overdue}
                        icon={AlertTriangle}
                        accent={billing.overdue > 0 ? 'red' : 'slate'}
                        href={invoices({ query: { status: 'issued' } })}
                    />
                </Section>
            )}
        </>
    );
}
