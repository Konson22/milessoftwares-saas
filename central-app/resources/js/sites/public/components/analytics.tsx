import { router } from '@inertiajs/react';
import { useEffect } from 'react';

declare global {
    interface Window {
        gtag?: (...args: unknown[]) => void;
    }
}

/**
 * Google Analytics 4 page views for the public site. The tag itself is set
 * up in app.blade.php only when GOOGLE_ANALYTICS_ID is configured; without
 * it window.gtag is missing and this does nothing. Inertia changes pages
 * without a full load, so each new URL is sent once here.
 */
export default function Analytics() {
    useEffect(() => {
        let last = '';

        const send = () => {
            const location = window.location.href;

            if (!window.gtag || location === last) {
                return;
            }

            last = location;
            window.gtag('event', 'page_view', {
                page_location: location,
                page_title: document.title,
            });
        };

        send();

        // After the swap, so document.title is the new page's.
        return router.on('navigate', () => setTimeout(send, 0));
    }, []);

    return null;
}
