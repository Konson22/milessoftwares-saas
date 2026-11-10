import { createInertiaApp } from '@inertiajs/react';
import PlainLayout from '@/layouts/plain-layout';
import ProductLayout from '@/layouts/product-layout';

const appName = import.meta.env.VITE_APP_NAME || 'Projects';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    // A donor's invitation opened outside one's own workspace, then the
    // full shell.
    layout: (name: string) =>
        name === 'portal/partnerships/landing' ? PlainLayout : ProductLayout,
    strictMode: true,
    progress: {
        color: '#0081FE',
    },
});
