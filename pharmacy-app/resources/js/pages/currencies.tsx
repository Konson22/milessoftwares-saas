import { Head } from '@inertiajs/react';
import { CurrencyRates } from '@miles/ui/components/currency-rates';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { store } from '@/routes/currencies';

export default function Currencies({ canManage }: { canManage: boolean }) {
    return (
        <>
            <Head title="Currencies" />
            <PageHeader
                title="Currencies"
                description="Currencies people can type amounts in, and their rates."
            />
            <Panel className="max-w-3xl">
                <PanelBody>
                    <CurrencyRates action={store().url} canManage={canManage} />
                </PanelBody>
            </Panel>
        </>
    );
}
