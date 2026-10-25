import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { CtaBand } from '@/sites/public/components/blocks';
import { solutions } from '@/sites/public/components/content';
import { CatalogStatus } from '@/sites/public/components/catalog';
import type { CatalogEntry } from '@/sites/public/components/catalog';
import PageHero, { Accent, IconBox } from '@/sites/public/components/page-hero';
import Seo from '@/sites/public/components/seo';
import { button, panel } from '@/sites/public/components/styles';

type Props = {
    catalog: CatalogEntry[];
    canonical: string;
};

/**
 * Industries, problem first: what goes wrong today, what changes with
 * Miles, and the ERP that does it. A solution whose product is not on the
 * site (retired) still reads, without the link.
 */
export default function Solutions({ catalog, canonical }: Props) {
    const byUrl = new Map(catalog.map((entry) => [entry.url, entry]));

    return (
        <>
            <Seo
                title="Industries"
                description="Miles for pharmacies, wholesalers, shops, traders, restaurants, multi-branch businesses, accountants, schools, NGOs and logistics: the problem and the solution."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Built for the way <Accent>your business</Accent> runs
                    </>
                }
                intro="Find your kind of business below: the problems we hear most, and what changes with Miles."
            >
                <nav
                    aria-label="Industries"
                    className="mt-9 flex flex-wrap justify-center gap-2.5"
                >
                    {solutions.map((solution) => (
                        <a
                            key={solution.id}
                            href={`#${solution.id}`}
                            className="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/25 transition-colors hover:bg-white/20"
                        >
                            {solution.title}
                        </a>
                    ))}
                </nav>
            </PageHero>

            <div className="mx-auto max-w-7xl space-y-6 px-4 py-16 sm:space-y-8 sm:px-6 sm:py-24">
                {solutions.map((solution, i) => {
                    const product =
                        solution.product === null
                            ? undefined
                            : byUrl.get(solution.product);

                    return (
                        <section
                            key={solution.id}
                            id={solution.id}
                            aria-labelledby={`${solution.id}-heading`}
                            className={`${panel} grid scroll-mt-28 gap-8 p-6 sm:p-10 lg:grid-cols-[1fr_1.2fr] lg:gap-14`}
                        >
                            <div>
                                <div className="flex items-center gap-4">
                                    <IconBox icon={solution.icon} index={i} />
                                    <h2
                                        id={`${solution.id}-heading`}
                                        className="text-2xl font-semibold tracking-tight sm:text-3xl"
                                    >
                                        {solution.title}
                                    </h2>
                                </div>
                                <p className="mt-6 text-sm font-bold tracking-wider text-slate-500 uppercase">
                                    The problem
                                </p>
                                <p className="mt-2 text-lg text-slate-700">
                                    {solution.pain}
                                </p>
                            </div>
                            <div className="rounded-2xl bg-site-soft p-6 sm:p-8">
                                <p className="text-sm font-bold tracking-wider text-site-accent-ink uppercase">
                                    With Miles
                                </p>
                                <p className="mt-2 text-lg font-semibold">
                                    {solution.change}
                                </p>
                                <ul className="mt-5 grid gap-2.5 sm:grid-cols-2">
                                    {solution.proof.map((point) => (
                                        <li
                                            key={point}
                                            className="flex items-center gap-2 text-sm font-semibold text-slate-700"
                                        >
                                            <Check
                                                className="size-4 shrink-0 text-site-accent"
                                                aria-hidden
                                            />
                                            {point}
                                        </li>
                                    ))}
                                </ul>
                                {product && (
                                    <div className="mt-7 flex flex-wrap items-center gap-3">
                                        <Link
                                            href={`/${product.url}`}
                                            className={`${button.primary} py-3`}
                                        >
                                            See {product.name}
                                            <ArrowRight
                                                className="size-4"
                                                aria-hidden
                                            />
                                        </Link>
                                        <CatalogStatus
                                            status={product.status}
                                        />
                                    </div>
                                )}
                                {solution.product === null && (
                                    <Link
                                        href="/platform"
                                        className={`mt-7 ${button.primary} py-3`}
                                    >
                                        See the platform
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Link>
                                )}
                            </div>
                        </section>
                    );
                })}
            </div>

            <CtaBand
                id="solutions-cta-heading"
                title={
                    <>
                        Don't see <Accent>your industry?</Accent>
                    </>
                }
                body="Clinics and more are on the way. Tell us what you run: we will tell you where our products fit, or build what you need."
            >
                <Link href="/coming-soon" className={button.light}>
                    See what's coming
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link href="/services" className={button.outlineDark}>
                    Discuss a Custom Solution
                </Link>
            </CtaBand>
        </>
    );
}
