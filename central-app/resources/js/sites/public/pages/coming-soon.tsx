import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { CtaBand, Eyebrow } from '@/sites/public/components/blocks';
import { ProductCard } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

type Props = {
    /** Catalog products not released yet. */
    catalog: CatalogEntry[];
    trialDays: number;
    canonical: string;
};

/**
 * Everything announced and not yet sold, straight from the catalog: a
 * product moves off this page as soon as the registry releases it.
 */
export default function ComingSoon({ catalog, trialDays, canonical }: Props) {
    const apps = catalog.filter((entry) => entry.category === 'application');
    const erp = catalog.filter((entry) => entry.category === 'erp');

    return (
        <>
            <Seo
                title="Coming soon"
                description="New Miles business applications and industry ERP solutions on the way. Join the waitlist and we will tell you first."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        More of your business,{' '}
                        <Accent>on the same books</Accent>
                    </>
                }
                intro="New Miles products on the way. Join the waitlist and we will tell you first."
            />

            <Group
                id="apps"
                eyebrow="Business Applications"
                title="Applications on the way"
                entries={apps}
                trialDays={trialDays}
            />
            <Group
                id="industries"
                eyebrow="ERP Solutions"
                title="ERPs for more industries"
                entries={erp}
                trialDays={trialDays}
                shaded
            />

            <div className="pt-16 sm:pt-24">
                <CtaBand
                    id="soon-cta-heading"
                    title={
                        <>
                            Need it <Accent>sooner?</Accent>
                        </>
                    }
                    body="Tell us what you run and what you need. What customers ask for is what we build next."
                >
                    <Link href="/contact" className={button.light}>
                        Talk to us
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link href="/products#apps" className={button.outlineDark}>
                        See what's available
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}

function Group({
    id,
    eyebrow,
    title,
    entries,
    trialDays,
    shaded = false,
}: {
    id: string;
    eyebrow: string;
    title: ReactNode;
    entries: CatalogEntry[];
    trialDays: number;
    shaded?: boolean;
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <section
            id={id}
            aria-labelledby={`${id}-heading`}
            className={shaded ? 'border-y border-site-line bg-white' : ''}
        >
            <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                <Eyebrow>{eyebrow}</Eyebrow>
                <SectionHeading
                    id={`${id}-heading`}
                    title={title}
                    align="left"
                />
                <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
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
}
