import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';

export default function Assignments({ shift }: { shift: null }) {
    return (
        <>
            <Head title="Assignments" />
            <PageHeader
                title="Assignments"
                description="Nozzles are assigned on the open shift."
            />
            <Panel>
                <PanelBody>
                    {shift === null && (
                        <p className="text-sm">
                            There is no open shift. Open one, then assign
                            attendants to nozzles.
                        </p>
                    )}
                </PanelBody>
            </Panel>
        </>
    );
}
