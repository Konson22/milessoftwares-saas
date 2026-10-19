import { createInertiaApp } from '@inertiajs/react';
import ProductLayout from '@/layouts/product-layout';
import RetailLayout from '@/layouts/retail-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Pharmacy';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // Retail (hosted by this deployment) has its own pages and shell.
    layout: (name: string) =>
        name.startsWith('retail/') ? RetailLayout : ProductLayout,
    strictMode: true,
    progress: {
        color: '#4B5563',
    },
});
