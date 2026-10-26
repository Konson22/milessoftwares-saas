import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import { CtaBand } from '@/sites/public/components/blocks';
import { quoteUrl, services } from '@/sites/public/components/offerings';
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
        title: 'Requirements',
        body: 'Tell us what you need, by the contact form or WhatsApp. We assess your requirements first.',
    },
    {
        title: 'Quotation and plan',
        body: 'You get a written scope, timeline and quotation before any work starts.',
    },
    {
        title: 'Build and review',
        body: 'We build in short steps and show you each one, so there are no surprises.',
    },
    {
        title: 'Launch and support',
        body: 'We deploy it, train your team and provide the maintenance you agree with us.',
    },
];

/** Changing a Miles product versus building something new. */
const choices = [
    {
        title: 'Customize a Miles product',
        body: 'You run one of our ready-made ERPs, adapted to you: fields, documents, reports, print layouts and approval steps.',
        points: [
            'Product licence: subscription, or one-time where offered',
            'Plus a setup or customization charge',
            'The product keeps receiving its updates',
        ],
        href: quoteUrl('Quote: ERP customization'),
        cta: 'Request a quotation',
    },
    {
        title: 'Commission a new system',
        body: 'We build software that does not exist yet, for your requirements: an ERP, an application or an integration.',
        points: [
            'Requirements assessment first',
            'Project quotation with scope and timeline',
            'Optional maintenance agreement',
        ],
        href: quoteUrl('Project: Custom software'),
        cta: 'Discuss your project',
    },
];

/**
 * Custom development: new systems built for one customer, and changes to a
 * Miles product. The ready-made products are in the catalog.
 */
export default function Services({ canonical }: Props) {
    const origin = new URL(canonical).origin;
    const whatsapp = socialLinks.find((link) => link.name === 'WhatsApp');

    return (
        <>
            <Seo
                title="Custom ERP, web, desktop and mobile app development"
                description="Custom software development: ERP systems, web, desktop and mobile applications, system integration and ERP customization. Request a quotation."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@graph': services.map((service) => ({
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
                        Custom software{' '}
                        <Accent>built around your business</Accent>
                    </>
                }
                intro="We adapt a Miles product or build a new system around how you work."
            />

            {services.map((service, i) => (
                <section
                    key={service.id}
                    id={service.id}
                    aria-labelledby={`${service.id}-heading`}
                    className={`scroll-mt-24 ${i % 2 === 1 ? 'border-y border-site-line bg-white' : ''}`}
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
                                    <span className="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-site-accent-ink text-white">
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
                id="customize-or-build"
                aria-labelledby="choice-heading"
                className="mx-auto max-w-7xl scroll-mt-24 px-4 py-16 sm:px-6 sm:py-24"
            >
                <SectionHeading
                    id="choice-heading"
                    title={
                        <>
                            Customize a product, or{' '}
                            <Accent>commission a new system</Accent>
                        </>
                    }
                    lead="Two different kinds of work, priced differently."
                    align="left"
                />
                <div className="mt-10 grid gap-5 md:grid-cols-2">
                    {choices.map((option) => (
                        <div
                            key={option.title}
                            className={`${panel} flex flex-col p-7 sm:p-9`}
                        >
                            <h3 className={`text-2xl ${display}`}>
                                {option.title}
                            </h3>
                            <p className="mt-3 leading-relaxed text-slate-600">
                                {option.body}
                            </p>
                            <ul className="mt-5 flex-1 space-y-2.5 text-sm text-slate-700">
                                {option.points.map((point) => (
                                    <li
                                        key={point}
                                        className="flex items-start gap-2.5"
                                    >
                                        <Check
                                            className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                                            aria-hidden
                                        />
                                        {point}
                                    </li>
                                ))}
                            </ul>
                            <Link
                                href={option.href}
                                className={`mt-7 self-start ${button.outline}`}
                            >
                                {option.cta}
                                <ArrowRight className="size-4" aria-hidden />
                            </Link>
                        </div>
                    ))}
                </div>
            </section>

            <section
                aria-labelledby="process-heading"
                className="border-y border-site-line bg-white"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="process-heading"
                        title={
                            <>
                                How we <Accent>work with you</Accent>
                            </>
                        }
                        lead="The same four steps for an application, an integration or a custom ERP."
                    />
                    <ol className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {steps.map((step, i) => (
                            <li
                                key={step.title}
                                className={`${panel} p-6 sm:p-7`}
                            >
                                <span className="inline-flex size-11 items-center justify-center rounded-full bg-site-accent-ink font-public-display text-sm font-bold text-white">
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
                    body="Tell us what you need and we reply within one working day with the next steps towards your quotation."
                >
                    <Link
                        href={quoteUrl('Project: Custom software')}
                        className={button.light}
                    >
                        Discuss Your Project
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link
                        href={quoteUrl('Quote: Custom software')}
                        className={button.outlineDark}
                    >
                        Request a Quotation
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
