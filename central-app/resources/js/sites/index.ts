/**
 * Inertia page names are `{site}/{page}`: `public/pricing` is
 * sites/public/pages/pricing.tsx. The PHP side of this rule is
 * App\Support\Inertia\SitePages.
 *
 * Pages and each site's layouts load on demand, so a visitor of one site
 * never downloads another site's pages or chrome.
 */
import type { ResolvedComponent } from '@inertiajs/react';

export const sites = ['public', 'admin', 'central'] as const;

export type Site = (typeof sites)[number];

type SiteModule = { layout: (page: string) => unknown };

const pages = import.meta.glob<{ default: ResolvedComponent }>([
    './*/pages/**/*.tsx',
    '!./*/pages/**/*.test.tsx',
]);
const siteModules = import.meta.glob<SiteModule>('./*/index.ts');
const loaded: Partial<Record<Site, SiteModule>> = {};

export function splitPageName(name: string): { site: Site; page: string } {
    const slash = name.indexOf('/');
    const site = sites.find((s) => s === name.slice(0, slash));

    if (slash < 1 || site === undefined) {
        throw new Error(
            `Page "${name}" must start with a site: ${sites.join(', ')}.`,
        );
    }

    return { site, page: name.slice(slash + 1) };
}

export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const { site, page } = splitPageName(name);
    const load = pages[`./${site}/pages/${page}.tsx`];

    if (load === undefined) {
        throw new Error(`Page not found: ${name}`);
    }

    const [module, siteModule] = await Promise.all([
        load(),
        siteModules[`./${site}/index.ts`](),
    ]);
    loaded[site] = siteModule;

    return module.default;
}

/**
 * Default layout for a page. resolvePage() has always loaded the page's
 * site module by the time Inertia asks.
 */
export function resolveLayout(name: string): unknown {
    const { site, page } = splitPageName(name);

    return loaded[site]?.layout(page) ?? null;
}
