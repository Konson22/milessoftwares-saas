import { createInertiaApp } from '@inertiajs/react';
import { putConfig } from '@inertiaui/modal-react';
import ProductLayout from '@/layouts/product-layout';

// Dialogs (Inertia UI Modal): solid panel in the app's own colours.
putConfig({
    modal: {
        closeButton: true,
        closeExplicitly: false,
        closeOnClickOutside: true,
        maxWidth: '2xl',
        paddingClasses: 'p-5 sm:p-6',
        panelClasses: 'im-panel bg-card text-card-foreground rounded-md border',
        position: 'center',
    },
});

const appName = import.meta.env.VITE_APP_NAME || 'Miles Logbook';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: () => ProductLayout,
    strictMode: true,
    progress: {
        color: '#4B5563',
    },
});
