import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { runInNewContext } from 'node:vm';
import { describe, expect, it } from 'vite-plus/test';

/*
 * The real public/mel/sw.js, run in a sandbox: what it may cache, and what
 * it does with a page load. Confidential data (pages, JSON, the API,
 * exports, photos) must never be cached.
 */

type Listener = (event: unknown) => void;

function load() {
    const listeners: Record<string, Listener> = {};
    const stored: string[] = [];
    const self: Record<string, unknown> = {
        location: { href: 'https://acme.milessoftwares.com/mel/sw.js' },
        addEventListener: (name: string, fn: Listener) => {
            listeners[name] = fn;
        },
        clients: { claim: () => Promise.resolve() },
        skipWaiting: () => undefined,
    };
    const caches = {
        open: () =>
            Promise.resolve({
                addAll: (urls: string[]) => {
                    stored.push(...urls);

                    return Promise.resolve();
                },
                put: (request: { url: string }) => {
                    stored.push(request.url);

                    return Promise.resolve();
                },
            }),
        match: () => Promise.resolve(undefined),
        keys: () => Promise.resolve([]),
    };
    const source = readFileSync(
        resolve(import.meta.dirname, '../../../public/mel/sw.js'),
        'utf8',
    );
    runInNewContext(source, { self, caches, URL, Promise, Response });

    return {
        cacheable: self.__melCacheable as (r: {
            method: string;
            url: string;
        }) => boolean,
        listeners,
        stored,
    };
}

const get = (url: string) => ({ method: 'GET', url });

describe('the service worker', () => {
    it('caches static build files, the icons and the offline page', () => {
        const { cacheable } = load();
        for (const url of [
            'https://acme.milessoftwares.com/mel/build/assets/app-3f9a1c.js',
            'https://acme.milessoftwares.com/mel/build/assets/app-3f9a1c.css',
            'https://acme.milessoftwares.com/mel/build/assets/inter-latin.woff2',
            'https://acme.milessoftwares.com/mel/icons/icon-192.png',
            'https://acme.milessoftwares.com/mel/offline.html',
        ]) {
            expect(cacheable(get(url)), url).toBe(true);
        }
    });

    it('never caches pages, data, the API, exports or photos', () => {
        const { cacheable } = load();
        for (const url of [
            'https://acme.milessoftwares.com/mel',
            'https://acme.milessoftwares.com/mel/',
            'https://acme.milessoftwares.com/mel/collect/submissions',
            'https://acme.milessoftwares.com/mel/collect/submissions/01936f5e-0000-7000-8000-000000000000',
            'https://acme.milessoftwares.com/mel/collect/submissions/x/photos/y',
            'https://acme.milessoftwares.com/mel/collect/exports/x/download',
            'https://acme.milessoftwares.com/mel/api/v1/collect/assignments',
            'https://acme.milessoftwares.com/mel/integration/v1/projects/x/verified-results',
            'https://acme.milessoftwares.com/mel/build/manifest.json',
            'https://acme.milessoftwares.com/mel/build/assets/app.js?v=1',
            'https://acme.milessoftwares.com/finance/build/assets/app.js',
            'https://evil.example/mel/build/assets/app.js',
        ]) {
            expect(cacheable(get(url)), url).toBe(false);
        }
        expect(
            cacheable({
                method: 'POST',
                url: 'https://acme.milessoftwares.com/mel/build/assets/app.js',
            }),
        ).toBe(false);
    });

    it('precaches only the data-free shell on install', async () => {
        const { listeners, stored } = load();
        let done: Promise<unknown> = Promise.resolve();
        listeners.install({
            waitUntil: (p: Promise<unknown>) => {
                done = p;
            },
        });
        await done;
        expect(stored).toEqual([
            'https://acme.milessoftwares.com/mel/offline.html',
            'https://acme.milessoftwares.com/mel/icons/icon-192.png',
            'https://acme.milessoftwares.com/mel/icons/icon-512.png',
            'https://acme.milessoftwares.com/mel/manifest.webmanifest',
        ]);
    });

    it('leaves data requests to the browser, uncached', () => {
        const { listeners } = load();
        let answered = false;
        listeners.fetch({
            request: {
                method: 'GET',
                mode: 'cors',
                url: 'https://acme.milessoftwares.com/mel/collect/submissions',
            },
            respondWith: () => {
                answered = true;
            },
        });
        expect(answered).toBe(false);
    });
});
