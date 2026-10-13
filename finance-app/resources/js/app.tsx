import { createInertiaApp } from '@inertiajs/react';
import ProductLayout from '@/layouts/product-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Finance';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => ProductLayout,
    strictMode: true,
    progress: {
        color: '#4B5563',
    },
});
