import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { CtaBand } from '@/sites/public/components/blocks';
import { ProductCard, categoryLabels } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

type Props = {
    category: CatalogEntry['category'];
    catalog: CatalogEntry[];
    trialDays: number;
    canonical: string;
};

const copy: Record<
    CatalogEntry['category'],
    { title: ReactNode; intro: string; description: string }
> = {
    erp: {
        title: (
            <>
                An ERP built for <Accent>your industry</Accent>
            </>
        ),
        intro: 'Ready-made systems for pharmacies and shops, with restaurants, schools, logistics and NGOs on the way, each working on its own, with Finance & Accounting as an optional add-on.',
        description:
            'Industry ERP solutions from Miles Global Softwares for pharmacies, retail, restaurants, schools, logistics and NGOs, with optional Finance & Accounting.',
    },
    application: {
        title: (
            <>
                Business applications that <Accent>work together</Accent>
            </>
        ),
        intro: 'Start with the one application you need. Each shares your sign-in, your organization and your books.',
        description:
            'Standalone business applications from Miles Global Softwares: finance, projects, HR, procurement, inventory, CRM, assets and documents, on one connected platform.',
    },
};

/** /erp and /apps: one category of the catalog, available products first. */
export default function CatalogIndex({
    category,
    catalog,
    trialDays,
    canonical,
}: Props) {
    const order = { available: 0, early_access: 1, coming_soon: 2 };
    const entries = [...catalog].sort(
        (a, b) => order[a.status] - order[b.status],
    );
    const other = category === 'erp' ? 'application' : 'erp';

    return (
        <>
            <Seo
                title={categoryLabels[category]}
                description={copy[category].description}
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'ItemList',
                    name: categoryLabels[category],
                    itemListElement: entries.map((entry, i) => ({
                        '@type': 'ListItem',
                        position: i + 1,
                        name: entry.name,
                        url: `${new URL(canonical).origin}/${entry.url}`,
                    })),
                }}
            />

            <PageHero
                above={
                    <p className="mb-5 rounded-full bg-white/10 px-4 py-1.5 text-sm font-semibold text-white ring-1 ring-white/20">
                        {categoryLabels[category]}
                    </p>
                }
                title={copy[category].title}
                intro={copy[category].intro}
            />

            <section
                aria-label={categoryLabels[category]}
                className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24"
            >
                <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {entries.map((entry) => (
                        <li key={entry.slug}>
                            <ProductCard
                                product={entry}
                                trialDays={trialDays}
                                headingLevel="h2"
                            />
                        </li>
                    ))}
                </ul>
            </section>

            <CtaBand
                id="catalog-cta-heading"
                title={
                    <>
                        Run your business. <Accent>All connected.</Accent>
                    </>
                }
                body={`Every Miles product shares one sign-in, one organization and one set of books. Start a ${trialDays}-day free trial, or explore the ${categoryLabels[other]}.`}
            >
                <Link href="/start" className={button.light}>
                    Start Your {trialDays}-Day Free Trial
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link
                    href={other === 'erp' ? '/erp' : '/apps'}
                    className={button.outlineDark}
                >
                    Explore {categoryLabels[other]}
                </Link>
            </CtaBand>
        </>
    );
}
