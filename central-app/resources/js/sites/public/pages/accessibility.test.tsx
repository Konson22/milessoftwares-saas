// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react';
import axe from 'axe-core';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import MarketingLayout from '@/sites/public/layouts/marketing-layout';
import About from './about';
import ComingSoon from './coming-soon';
import Contact from './contact';
import CatalogIndex from './catalog';
import MarketingHome from './home';
import LandingPage from './landing';
import Platform from './platform';
import Pricing from './pricing';
import ProductPage from './product';
import Solutions from './solutions';
import Start from './start';
import Thanks from './thanks';

// Inertia's components need a running app; for checking markup they are
// replaced with their plain HTML equivalents.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
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
    name: 'Miles Pharmacy ERP',
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
    seoDescription: 'Pharmacy ERP by Miles Softwares.',
    features: [{ icon: 'pill', title: 'Expiry first', body: 'Sells oldest.' }],
    workflow: [{ title: 'Receive', body: 'Batches in.' }],
    faqs: [{ question: 'Is there a trial?', answer: 'Yes.' }],
    url: 'erp/pharmacy',
    status: 'early_access' as const,
    includes: erpProduct.applications,
    trial: 'pharmacy',
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
        name: 'Miles Finance',
        url: 'apps/finance',
        status: 'available' as const,
        includes: [],
        trial: 'finance',
    },
    {
        ...entry,
        slug: 'hr',
        category: 'application' as const,
        name: 'Miles HR',
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
        name: 'Miles Pharmacy ERP',
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
        'erp solutions',
        <CatalogIndex
            key="erp"
            category="erp"
            catalog={catalog.filter((item) => item.category === 'erp')}
            trialDays={60}
            canonical="https://example.test/erp"
        />,
    ],
    [
        'business applications',
        <CatalogIndex
            key="apps"
            category="application"
            catalog={catalog.filter((item) => item.category === 'application')}
            trialDays={60}
            canonical="https://example.test/apps"
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
        'industries',
        <Solutions
            key="industries"
            catalog={catalog}
            canonical="https://example.test/industries"
        />,
    ],
    [
        'platform',
        <Platform key="platform" canonical="https://example.test/platform" />,
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
    ['thanks', <Thanks key="thanks" />],
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
    );

    it.each(pages)('%s has exactly one top-level heading', (_name, page) => {
        const { container } = render(<MarketingLayout>{page}</MarketingLayout>);

        expect(container.querySelectorAll('h1')).toHaveLength(1);
    });
});
