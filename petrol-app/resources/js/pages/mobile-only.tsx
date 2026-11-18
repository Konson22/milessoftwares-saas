import { Head } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody } from '@miles/ui/components/panel';

/**
 * Attendants work from the mobile app only (RequireWebRole).
 */
export default function MobileOnly() {
    return (
        <>
            <Head title="Use the mobile app" />
            <Panel className="mx-auto mt-8 max-w-xl">
                <PanelBody>
                    <EmptyState
                        icon={Smartphone}
                        title="Attendants work from the mobile app"
                    >
                        Sign in to the Petrol mobile app on your phone with the
                        same username and password. Ask your manager if you need
                        a role on the web.
                    </EmptyState>
                </PanelBody>
            </Panel>
        </>
    );
}
