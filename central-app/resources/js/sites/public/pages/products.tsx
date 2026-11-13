import { Link } from '@inertiajs/react';
import { ArrowRight, Download, MonitorSmartphone } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { CtaBand } from '@/sites/public/components/blocks';
import {
    ProductCard,
    StatusBadge,
    categoryLabels,
} from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import {
    Accent,
    HeroBackdrop,
    SpeedMark,
} from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, container, display } from '@/sites/public/components/styles';

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
                title="ERP software and business applications"
                description="Tatook ERP for pharmacy, retail, restaurant, school, logistics and NGOs, plus accounting, HR, payroll, inventory, procurement and project management."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'ItemList',
                    name: 'Tatook products',
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

            <ProductsHero
                erp={byCategory('erp')}
                applications={byCategory('application')}
                trialDays={trialDays}
            />

            {/* The filter: stays under the fixed header while the list scrolls. */}
            <div className="sticky top-14 z-30 border-b border-site-line bg-white/90 backdrop-blur sm:top-16">
                <div className="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6 lg:px-8">
                    <div
                        role="group"
                        aria-label="Show products"
                        className="flex min-w-0 flex-1 overflow-x-auto"
                    >
                        <div className="inline-flex gap-1 rounded-full bg-site-wash p-1 ring-1 ring-site-line">
                            {choices.map(({ value, label, count }) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={filter === value}
                                    onClick={() => choose(value)}
                                    className="group/choice inline-flex shrink-0 items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold whitespace-nowrap text-slate-600 transition-colors hover:text-site-accent-ink aria-pressed:bg-site-ink aria-pressed:text-white aria-pressed:shadow-md aria-pressed:shadow-site-night/20"
                                >
                                    {label}
                                    <span className="rounded-full bg-white px-2 py-0.5 text-xs font-bold text-site-accent-ink ring-1 ring-site-line group-aria-pressed/choice:bg-site-highlight group-aria-pressed/choice:text-site-night group-aria-pressed/choice:ring-0">
                                        {count}
                                    </span>
                                </button>
                            ))}
                        </div>
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
                                className={`scroll-mt-32 ${i % 2 === 1 ? 'border-t border-site-line bg-site-wash' : 'bg-white'}`}
                            >
                                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8 sm:py-20">
                                    <SectionHeading
                                        id={headingId}
                                        title={section.title}
                                        lead={section.lead}
                                        align="left"
                                    />
                                    <ul className="mt-12 grid gap-x-6 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
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
                <div className="mx-auto max-w-7xl px-4 pt-14 sm:px-6 lg:px-8 sm:pt-16">
                    {/* A coupon: notched edges and a dashed tear line. */}
                    <div className="relative flex flex-col gap-6 overflow-hidden rounded-xl bg-site-wash p-6 ring-1 ring-site-line sm:p-8 md:flex-row md:items-center md:gap-8">
                        <div className="flex flex-1 flex-col items-start gap-5 sm:flex-row sm:items-center">
                            <span className="inline-flex size-16 shrink-0 items-center justify-center rounded-2xl bg-linear-160 from-[#0a5a26] to-[#032a16] text-site-highlight shadow-lg shadow-site-night/20">
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
                body={`Every Tatook product shares one sign-in, one organization and one set of books. Start a ${trialDays}-day free trial of the one you need first.`}
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
 * The page's opening band: dark green, the title and intro, and beside
 * them a fan of three product cards with their promotion badges, as on the
 * cards below. The fan is decorative and shows from `lg`; with reduced
 * motion it stays still.
 */
function ProductsHero({
    erp,
    applications,
    trialDays,
}: {
    erp: CatalogEntry[];
    applications: CatalogEntry[];
    trialDays: number;
}) {
    const fan = [
        {
            product: erp[0],
            place: 'top-6 left-0 -rotate-[7deg]',
            float: 'site-float-slow',
        },
        {
            product: erp[1],
            place: 'top-2 right-0 rotate-[6deg]',
            float: 'site-float-slow',
        },
        {
            product: applications[0],
            place: 'top-[9rem] left-1/2 z-10 -translate-x-1/2 -rotate-[2deg]',
            float: 'site-float',
        },
    ].filter((card) => card.product);

    return (
        <section className="site-dark relative isolate overflow-hidden bg-[radial-gradient(circle_at_78%_40%,#11512b,#063519_55%,#032a16_100%)]">
            <HeroBackdrop />
            <div
                className={`${container} grid items-center gap-10 pt-24 pb-10 sm:pt-28 sm:pb-12 lg:grid-cols-[1.05fr_1fr]`}
            >
                <div className="site-rise">
                    <SpeedMark className="mb-5" />
                    <h1
                        className={`max-w-2xl text-[2.25rem] leading-[1.08] sm:text-5xl lg:text-[3.5rem] ${display}`}
                    >
                        Software for every part of{' '}
                        <Accent>your business.</Accent>
                    </h1>
                    <p className="mt-5 max-w-xl text-base leading-relaxed text-pretty text-slate-200 sm:text-lg">
                        ERP solutions and business applications on one platform:
                        one sign-in, one organization, one set of books.
                    </p>
                </div>

                <div aria-hidden className="relative hidden h-[22rem] lg:block">
                    <div className="absolute top-1/2 left-1/2 size-80 -translate-1/2 rounded-full bg-site-accent/20 blur-3xl" />
                    {fan.map(({ product, place, float }) => (
                        <div key={product.slug} className={`absolute ${place}`}>
                            <div className={float}>
                                <FanCard
                                    product={product}
                                    trialDays={trialDays}
                                />
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/** A small likeness of a product card, for the hero's fan. */
function FanCard({
    product,
    trialDays,
}: {
    product: CatalogEntry;
    trialDays: number;
}) {
    return (
        <div className="relative w-60 rounded-xl bg-white shadow-[0_30px_60px_-20px_rgb(0_0_0/0.6)] ring-1 ring-white/20 xl:w-64">
            <div className="relative flex h-24 items-end overflow-hidden rounded-t-xl bg-linear-160 from-[#0d6a2e] to-[#04381a] px-5 pr-24 pb-4">
                <span className="absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.16)_1px,transparent_1.5px)] [background-size:12px_12px] [mask-image:linear-gradient(to_bottom,black,transparent)]" />
                <span className="relative text-lg leading-tight font-semibold text-white">
                    {product.name}
                </span>
                <span className="absolute bottom-0 left-5 h-1 w-8 rounded-t-full bg-site-highlight" />
            </div>
            <StatusBadge
                status={product.status}
                trialDays={product.trial ? trialDays : undefined}
            />
            <div className="space-y-2 p-5">
                <i className="block h-2 w-full rounded-full bg-site-line" />
                <i className="block h-2 w-4/5 rounded-full bg-site-line" />
                <i className="block h-2 w-3/5 rounded-full bg-site-line/70" />
                <span className="mt-5 flex items-center gap-2">
                    <b className="block h-8 w-32 rounded-full bg-site-ink" />
                    <b className="block h-2 w-14 rounded-full bg-site-line" />
                </span>
            </div>
        </div>
    );
}
