import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';

export default function Readings({
    readings,
}: {
    readings: {
        id: string;
        shift: string;
        nozzle: string;
        kind: string;
        litres: string;
        at: string;
    }[];
}) {
    return (
        <>
            <Head title="Meter readings" />
            <PageHeader
                title="Meter readings"
                description="Opening and closing readings. An approved reading stays as it was."
            />
            <Panel>
                <PanelBody>
                    <ul className="text-sm">
                        {readings.map((row) => (
                            <li key={row.id}>
                                {row.shift} {row.nozzle} {row.kind} {row.litres}{' '}
                                L
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
