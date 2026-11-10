import { Head } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';

export default function Audit({
    logs,
}: {
    logs: {
        id: string;
        action: string;
        subject: string;
        actor: string | null;
        at: string | null;
    }[];
}) {
    return (
        <>
            <Head title="Audit" />
            <PageHeader
                title="Audit"
                description="What changed at this station, and who did it."
            />
            <Panel>
                <PanelBody>
                    <ul className="grid gap-1 text-sm">
                        {logs.map((log) => (
                            <li key={log.id}>
                                {log.at} {log.actor ?? 'Someone'} {log.action}{' '}
                                {log.subject}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
        </>
    );
}
