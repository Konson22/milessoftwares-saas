// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react';
import axe from 'axe-core';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import MarketingLayout from '@/sites/public/layouts/marketing-layout';
import About from './about';
import ComingSoon from './coming-soon';
import Contact from './contact';
import Downloads from './downloads';
import MarketingHome from './home';
import FinancePage from './finance';
import LandingPage from './landing';
import Platform from './platform';
import Pricing from './pricing';
import Privacy from './privacy';
import PrivacyMataemPlus from './privacy-mataem-plus';
import PrivacyMilesPharmacy from './privacy-miles-pharmacy';
import Services from './services';
import ProductPage from './product';
import Products from './products';
import Start from './start';
import Terms from './terms';
import Thanks from './thanks';

// Inertia's components need a running app; for checking markup they are
// replaced with their plain HTML equivalents.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { on: () => () => {} },
    usePage: () => ({ url: '/pricing', props: { trialDays: 60 } }),
    Link: ({
        href,
        children,
        ...rest
    }: {
        href: string;
        children: ReactNode;
    }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
    Form: ({
        children,
        action,
        method,
        className,
    }: {
        children: (state: {
            errors: Record<string, string>;
            processing: boolean;
        }) => ReactNode;
        action: string;
        method: string;
        className?: string;
    }) => (
        <form action={action} method={method} className={className}>
            {children({ errors: {}, processing: false })}
        </form>
    ),
}));

const product = {
    code: 'retail',
    name: 'Retail',
    tagline: 'Sell more, count less.',
    description: 'Point of sale and stock.',
    status: 'available' as const,
    features: ['Multi-branch stock', 'Offline tills'],
};

const plan = {
    code: 'retail-std',
    name: 'Retail Standard',
    description: null,
    price: 'USD 25.00',
    interval: 'monthly' as const,
    trialDays: 14,
    isBundle: false,
    applications: ['Retail'],
    product: null,
};

const erpProduct = {
    code: 'pharmacy',
    name: 'Pharmacy ERP',
    tagline: 'Medicines and the books.',
    description: null,
    status: 'pilot' as const,
    applications: [
        {
            code: 'pharmacy',
            name: 'Pharmacy',
            requirement: 'required' as const,
            listed: true,
        },
        {
            code: 'hr',
            name: 'HR',
            requirement: 'optional' as const,
            listed: false,
        },
    ],
};

const entry = {
    slug: 'pharmacy',
    category: 'erp' as const,
    name: 'Pharmacy ERP',
    icon: 'pill',
    summary: 'Medicines, batches and the books.',
    headline: 'Run your pharmacy',
    subhead: 'Expiry-first selling and books that keep themselves.',
    audience: 'Pharmacies and wholesalers',
    seoTitle: 'Pharmacy ERP',
    seoDescription: 'Pharmacy ERP by Miles Global Softwares.',
    features: [{ icon: 'pill', title: 'Expiry first', body: 'Sells oldest.' }],
    workflow: [{ title: 'Receive', body: 'Batches in.' }],
    faqs: [{ question: 'Is there a trial?', answer: 'Yes.' }],
    url: 'erp/pharmacy',
    status: 'early_access' as const,
    includes: erpProduct.applications,
    trial: 'pharmacy',
    highlights: ['Batch and expiry tracking', 'Mobile application'],
    offering: {
        platforms: ['web' as const, 'desktop' as const, 'android' as const],
        connectivity: ['online' as const, 'offline_sync' as const],
        licensing: ['subscription' as const, 'one_time' as const],
        desktop: ['Keeps selling offline'],
        mobile: ['Scan barcodes with the camera'],
    },
};

const catalog = [
    entry,
    {
        ...entry,
        slug: 'ngo',
        name: 'NGO ERP',
        url: 'erp/ngo',
        status: 'coming_soon' as const,
        trial: null,
    },
    {
        ...entry,
        slug: 'finance',
        category: 'application' as const,
        name: 'Finance & Accounting',
        url: 'apps/finance',
        status: 'available' as const,
        includes: [],
        trial: 'finance',
    },
    {
        ...entry,
        slug: 'hr',
        category: 'application' as const,
        name: 'HR',
        url: 'apps/hr',
        status: 'available' as const,
        includes: [],
        trial: null,
    },
];

const productPlan = {
    ...plan,
    code: 'pharmacy-std',
    name: 'Pharmacy Standard',
    product: {
        code: 'pharmacy',
        name: 'Pharmacy ERP',
        status: 'pilot' as const,
    },
};

const pages: [string, ReactNode][] = [
    [
        'home',
        <MarketingHome
            key="home"
            products={[
                product,
                {
                    ...product,
                    code: 'school',
                    name: 'School',
                    status: 'planned',
                },
            ]}
            catalog={catalog}
            trialDays={60}
            canonical="https://example.test/"
        />,
    ],
    [
        'product',
        <ProductPage
            key="product"
            product={product}
            plans={[plan]}
            trialDays={60}
            canonical="https://example.test/products/retail"
        />,
    ],
    [
        'finance',
        <FinancePage
            key="finance"
            product={{
                ...entry,
                slug: 'finance',
                category: 'application',
                name: 'Finance & Accounting',
                url: 'apps/finance',
                status: 'available',
                includes: [],
                trial: 'finance',
                offering: {
                    platforms: ['web'],
                    connectivity: ['online'],
                    licensing: ['subscription'],
                    desktop: [],
                    mobile: [],
                },
            }}
            plans={[plan, { ...plan, code: 'bundle', name: 'Bundle', isBundle: true }]}
            trialDays={60}
            canonical="https://example.test/apps/finance"
        />,
    ],
    [
        'landing',
        <LandingPage
            key="landing"
            product={entry}
            plans={[productPlan]}
            trialDays={60}
            canonical="https://example.test/erp/pharmacy"
        />,
    ],
    [
        'coming-soon landing',
        <LandingPage
            key="landing-soon"
            product={catalog[1]}
            plans={[]}
            trialDays={60}
            canonical="https://example.test/erp/ngo"
        />,
    ],
    [
        'products',
        <Products
            key="products"
            catalog={catalog}
            trialDays={60}
            canonical="https://example.test/products"
        />,
    ],
    [
        'pricing',
        <Pricing
            key="pricing"
            plans={[
                plan,
                { ...plan, code: 'all', isBundle: true },
                productPlan,
            ]}
            catalog={catalog}
            trialDays={60}
            canonical="https://example.test/pricing"
        />,
    ],
    [
        'start',
        <Start
            key="start"
            products={[
                product,
                { ...product, code: 'pharmacy', name: 'Pharmacy' },
            ]}
            selected="pharmacy"
            trialDays={60}
            canonical="https://example.test/start"
        />,
    ],
    [
        'contact',
        <Contact
            key="contact"
            formToken="t"
            subject="Waitlist: School"
            canonical="https://example.test/contact"
        />,
    ],
    [
        'platform',
        <Platform key="platform" canonical="https://example.test/platform" />,
    ],
    [
        'services',
        <Services
            key="services"
            trialDays={60}
            canonical="https://example.test/services"
        />,
    ],
    [
        'coming soon',
        <ComingSoon
            key="coming-soon"
            catalog={[catalog[1]]}
            trialDays={60}
            canonical="https://example.test/coming-soon"
        />,
    ],
    ['about', <About key="about" canonical="https://example.test/about" />],
    [
        'privacy',
        <Privacy key="privacy" canonical="https://example.test/privacy" />,
    ],
    [
        'Mataem Plus privacy',
        <PrivacyMataemPlus
            key="privacy-mataem-plus"
            canonical="https://example.test/privacy/mataem-plus"
        />,
    ],
    [
        'Miles Pharmacy privacy',
        <PrivacyMilesPharmacy
            key="privacy-miles-pharmacy"
            canonical="https://example.test/privacy/miles-pharmacy"
        />,
    ],
    [
        'terms',
        <Terms
            key="terms"
            trialDays={60}
            canonical="https://example.test/terms"
        />,
    ],
    ['thanks', <Thanks key="thanks" />],
    [
        'downloads',
        <Downloads
            key="downloads"
            apps={[
                {
                    code: 'pharmacy',
                    platform: 'windows',
                    name: 'Miles Pharmacy',
                    summary: 'The pharmacy on a PC.',
                    needs: 'Nothing to start',
                    version: '1.1.0',
                    product: 'erp/pharmacy',
                    url: 'https://example.test/download/pharmacy',
                    size: 113_000_000,
                    store: null,
                    releases: [
                        {
                            version: '1.1.0',
                            date: '2026-10-31',
                            changes: ['Licence keys.'],
                            url: null,
                        },
                        {
                            version: '1.0.0',
                            date: '2026-10-25',
                            changes: ['First release.'],
                            url: 'https://example.test/download/pharmacy/1.0.0',
                        },
                    ],
                },
                {
                    code: 'retail-android',
                    platform: 'android',
                    name: 'Miles Retail',
                    summary: 'The shop counter on a phone.',
                    needs: 'A Miles workspace',
                    version: '1.0.0',
                    product: 'erp/retail',
                    url: null,
                    size: null,
                    store: 'https://play.google.com/store/apps/details?id=com.milesoftwares.retail',
                    releases: [],
                },
            ]}
            canonical="https://example.test/downloads"
        />,
    ],
];

afterEach(() => cleanup());

describe('marketing pages', () => {
    it.each(pages)(
        '%s has no detectable accessibility violations',
        async (_name, page) => {
            const { container } = render(
                <MarketingLayout>{page}</MarketingLayout>,
            );

            const results = await axe.run(container, {
                // Colour contrast needs real layout and styles, which jsdom lacks;
                // it is covered by the Lighthouse run against the built site.
                rules: { 'color-contrast': { enabled: false } },
            });

            expect(
                results.violations.map(
                    (violation) =>
                        `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`,
                ),
            ).toEqual([]);
        },
        // The first run loads axe and jsdom, which takes longer than the
        // 5-second default on slower machines.
        30_000,
    );

    it.each(pages)('%s has exactly one top-level heading', (_name, page) => {
        const { container } = render(<MarketingLayout>{page}</MarketingLayout>);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
    });
});
