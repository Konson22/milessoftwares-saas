import { Head } from '@inertiajs/react';
import { Radio, Truck } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';

/**
 * ADR-0028 phases 2 and 3: described only. Nothing here connects, registers
 * or tracks anything, and there are no controls that look as if they do.
 */
const PAGES = {
    'gps-devices': {
        icon: Radio,
        title: 'GPS devices',
        description:
            'Connect compatible GPS devices installed in vehicles and equipment to automatically capture movement, synchronize tracking data, and link routes with operational records.',
        planned: [
            'Vehicle-installed GPS trackers',
            'A Traccar tracking server',
            'External GPS tracking platforms',
            'Assigning a device to a vehicle or other resource',
        ],
    },
    fleet: {
        icon: Truck,
        title: 'Fleet management',
        description:
            'See where vehicles are and how they are used, with the routes and records behind them.',
        planned: [
            'Live vehicle location monitoring',
            'Vehicle movement history',
            'Geofencing and movement alerts',
            'Trip analytics and driver activity reports',
            'Vehicle utilization and fleet dashboards',
        ],
    },
} as const;

export default function ComingSoon({ page }: { page: keyof typeof PAGES }) {
    const p = PAGES[page];
    const Icon = p.icon;

    return (
        <>
            <Head title={p.title} />
            <PageHeader
                title={p.title}
                description="Coming soon: not available yet."
            />
            <Panel className="max-w-2xl">
                <PanelBody className="grid gap-4">
                    <div className="flex items-start gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-muted">
                            <Icon className="size-5" />
                        </span>
                        <div className="grid gap-1">
                            <span className="w-fit rounded-md border px-2 py-0.5 text-xs font-semibold">
                                Coming soon
                            </span>
                            <p className="text-sm">{p.description}</p>
                        </div>
                    </div>
                    <div>
                        <p className="mb-1 text-sm font-medium">Planned</p>
                        <ul className="list-disc pl-5 text-sm text-muted-foreground">
                            {p.planned.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Today, routes are recorded with the Miles Logbook phone
                        app, started by the person doing the work. See Routes.
                    </p>
                </PanelBody>
            </Panel>
        </>
    );
}
