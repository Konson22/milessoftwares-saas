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

    window.fbq('init', PIXEL_ID);

    // Meta's script (~180 KB, ~250 ms of main-thread work) is fetched on the
    // visitor's first scroll, tap, click or key press, so it never holds up
    // the page or its first input. Calls made before then wait in
    // fbq.queue, so the first PageView is still sent. A visitor who leaves
    // without touching the page is not counted.
    const events = ['scroll', 'pointerdown', 'keydown', 'touchstart'] as const;
    const load = () => {
        events.forEach((name) => window.removeEventListener(name, load));

        const script = document.createElement('script');
        script.async = true;
        script.src = 'https://connect.facebook.net/en_US/fbevents.js';
        document.head.appendChild(script);
    };

    events.forEach((name) =>
        window.addEventListener(name, load, { once: true, passive: true }),
    );
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
