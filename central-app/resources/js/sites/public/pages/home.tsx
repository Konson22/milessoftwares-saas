import { Link } from "@inertiajs/react";
import { ArrowRight, Code2, LayoutGrid, Rocket } from "lucide-react";
import type { LucideIcon } from "lucide-react";
import {
    CtaBand,
    Eyebrow,
    FeatureGrid,
} from "@/sites/public/components/blocks";
import { ProductCard, catalogIcon } from "@/sites/public/components/catalog";
import type { CatalogEntry } from "@/sites/public/components/catalog";
import {
    cloudPillars,
    howItWorks,
    milesProducts,
    whyMiles,
} from "@/sites/public/components/content";
import FaqList, { faq } from "@/sites/public/components/faq";
import {
    DeploymentOptions,
    ServiceCards,
    quoteUrl,
} from "@/sites/public/components/offerings";
import {
    Accent,
    IconBox,
    LightBackdrop,
} from "@/sites/public/components/page-hero";
import type { Product } from "@/sites/public/components/product";
import SectionHeading from "@/sites/public/components/section-heading";
import Seo, { organization, siteName } from "@/sites/public/components/seo";
import {
    button,
    card,
    display,
    iconChip,
    panel,
    textLink,
} from "@/sites/public/components/styles";

type Props = {
    catalog: CatalogEntry[];
    /** Registry applications (sent by the server; the hero no longer draws them). */
    products: Product[];
    trialDays: number;
    canonical: string;
};

/** The cards just under the hero: subscriptions, custom work, the trial. */
function highlights(trialDays: number): {
    icon: LucideIcon;
    title: string;
    body: string;
    cta: string;
    href: string;
}[] {
    return [
        {
            icon: LayoutGrid,
            title: "Subscribe only to what you need",
            body: "Pick the applications your business uses and add the next one as you grow.",
            cta: "See plans",
            href: "/pricing",
        },
        {
            icon: Code2,
            title: "Custom software",
            body: "Web, desktop and mobile systems built around how you work, quoted as a project.",
            cta: "Custom solutions",
            href: "/services",
        },
        {
            icon: Rocket,
            title: `${trialDays}-day free trial`,
            body: "Start with the application you need first. No payment details.",
            cta: "Get started",
            href: "/start",
        },
    ];
}

/** Two digits, as numbered steps read: 01, 02, … */
function ordinal(i: number): string {
    return String(i + 1).padStart(2, "0");
}

export default function MarketingHome({
    catalog,
    trialDays,
    canonical,
}: Props) {
    const origin = new URL(canonical).origin;
    const portfolio = milesProducts.flatMap((item) => {
        const entry = catalog.find(
            (candidate) =>
                candidate.category === item.category &&
                candidate.slug === item.slug,
        );

        return entry ? [{ title: item.title, entry }] : [];
    });

    return (
        <>
            <Seo
                title="Business software that works together"
                description="Miles Global Softwares builds business applications on Miles Cloud and tailored software, helping organizations manage operations, connect teams and grow with confidence."
                canonical={canonical}
                jsonLd={{
                    "@context": "https://schema.org",
                    "@graph": [
                        organization(origin),
                        {
                            "@type": "WebSite",
                            "@id": `${origin}/#website`,
                            name: siteName,
                            url: `${origin}/`,
                            publisher: { "@id": `${origin}/#organization` },
                        },
                    ],
                }}
            />

            {/* 1. Hero: light, under the white header; the products as tiles, no pictures. */}
            <section className="relative isolate overflow-hidden bg-linear-to-b from-site-soft via-white to-white">
                <LightBackdrop />
                <div className="site-rise mx-auto flex max-w-7xl flex-col items-center px-4 pt-32 pb-16 text-center sm:px-6 sm:pt-44 sm:pb-20">
                    <p className="mb-6 inline-flex items-center gap-2 rounded-full border border-site-line bg-white px-4 py-1.5 text-sm font-semibold text-site-ink">
                        <span
                            aria-hidden
                            className="size-2 rounded-full bg-site-accent"
                        />
                        Miles Products on Miles Cloud
                    </p>
                    <h1
                        className={`max-w-4xl text-[2rem] leading-[1.1] min-[400px]:text-[2.375rem] sm:text-6xl xl:text-7xl ${display}`}
                    >
                        Business software{" "}
                        <Accent>that works together.</Accent>
                    </h1>
                    <p className="mt-6 max-w-2xl text-base text-pretty text-slate-600 sm:text-lg">
                        Miles Global Softwares builds powerful business
                        applications and tailored software solutions to help
                        organizations manage operations, connect teams and grow
                        with confidence.
                    </p>
                    <div className="mt-9 flex flex-wrap items-center justify-center gap-3">
                        <a href="#products" className={button.primary}>
                            Explore Products
                            <ArrowRight className="size-4" aria-hidden />
                        </a>
                        <Link href="/platform" className={button.outline}>
                            Discover Miles Cloud
                        </Link>
                    </div>

                    {/* The six products on one line, joined like the apps they are. */}
                    <div className="relative mt-16 w-full max-w-5xl sm:mt-20">
                        <div
                            aria-hidden
                            className="absolute inset-x-[8%] top-1/2 hidden h-px bg-linear-to-r from-transparent via-site-accent/40 to-transparent lg:block"
                        />
                        <ul className="relative grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-6">
                            {milesProducts.map((product) => {
                                const Icon = catalogIcon(product.icon);

                                return (
                                    <li key={product.slug}>
                                        <Link
                                            href={`/${product.category === "erp" ? "erp" : "apps"}/${product.slug}`}
                                            className={`${card} group flex h-full flex-col items-center gap-3 px-3 py-5`}
                                        >
                                            <span
                                                className={`${iconChip} size-11 transition-transform group-hover:scale-110`}
                                            >
                                                <Icon
                                                    className="size-5"
                                                    aria-hidden
                                                />
                                            </span>
                                            <span className="text-sm font-semibold text-site-ink transition-colors group-hover:text-site-accent-ink">
                                                {product.title}
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>
            </section>

            {/* Three ways in, just under the hero. */}
            <div className="mx-auto max-w-7xl px-4 pt-10 sm:px-6 sm:pt-14">
                <ul className="grid gap-4 md:grid-cols-3">
                    {highlights(trialDays).map((item, i) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                className={`${card} group flex h-full items-start gap-5 p-6`}
                            >
                                <IconBox icon={item.icon} index={i} />
                                <span className="flex-1">
                                    <span
                                        className={`block text-lg transition-colors group-hover:text-site-accent-ink ${display}`}
                                    >
                                        {item.title}
                                    </span>
                                    <span className="mt-1 block text-sm leading-relaxed text-slate-600">
                                        {item.body}
                                    </span>
                                    <span className="mt-3 inline-flex items-center gap-1.5 text-sm font-bold text-site-accent-ink">
                                        {item.cta}
                                        <ArrowRight
                                            className="size-4 transition-transform group-hover:translate-x-1"
                                            aria-hidden
                                        />
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>

            {/* 2. Miles Products */}
            <section
                id="products"
                aria-labelledby="products-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <div>
                        <Eyebrow>Miles Products</Eyebrow>
                        <SectionHeading
                            id="products-heading"
                            title={
                                <>
                                    Applications for each part of{" "}
                                    <Accent>your business.</Accent>
                                </>
                            }
                            lead="Use one on its own or several together in the same workspace. Each product shows where it stands today: available, in early access or coming soon."
                            align="left"
                        />
                    </div>
                    <div className="flex flex-wrap gap-3">
                        <Link href="/erp" className={button.outline}>
                            ERP Solutions
                        </Link>
                        <Link href="/apps" className={button.outline}>
                            Business Applications
                        </Link>
                    </div>
                </div>
                <ul className="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {portfolio.map(({ title, entry }) => (
                        <li key={entry.url}>
                            <ProductCard
                                product={entry}
                                title={title}
                                trialDays={trialDays}
                                highlights
                            />
                        </li>
                    ))}
                </ul>
            </section>

            {/* 3. Miles Cloud */}
            <section
                id="cloud"
                aria-labelledby="cloud-heading"
                className="scroll-mt-24 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <div className="flex flex-wrap items-end justify-between gap-6">
                        <div>
                            <Eyebrow>Miles Cloud</Eyebrow>
                            <SectionHeading
                                id="cloud-heading"
                                title={
                                    <>
                                        Your business applications.{" "}
                                        <Accent>
                                            One connected workspace.
                                        </Accent>
                                    </>
                                }
                                lead="Miles Cloud is the platform every Miles product runs on: one account, one organization and one set of permissions, whichever applications you use."
                                align="left"
                            />
                        </div>
                        <Link href="/platform" className={button.outline}>
                            Discover Miles Cloud
                        </Link>
                    </div>
                    <div className="mt-10">
                        <FeatureGrid features={cloudPillars} columns={3} />
                    </div>
                </div>
            </section>

            {/* 4. Custom software development: built for you, not a subscription */}
            <section
                id="custom"
                aria-labelledby="custom-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <Eyebrow>Custom Software Development</Eyebrow>
                <SectionHeading
                    id="custom-heading"
                    title={
                        <>
                            Your business is unique.{" "}
                            <Accent>Your software can be too.</Accent>
                        </>
                    }
                    lead="Not every organization fits into ready-made software. Separately from our subscription products, we develop systems designed around your workflows, operational requirements and goals: scoped with you, quoted, and delivered as a project."
                    align="left"
                />
                <div className="mt-10 grid gap-5 md:grid-cols-2">
                    <div className={`${panel} p-7`}>
                        <h3 className="text-lg font-semibold">
                            Customize a Miles product
                        </h3>
                        <p className="mt-2 text-sm leading-relaxed text-slate-600">
                            Start from one of our ERPs and have it adapted:
                            extra fields, documents, reports and approval steps.
                            You pay the product licence plus a setup or
                            customization charge.
                        </p>
                    </div>
                    <div className={`${panel} p-7`}>
                        <h3 className="text-lg font-semibold">
                            Commission a new system
                        </h3>
                        <p className="mt-2 text-sm leading-relaxed text-slate-600">
                            A system built for you from your requirements: an
                            ERP, a web, desktop or mobile application, or an
                            integration. Priced by quotation after a
                            requirements assessment.
                        </p>
                    </div>
                </div>
                <div className="mt-5">
                    <ServiceCards />
                </div>
                <div className="mt-10 flex flex-wrap justify-center gap-3">
                    <Link
                        href={quoteUrl("Project: Custom software")}
                        className={button.primary}
                    >
                        Discuss Your Project
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link
                        href={quoteUrl("Quote: Custom software")}
                        className={button.outline}
                    >
                        Request a Quotation
                    </Link>
                </div>
            </section>

            {/* 5. Flexible software deployment */}
            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="scroll-mt-24 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <Eyebrow>Flexible Software Deployment</Eyebrow>
                    <SectionHeading
                        id="ways-heading"
                        title={
                            <>
                                Online, offline or connected.{" "}
                                <Accent>You choose how to work.</Accent>
                            </>
                        }
                        lead="Not every product offers every option. Each one below names the products that have it today."
                        align="left"
                    />
                    <div className="mt-10">
                        <DeploymentOptions
                            catalog={catalog}
                            trialDays={trialDays}
                        />
                    </div>
                </div>
            </section>

            {/* 6. Why Miles Global Softwares */}
            <section
                aria-labelledby="why-heading"
                className="relative isolate overflow-hidden"
            >
                <LightBackdrop />
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="why-heading"
                        title={
                            <>
                                Why <Accent>Miles Global Softwares</Accent>
                            </>
                        }
                    />
                    <div className="mt-10 sm:mt-14">
                        <FeatureGrid features={whyMiles} columns={4} />
                    </div>
                </div>
            </section>

            {/* 7. How we work */}
            <section
                id="how-it-works"
                aria-labelledby="how-heading"
                className="scroll-mt-24 border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="how-heading"
                        title={
                            <>
                                How we <Accent>work</Accent>
                            </>
                        }
                    />
                    <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {howItWorks.map((step, i) => (
                            <li
                                key={step.title}
                                className={`${panel} p-6 sm:p-7`}
                            >
                                <span className="site-gradient inline-flex size-11 items-center justify-center rounded-full font-public-display text-sm font-bold text-white">
                                    {ordinal(i)}
                                </span>
                                <h3 className="mt-5 text-lg font-semibold">
                                    {step.title}
                                </h3>
                                <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                    {step.body}
                                </p>
                            </li>
                        ))}
                    </ol>
                </div>
            </section>

            {/* FAQ */}
            <section aria-labelledby="faq-heading">
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1fr_1.5fr] lg:gap-16">
                    <div className="lg:sticky lg:top-32 lg:self-start">
                        <SectionHeading
                            id="faq-heading"
                            title={
                                <>
                                    Frequently asked <Accent>questions</Accent>
                                </>
                            }
                            align="left"
                        />
                        <p className="mt-6 max-w-sm text-slate-600">
                            Something else on your mind?{" "}
                            <Link href="/contact" className={textLink}>
                                Ask us
                            </Link>{" "}
                            and we reply within one working day.
                        </p>
                    </div>
                    <FaqList items={faq(trialDays)} />
                </div>
            </section>

            {/* 8. Final call to action */}
            <CtaBand
                id="cta-heading"
                title={
                    <>
                        Let's build the right solution{" "}
                        <Accent>for your business.</Accent>
                    </>
                }
                body="Whether you need ready-made business software or a completely customized application, we can help you find the right solution."
            >
                <a href="#products" className={button.light}>
                    Explore Products
                    <ArrowRight className="size-4" aria-hidden />
                </a>
                <Link href="/start" className={button.outlineDark}>
                    Get Started
                </Link>
                <Link
                    href={quoteUrl("Project: Custom software")}
                    className={button.outlineDark}
                >
                    Discuss a Custom Project
                </Link>
            </CtaBand>
        </>
    );
}
