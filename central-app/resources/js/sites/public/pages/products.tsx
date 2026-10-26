import { Link } from '@inertiajs/react';
import { ArrowRight, Download, MonitorSmartphone } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { CtaBand, Eyebrow } from '@/sites/public/components/blocks';
import { ProductCard, categoryLabels } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

type Props = {
    catalog: CatalogEntry[];
    trialDays: number;
    canonical: string;
};

type Category = CatalogEntry['category'];

/** Each category's section: its anchor (old /erp and /apps links land on it) and copy. */
const sections: Record<
    Category,
    { id: string; title: ReactNode; lead: string }
> = {
    erp: {
        id: 'erp',
        title: (
            <>
                An ERP built for <Accent>your industry.</Accent>
            </>
        ),
        lead: 'Ready-made systems for pharmacies and shops, with restaurants, schools, logistics and NGOs on the way. Each works on its own, with Finance & Accounting as an optional add-on.',
    },
    application: {
        id: 'apps',
        title: (
            <>
                Business applications that <Accent>work together.</Accent>
            </>
        ),
        lead: 'Start with the one application you need. Each shares your sign-in, your organization and your books.',
    },
};

const categories: Category[] = ['erp', 'application'];

/** Available products first, then early access, then coming soon. */
const order = { available: 0, early_access: 1, coming_soon: 2 };

/**
 * /products: the whole catalog on one page, ERP solutions then business
 * applications, with a filter that stays under the header.
 */
export default function Products({ catalog, trialDays, canonical }: Props) {
    const [filter, setFilter] = useState<Category | 'all'>('all');
    const list = useRef<HTMLDivElement>(null);

    const byCategory = (category: Category) =>
        catalog
            .filter((entry) => entry.category === category)
            .sort((a, b) => order[a.status] - order[b.status]);

    const choices: { value: Category | 'all'; label: string; count: number }[] =
        [
            { value: 'all', label: 'All products', count: catalog.length },
            ...categories.map((category) => ({
                value: category,
                label: categoryLabels[category],
                count: byCategory(category).length,
            })),
        ];

    const choose = (value: Category | 'all') => {
        setFilter(value);

        // Past the top of the list, bring its start back under the bar.
        const top = list.current?.getBoundingClientRect().top ?? 0;

        if (top < 0) {
            list.current?.scrollIntoView({ block: 'start' });
        }
    };

    return (
        <>
            <Seo
                title="Products"
                description="ERP solutions and business applications from Miles Global Softwares, on one connected platform: pharmacy, retail, restaurant, school, logistics and NGO systems, and finance, HR, CRM and more."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'ItemList',
                    name: 'Miles products',
                    itemListElement: categories
                        .flatMap(byCategory)
                        .map((entry, i) => ({
                            '@type': 'ListItem',
                            position: i + 1,
                            name: entry.name,
                            url: `${new URL(canonical).origin}/${entry.url}`,
                        })),
                }}
            />

            <PageHero
                title={
                    <>
                        Software for every part of{' '}
                        <Accent>your business.</Accent>
                    </>
                }
                intro="ERP solutions and business applications on one platform: one sign-in, one organization, one set of books."
            />

            {/* The filter: stays under the fixed header while the list scrolls. */}
            <div className="sticky top-14 z-30 border-b border-site-line bg-white sm:top-16">
                <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6">
                    <div
                        role="group"
                        aria-label="Show products"
                        className="-mx-1 flex min-w-0 flex-1 gap-2 overflow-x-auto px-1"
                    >
                        {choices.map(({ value, label, count }) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={filter === value}
                                onClick={() => choose(value)}
                                className="inline-flex shrink-0 items-center gap-2 rounded-full border border-site-line px-4 py-2 text-sm font-semibold whitespace-nowrap text-site-ink transition-colors hover:border-site-accent-ink hover:text-site-accent-ink aria-pressed:border-site-accent-ink aria-pressed:bg-site-accent-ink aria-pressed:text-white"
                            >
                                {label}
                                <span className="text-xs opacity-75">
                                    {count}
                                </span>
                            </button>
                        ))}
                    </div>
                    <Link
                        href="/downloads"
                        className="hidden shrink-0 items-center gap-1.5 text-sm font-semibold text-site-accent-ink hover:underline md:inline-flex"
                    >
                        <Download className="size-4" aria-hidden />
                        Desktop downloads
                    </Link>
                </div>
            </div>

            <div ref={list} className="scroll-mt-32">
                {categories
                    .filter(
                        (category) => filter === 'all' || filter === category,
                    )
                    .map((category, i) => {
                        const section = sections[category];
                        const headingId = `${section.id}-heading`;

                        return (
                            <section
                                key={category}
                                id={section.id}
                                aria-labelledby={headingId}
                                className={`scroll-mt-32 ${i % 2 === 1 ? 'border-t border-site-line' : ''} bg-white`}
                            >
                                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
                                    <Eyebrow>
                                        {categoryLabels[category]} ·{' '}
                                        {byCategory(category).length}
                                    </Eyebrow>
                                    <SectionHeading
                                        id={headingId}
                                        title={section.title}
                                        lead={section.lead}
                                        align="left"
                                    />
                                    <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                                        {byCategory(category).map((entry) => (
                                            <li key={entry.slug}>
                                                <ProductCard
                                                    product={entry}
                                                    trialDays={trialDays}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </section>
                        );
                    })}
            </div>

            {/* Where the products run, beyond the browser. */}
            <section
                aria-labelledby="devices-heading"
                className="border-t border-site-line bg-white"
            >
                <div className="mx-auto flex max-w-7xl flex-col items-start gap-6 px-4 py-12 sm:px-6 md:flex-row md:items-center">
                    <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-2xl bg-site-accent/10 text-site-accent-ink">
                        <MonitorSmartphone className="size-7" aria-hidden />
                    </span>
                    <div className="flex-1">
                        <h2
                            id="devices-heading"
                            className="text-xl font-semibold text-site-ink"
                        >
                            Also on your desktop and phone
                        </h2>
                        <p className="mt-1 max-w-2xl text-slate-600">
                            Some products have Windows and mobile apps that work
                            offline and sync with the cloud. Each card above
                            says where it runs today.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Link href="/downloads" className={button.outline}>
                            <Download className="size-4" aria-hidden />
                            Desktop downloads
                        </Link>
                        <Link href="/pricing" className={button.outline}>
                            See pricing
                        </Link>
                    </div>
                </div>
            </section>

            <CtaBand
                id="products-cta-heading"
                title={
                    <>
                        Run your business. <Accent>All connected.</Accent>
                    </>
                }
                body={`Every Miles product shares one sign-in, one organization and one set of books. Start a ${trialDays}-day free trial of the one you need first.`}
            >
                <Link href="/start" className={button.light}>
                    Start Your {trialDays}-Day Free Trial
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link href="/contact" className={button.outlineDark}>
                    Talk to us
                </Link>
            </CtaBand>
        </>
    );
}
