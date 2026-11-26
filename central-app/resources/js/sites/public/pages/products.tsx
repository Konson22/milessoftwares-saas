import { Link } from '@inertiajs/react';
import { ArrowRight, Check, Download, MonitorSmartphone } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { CtaBand } from '@/sites/public/components/blocks';
import { ProductCard, categoryLabels } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, container } from '@/sites/public/components/styles';

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

const shortLabels: Record<Category, string> = {
    erp: 'ERP',
    application: 'Apps',
};

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

    const choices: {
        value: Category | 'all';
        label: string;
        /** The label on a phone, where all three sit side by side. */
        short: string;
        count: number;
    }[] = [
        {
            value: 'all',
            label: 'All products',
            short: 'All',
            count: catalog.length,
        },
        ...categories.map((category) => ({
            value: category,
            label: categoryLabels[category],
            short: shortLabels[category],
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
                title="ERP software and business applications"
                description="Miles ERP for pharmacy, retail, restaurant, school, logistics and NGOs, plus accounting, HR, payroll, inventory, procurement and project management."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'ItemList',
                    name: 'Miles Global Softwares products',
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

            <ProductsHero trialDays={trialDays} count={catalog.length} />

            {/* The filter: stays under the fixed header while the list scrolls. */}
            <div className="sticky top-14 z-30 border-b border-site-line bg-white sm:top-16">
                <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-2.5 sm:px-6 sm:py-3 lg:px-8">
                    <div
                        role="group"
                        aria-label="Show products"
                        className="grid w-full grid-cols-3 gap-1 rounded-xl bg-site-wash p-1 ring-1 ring-site-line sm:inline-grid sm:w-auto sm:auto-cols-max sm:grid-flow-col sm:grid-cols-none sm:rounded-full"
                    >
                        {choices.map(({ value, label, short, count }) => (
                            <button
                                key={value}
                                type="button"
                                aria-pressed={filter === value}
                                onClick={() => choose(value)}
                                className="group/choice inline-flex min-w-0 items-center justify-center gap-1.5 rounded-lg px-2 py-2 text-sm font-semibold whitespace-nowrap text-slate-600 transition-colors hover:text-site-accent-ink aria-pressed:bg-site-ink aria-pressed:text-white aria-pressed:shadow-md aria-pressed:shadow-site-night/20 sm:gap-2 sm:rounded-full sm:px-4"
                            >
                                <span className="sm:hidden">{short}</span>
                                <span className="hidden sm:inline">
                                    {label}
                                </span>
                                <span className="rounded-full bg-white px-1.5 py-0.5 text-xs font-bold text-site-accent-ink ring-1 ring-site-line group-aria-pressed/choice:bg-site-highlight group-aria-pressed/choice:text-site-night group-aria-pressed/choice:ring-0 sm:px-2">
                                    {count}
                                </span>
                            </button>
                        ))}
                    </div>
                    <Link
                        href="/downloads"
                        className="ml-auto hidden shrink-0 items-center gap-1.5 text-sm font-semibold text-site-accent-ink hover:underline md:inline-flex"
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
                        const entries = byCategory(category);

                        return (
                            <section
                                key={category}
                                id={section.id}
                                aria-labelledby={headingId}
                                className={`scroll-mt-32 ${i % 2 === 1 ? 'border-t border-site-line bg-site-wash' : 'bg-white'}`}
                            >
                                <div className="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8 lg:py-20">
                                    <SectionHeading
                                        id={headingId}
                                        title={section.title}
                                        lead={section.lead}
                                        align="left"
                                    />
                                    <ul className="mt-10 grid gap-x-6 gap-y-10 sm:mt-12 md:grid-cols-2 xl:grid-cols-3">
                                        {entries.map((entry) => (
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
                <div className="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8 sm:pt-16">
                    {/* A coupon: notched edges and a dashed tear line. */}
                    <div className="relative flex flex-col gap-6 overflow-hidden rounded-xl bg-site-wash p-6 ring-1 ring-site-line sm:p-8 md:flex-row md:items-center md:gap-8">
                        <div className="flex flex-1 flex-col items-start gap-5 sm:flex-row sm:items-center">
                            <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-2xl bg-site-navy text-site-highlight shadow-lg shadow-site-night/20">
                                <MonitorSmartphone
                                    className="size-8"
                                    aria-hidden
                                />
                            </span>
                            <div>
                                <h2
                                    id="devices-heading"
                                    className="text-xl font-semibold text-site-ink"
                                >
                                    Also on your desktop and phone
                                </h2>
                                <p className="mt-1 max-w-2xl text-slate-600">
                                    Some products have Windows and mobile apps
                                    that work offline and sync with the cloud.
                                    Each card above says where it runs today.
                                </p>
                            </div>
                        </div>
                        <div
                            aria-hidden
                            className="relative -mx-6 border-t-2 border-dashed border-site-line sm:-mx-8 md:mx-0 md:-my-8 md:self-stretch md:border-t-0 md:border-l-2"
                        >
                            <span className="absolute top-0 -left-4 size-8 -translate-y-1/2 rounded-full bg-white ring-1 ring-site-line md:top-[-1rem] md:left-0 md:-translate-x-[calc(50%+1px)] md:translate-y-0" />
                            <span className="absolute top-0 -right-4 size-8 -translate-y-1/2 rounded-full bg-white ring-1 ring-site-line md:top-auto md:right-auto md:bottom-[-1rem] md:left-0 md:-translate-x-[calc(50%+1px)] md:translate-y-0" />
                        </div>
                        <div className="flex flex-wrap gap-3">
                            <Link href="/downloads" className={button.primary}>
                                <Download className="size-4" aria-hidden />
                                Desktop downloads
                            </Link>
                            <Link href="/pricing" className={button.outline}>
                                See pricing
                            </Link>
                        </div>
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
                body={`Every Miles Global Softwares product shares one sign-in, one organization and one set of books. Start a ${trialDays}-day free trial of the one you need first.`}
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

/**
 * The page's opening band, solid navy: the title, intro, actions and
 * three facts on the left, and the dashboards-and-globe picture framed on
 * the right from `lg` (under the copy below it). The picture's own dark
 * backdrop sits in a rounded frame, as its glow reaches the edges and
 * would not blend into a flat colour. White title, light cyan accent,
 * near-white body.
 */
function ProductsHero({
    trialDays,
    count,
}: {
    trialDays: number;
    count: number;
}) {
    const facts = [
        `${count} products, one sign-in`,
        'Web, Windows desktop and mobile',
        'Works offline where you need it',
    ];

    return (
        <section className="site-dark relative isolate overflow-hidden bg-site-navy">
            <div
                className={`${container} grid items-center gap-10 pt-24 pb-14 sm:pt-28 sm:pb-16 lg:grid-cols-[1fr_1.05fr] lg:gap-12 lg:pb-20`}
            >
                <div className="site-rise min-w-0 max-w-xl">
                    <h1 className="text-[2.25rem] leading-[1.08] font-semibold tracking-tight text-balance text-white sm:text-5xl lg:text-[3.5rem]">
                        One platform for{' '}
                        <span className="text-[#9fe0ff]">
                            every part of your business.
                        </span>
                    </h1>
                    <p className="mt-5 max-w-lg text-base leading-relaxed text-pretty text-[#dce8ff] sm:text-lg">
                        Ready-made ERPs for pharmacies, shops and more, plus
                        accounting, HR, payroll, inventory and project apps.
                        Everything shares your sign-in, your organization and
                        your books.
                    </p>
                    <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <Link
                            href="/start"
                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-white px-5 py-3 text-sm font-semibold text-site-navy transition-colors hover:bg-[#e6f6ff]"
                        >
                            Start your {trialDays}-day free trial
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                        <Link
                            href="/contact"
                            className="inline-flex items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white ring-1 ring-[#9fe0ff] transition-colors hover:bg-site-navy-light"
                        >
                            Talk to us
                        </Link>
                    </div>
                    <ul className="mt-8 grid gap-2.5 text-sm font-medium text-white sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                        {facts.map((fact) => (
                            <li
                                key={fact}
                                className="flex items-center gap-2.5"
                            >
                                <span className="inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-[#9fe0ff] text-site-navy">
                                    <Check
                                        className="size-3"
                                        strokeWidth={3}
                                        aria-hidden
                                    />
                                </span>
                                {fact}
                            </li>
                        ))}
                    </ul>
                </div>
                <div className="site-rise">
                    <img
                        src="/images/hero/products-hero.webp"
                        srcSet="/images/hero/products-hero-900.webp 900w, /images/hero/products-hero.webp 1536w"
                        sizes="(min-width: 1024px) 50vw, 100vw"
                        width={1536}
                        height={1024}
                        alt="Pharmacy, retail and project dashboards in front of a globe"
                        fetchPriority="high"
                        className="w-full rounded-2xl ring-1 ring-site-navy-line"
                    />
                </div>
            </div>
        </section>
    );
}
