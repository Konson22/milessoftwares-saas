import { Link } from '@inertiajs/react';
import { ArrowRight, Check } from 'lucide-react';
import {
    CtaBand,
    StepBand,
    cardSurface,
} from '@/sites/public/components/blocks';
import { quoteUrl, services } from '@/sites/public/components/offerings';
import { Accent, DarkHero } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization } from '@/sites/public/components/seo';
import { socialLinks } from '@/sites/public/components/social';
import { button, display } from '@/sites/public/components/styles';

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

/** Changing a Tatook product versus building something new. */
const choices = [
    {
        title: 'Customize a Tatook product',
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
 * Tatook product. The ready-made products are in the catalog.
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

            <DarkHero
                title={
                    <>
                        Custom software{' '}
                        <Accent>built around your business</Accent>
                    </>
                }
                intro="We adapt a Tatook product or build a new system around how you work."
                asideClassName=""
                aside={
                    <nav aria-label="Our services">
                        <ul className="grid grid-cols-2 gap-3">
                            {services.map(({ id, icon: Icon, title }) => (
                                <li key={id}>
                                    <a
                                        href={`#${id}`}
                                        className="group flex h-full flex-col gap-3 rounded-2xl bg-white/[0.07] p-4 ring-1 ring-white/10 backdrop-blur-sm transition-[background-color,transform] hover:-translate-y-0.5 hover:bg-white/[0.12] focus-visible:outline-2 focus-visible:outline-site-highlight sm:flex-row sm:items-center"
                                    >
                                        <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-highlight text-site-night">
                                            <Icon
                                                className="size-5"
                                                aria-hidden
                                            />
                                        </span>
                                        <span className="text-sm leading-snug font-semibold text-white group-hover:text-site-highlight">
                                            {title}
                                        </span>
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>
                }
            >
                <Link
                    href={quoteUrl('Project: Custom software')}
                    className={button.light}
                >
                    Discuss your project
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link
                    href={quoteUrl('Quote: Custom software')}
                    className={button.outlineDark}
                >
                    Request a quotation
                </Link>
            </DarkHero>

            {services.map((service, i) => {
                const Icon = service.icon;
                const flip = i % 2 === 1;

                return (
                    <section
                        key={service.id}
                        id={service.id}
                        aria-labelledby={`${service.id}-heading`}
                        className={`scroll-mt-24 ${flip ? 'bg-site-wash' : 'bg-white'}`}
                    >
                        <div className="mx-auto grid max-w-7xl items-center gap-10 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-[1.15fr_1fr] lg:gap-16 lg:px-8">
                            <div className={flip ? 'lg:order-2' : ''}>
                                <span className="inline-flex size-14 items-center justify-center rounded-2xl bg-linear-160 from-[#0a5a26] to-[#032a16] text-site-highlight shadow-lg shadow-site-night/20">
                                    <Icon className="size-7" aria-hidden />
                                </span>
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
                                    <span className="sr-only">
                                        : {service.title}
                                    </span>
                                    <ArrowRight
                                        className="size-4"
                                        aria-hidden
                                    />
                                </Link>
                            </div>
                            <div className="site-dark relative overflow-hidden rounded-3xl bg-linear-160 from-[#0a5a26] via-[#063519] to-[#032a16] p-7 shadow-xl shadow-site-night/20 sm:p-9">
                                <span
                                    aria-hidden
                                    className="absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.12)_1px,transparent_1.5px)] [background-size:16px_16px] [mask-image:linear-gradient(to_bottom_left,black,transparent_60%)]"
                                />
                                <Icon
                                    aria-hidden
                                    strokeWidth={1.25}
                                    className="absolute -right-6 -bottom-8 size-44 text-white/[0.06]"
                                />
                                <ul className="relative space-y-4">
                                    {service.points.map((point) => (
                                        <li
                                            key={point}
                                            className="flex items-start gap-3 font-semibold text-white"
                                        >
                                            <span className="mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-full bg-site-highlight text-site-night">
                                                <Check
                                                    className="size-3.5"
                                                    strokeWidth={3}
                                                    aria-hidden
                                                />
                                            </span>
                                            {point}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        </div>
                    </section>
                );
            })}

            <section
                id="customize-or-build"
                aria-labelledby="choice-heading"
                className="scroll-mt-24 border-t border-site-line bg-white"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
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
                    <div className="mt-12 grid gap-6 md:grid-cols-2">
                        {choices.map((option, i) => (
                            <div
                                key={option.title}
                                className={`${cardSurface} flex flex-col overflow-hidden`}
                            >
                                <div
                                    className={`site-dark relative px-7 pt-8 pb-7 sm:px-9 ${i === 0 ? 'bg-linear-160 from-[#0a5a26] via-[#063519] to-[#032a16]' : 'bg-linear-160 from-[#0e7a3a] via-[#0a5a26] to-[#063519]'}`}
                                >
                                    <span
                                        aria-hidden
                                        className="font-public-display text-5xl font-bold text-white/15"
                                    >
                                        {String(i + 1).padStart(2, '0')}
                                    </span>
                                    <h3 className={`mt-2 text-2xl ${display}`}>
                                        {option.title}
                                    </h3>
                                    <span
                                        aria-hidden
                                        className="absolute bottom-0 left-7 h-1 w-12 rounded-t-full bg-site-highlight sm:left-9"
                                    />
                                </div>
                                <div className="flex flex-1 flex-col p-7 sm:p-9">
                                    <p className="leading-relaxed text-slate-600">
                                        {option.body}
                                    </p>
                                    <ul className="mt-5 flex-1 space-y-3 text-sm font-medium text-slate-700">
                                        {option.points.map((point) => (
                                            <li
                                                key={point}
                                                className="flex items-start gap-2.5"
                                            >
                                                <span className="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full bg-site-accent-ink text-white">
                                                    <Check
                                                        className="size-3"
                                                        strokeWidth={3}
                                                        aria-hidden
                                                    />
                                                </span>
                                                {point}
                                            </li>
                                        ))}
                                    </ul>
                                    <Link
                                        href={option.href}
                                        className={`mt-8 self-start ${i === 0 ? button.outline : button.primary}`}
                                    >
                                        {option.cta}
                                        <ArrowRight
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Link>
                                </div>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <StepBand
                id="process"
                title={
                    <>
                        How we <Accent>work with you</Accent>
                    </>
                }
                lead="The same four steps for an application, an integration or a custom ERP."
                steps={steps}
            />

            <div className="bg-white pt-16 sm:pt-24">
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
