import { useEffect, useState } from 'react';

/*
 * The desktop app (ADR-0019 §23): M&E installs as a PWA. The service
 * worker (public/mel/sw.js) caches static build files only, never data;
 * see its header. This file registers it and reports the connection and
 * waiting updates to the layout.
 */

export const SERVICE_WORKER_URL = '/mel/sw.js';
export const SERVICE_WORKER_SCOPE = '/mel/';

type UpdateListener = (registration: ServiceWorkerRegistration) => void;
const listeners = new Set<UpdateListener>();
let waiting: ServiceWorkerRegistration | null = null;

function announce(registration: ServiceWorkerRegistration) {
    waiting = registration;
    listeners.forEach((listener) => listener(registration));
}

/** Registers the worker once, in production builds, where supported. */
export function registerServiceWorker(
    container: ServiceWorkerContainer | undefined = typeof navigator !==
    'undefined'
        ? navigator.serviceWorker
        : undefined,
): void {
    if (!container || import.meta.env.DEV) return;

    void container
        .register(SERVICE_WORKER_URL, { scope: SERVICE_WORKER_SCOPE })
        .then((registration) => {
            if (registration.waiting && container.controller) {
                announce(registration);
            }
            registration.addEventListener('updatefound', () => {
                const installing = registration.installing;
                installing?.addEventListener('statechange', () => {
                    // A new version is ready while an old one runs this page.
                    if (
                        installing.state === 'installed' &&
                        container.controller
                    ) {
                        announce(registration);
                    }
                });
            });
            // Look for a new version every hour while open.
            window.setInterval(
                () => void registration.update(),
                60 * 60 * 1000,
            );
        })
        .catch(() => {
            // Not installable here (private window, blocked): M&E works the same.
        });

    let reloading = false;
    container.addEventListener('controllerchange', () => {
        if (reloading) return;
        reloading = true;
        window.location.reload();
    });
}

/** A waiting new version, and the way to switch to it. */
export function useUpdate(): { ready: boolean; apply: () => void } {
    const [registration, setRegistration] =
        useState<ServiceWorkerRegistration | null>(waiting);

    useEffect(() => {
        listeners.add(setRegistration);

        return () => {
            listeners.delete(setRegistration);
        };
    }, []);

    return {
        ready: registration?.waiting != null,
        apply: () => registration?.waiting?.postMessage('skip-waiting'),
    };
}

/** Whether the browser has a connection. */
export function useOnline(): boolean {
    const [online, setOnline] = useState(
        typeof navigator === 'undefined' ? true : navigator.onLine,
    );

    useEffect(() => {
        const up = () => setOnline(true);
        const down = () => setOnline(false);
        window.addEventListener('online', up);
        window.addEventListener('offline', down);

        return () => {
            window.removeEventListener('online', up);
            window.removeEventListener('offline', down);
        };
    }, []);

    return online;
}
