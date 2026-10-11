import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/shared/components/ui/sonner';
import { TooltipProvider } from '@/shared/components/ui/tooltip';
import { initializeTheme } from '@/shared/hooks/use-appearance';
import { resolveLayout, resolvePage } from '@/sites';

const appName = import.meta.env.VITE_APP_NAME || 'Miles Global Softwares';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: resolvePage,
    layout: resolveLayout,
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
