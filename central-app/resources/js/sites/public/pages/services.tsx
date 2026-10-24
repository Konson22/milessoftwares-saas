import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { CtaBand, Eyebrow } from '@/sites/public/components/blocks';
import {
    DeploymentOptions,
    quoteUrl,
    services,
} from '@/sites/public/components/offerings';
import PageHero, { Accent, IconBox } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization } from '@/sites/public/components/seo';
import { socialLinks } from '@/sites/public/components/social';
import { button, display, panel } from '@/sites/public/components/styles';

type Props = {
    trialDays: number;
    canonical: string;
};

const steps = [
    {
        title: 'Talk to us',
        body: 'Tell us what you need, by the contact form or WhatsApp. We listen first.',
    },
    {
        title: 'Quote and plan',
        body: 'You get a clear scope, timeline and price before any work starts.',
    },
    {
        title: 'Build and review',
        body: 'We build in short steps and show you each one, so there are no surprises.',
    },
    {
        title: 'Launch and support',
        body: 'We set it up, train your team and stay with you after launch.',
    },
];

/** What we make, from ready-made ERPs to websites and apps built for you. */
export default function Services({ trialDays, canonical }: Props) {
    const origin = new URL(canonical).origin;
    const whatsapp = socialLinks.find((link) => link.name === 'WhatsApp');

    return (
        <>
            <Seo
                title="ERP, website, desktop and mobile app development"
                description="Ready-made and custom ERP, websites, and offline-ready desktop and mobile apps, built for businesses, schools and NGOs. Request a free quote."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@graph': services
                        .filter((service) => service.id !== 'ready-made-erp')
                        .map((service) => ({
                            '@type': 'Service',
                            name: service.title,
                            description: service.body,
                            url: `${canonical}#${service.id}`,
                            provider: organization(origin),
                        })),
                }}
            />

            <PageHero
                title={
                    <>
                        Software built <Accent>for your business</Accent>
                    </>
                }
                intro="Ready-made and custom ERP, websites, desktop apps and mobile apps, from one team that builds and supports them."
            >
                <nav
                    aria-label="On this page"
                    className="mt-9 flex flex-wrap justify-center gap-2.5"
                >
                    {services.map((service) => (
                        <a
                            key={service.id}
                            href={`#${service.id}`}
                            className="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/25 transition-colors hover:bg-white/20"
                        >
                            {service.title}
                        </a>
                    ))}
                </nav>
            </PageHero>

            {services.map((service, i) => (
                <section
                    key={service.id}
                    id={service.id}
                    aria-labelledby={`${service.id}-heading`}
                    className={`scroll-mt-24 ${i % 2 === 1 ? 'border-y border-site-line bg-site-soft' : ''}`}
                >
                    <div className="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-[1.2fr_1fr] lg:gap-16">
                        <div>
                            <IconBox icon={service.icon} index={i} />
                            <h2
                                id={`${service.id}-heading`}
                                className={`mt-6 text-3xl sm:text-4xl ${display}`}
                            >
                                {service.title}
                            </h2>
                            <p className="mt-4 max-w-xl text-lg leading-relaxed text-slate-600">
                                {service.body}
                            </p>
                            <Link
                                href={service.href}
                                className={`mt-8 ${button.primary}`}
                            >
                                {service.cta}
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        </div>
                        <ul className={`${panel} space-y-4 p-7 sm:p-9`}>
                            {service.points.map((point) => (
                                <li
                                    key={point}
                                    className="flex items-start gap-3 font-semibold text-site-ink"
                                >
                                    <span className="site-gradient mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full text-white">
                                        <Check
                                            className="size-3.5"
                                            aria-hidden
                                        />
                                    </span>
                                    {point}
                                </li>
                            ))}
                        </ul>
                    </div>
                </section>
            ))}

            <section
                id="ways-to-run"
                aria-labelledby="ways-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <Eyebrow>Every ERP</Eyebrow>
                <SectionHeading
                    id="ways-heading"
                    title={
                        <>
                            Online, synced or <Accent>fully offline</Accent>
                        </>
                    }
                    lead="Every ERP we make, ready-made or custom, runs in the cloud, as desktop and mobile apps that sync, or offline for a single branch."
                    align="left"
                />
                <div className="mt-10">
                    <DeploymentOptions trialDays={trialDays} />
                </div>
            </section>

            <section
                aria-labelledby="process-heading"
                className="border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="process-heading"
                        title={
                            <>
                                How we <Accent>work with you</Accent>
                            </>
                        }
                        lead="The same four steps for a website, an app or a custom ERP."
                    />
                    <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {steps.map((step, i) => (
                            <li
                                key={step.title}
                                className={`${panel} p-6 sm:p-7`}
                            >
                                <span className="site-gradient inline-flex size-11 items-center justify-center rounded-full font-public-display text-sm font-bold text-white">
                                    {String(i + 1).padStart(2, '0')}
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

            <div className="pt-16 sm:pt-24">
                <CtaBand
                    id="services-cta-heading"
                    title={
                        <>
                            Have a project in mind? <Accent>Let's talk.</Accent>
                        </>
                    }
                    body="Tell us what you need and we reply within one working day with next steps and a quote."
                >
                    <Link
                        href={quoteUrl('Quote: New project')}
                        className={button.light}
                    >
                        Request a quote
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    {whatsapp && (
                        <a
                            href={whatsapp.href}
                            target="_blank"
                            rel="noopener noreferrer"
                            className={button.outlineDark}
                        >
                            Chat on WhatsApp
                        </a>
                    )}
                </CtaBand>
            </div>
        </>
    );
}
