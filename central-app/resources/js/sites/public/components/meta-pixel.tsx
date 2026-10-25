import { router } from '@inertiajs/react';
import { useEffect } from 'react';

const PIXEL_ID = '1102465279187343';

type FacebookPixel = ((...args: unknown[]) => void) & {
    callMethod?: (...args: unknown[]) => void;
    queue: unknown[];
    loaded: boolean;
    version: string;
    push: FacebookPixel;
};

declare global {
    interface Window {
        fbq?: FacebookPixel;
        _fbq?: FacebookPixel;
    }
}

/**
 * Meta (Facebook) Pixel for the public marketing site. Loads once, then
 * records PageView on the first render and on every Inertia navigation.
 */
function ensurePixel(): void {
    if (window.fbq) {
        return;
    }

    const fbq = function (...args: unknown[]) {
        if (fbq.callMethod) {
            fbq.callMethod(...args);
        } else {
            fbq.queue.push(args);
        }
    } as FacebookPixel;

    fbq.push = fbq;
    fbq.loaded = true;
    fbq.version = '2.0';
    fbq.queue = [];

    window.fbq = fbq;
    if (!window._fbq) {
        window._fbq = fbq;
    }

    const script = document.createElement('script');
    script.async = true;
    script.src = 'https://connect.facebook.net/en_US/fbevents.js';
    const first = document.getElementsByTagName('script')[0];
    first?.parentNode?.insertBefore(script, first);

    window.fbq('init', PIXEL_ID);
}

export default function MetaPixel() {
    useEffect(() => {
        ensurePixel();
        window.fbq?.('track', 'PageView');

        return router.on('navigate', () => {
            window.fbq?.('track', 'PageView');
        });
    }, []);

    return (
        <noscript>
            <img
                height={1}
                width={1}
                style={{ display: 'none' }}
                src={`https://www.facebook.com/tr?id=${PIXEL_ID}&ev=PageView&noscript=1`}
                alt=""
            />
        </noscript>
    );
}
