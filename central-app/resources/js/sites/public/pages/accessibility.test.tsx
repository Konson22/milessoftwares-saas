// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react';
import axe from 'axe-core';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import MarketingLayout from '@/sites/public/layouts/marketing-layout';
import Contact from './contact';
import MarketingHome from './home';
import Pricing from './pricing';
import ProductPage from './product';
import Start from './start';
import Thanks from './thanks';

// Inertia's components need a running app; for checking markup they are
// replaced with their plain HTML equivalents.
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ url: '/pricing' }),
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
        'pricing',
        <Pricing
            key="pricing"
            plans={[plan, { ...plan, code: 'all', isBundle: true }]}
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
            canonical="https://example.test/contact"
        />,
    ],
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
