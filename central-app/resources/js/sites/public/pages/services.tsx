import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    FileText,
    Layers,
    LifeBuoy,
    MonitorSmartphone,
} from 'lucide-react';
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

/** What every custom project comes with, beside the hero. */
const heroFacts = [
    {
        icon: FileText,
        title: 'Quoted up front',
        body: 'A written scope, timeline and price first.',
    },
    {
        icon: MonitorSmartphone,
        title: 'Any screen',
        body: 'Web, Windows, Android and iOS.',
    },
    {
        icon: Layers,
        title: 'Proven modules',
        body: 'Built on the platform our own ERPs run on.',
    },
    {
        icon: LifeBuoy,
        title: 'Supported after launch',
        body: 'Training, and maintenance as agreed.',
    },
];

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
                    <ul className="grid gap-4 rounded-xl bg-white/[0.06] p-5 ring-1 ring-white/10 backdrop-blur-sm sm:grid-cols-2 sm:p-6">
                        {heroFacts.map(({ icon: Icon, title, body }) => (
                            <li key={title} className="flex items-start gap-3">
                                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-highlight text-site-night">
                                    <Icon className="size-5" aria-hidden />
                                </span>
                                <span>
                                    <span className="block text-sm font-semibold text-white">
                                        {title}
                                    </span>
                                    <span className="mt-0.5 block text-sm leading-snug text-slate-300">
                                        {body}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
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

            <section
                id="what-we-build"
                aria-labelledby="build-heading"
                className="scroll-mt-24 bg-site-wash"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
                    <SectionHeading
                        id="build-heading"
                        title={
                            <>
                                What we <Accent>build for you</Accent>
                            </>
                        }
                        lead="Six kinds of work, each scoped and quoted on its own or together as one project."
                        align="left"
                    />
                    <ul className="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-3">
                        {services.map((service, i) => {
                            const Icon = service.icon;

                            return (
                                <li
                                    key={service.id}
                                    id={service.id}
                                    className="group flex scroll-mt-28 flex-col rounded-xl bg-white p-6 ring-1 ring-site-line transition-[box-shadow,transform] duration-300 target:ring-2 target:ring-site-accent hover:-translate-y-1 hover:shadow-xl hover:shadow-site-night/5 sm:p-7"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="inline-flex size-12 items-center justify-center rounded-2xl bg-linear-160 from-[#0a5a26] to-[#032a16] text-site-highlight">
                                            <Icon
                                                className="size-6"
                                                aria-hidden
                                            />
                                        </span>
                                        <span
                                            aria-hidden
                                            className="font-public-display text-3xl font-semibold text-site-accent/20 tabular-nums"
                                        >
                                            {String(i + 1).padStart(2, '0')}
                                        </span>
                                    </div>
                                    <h2 className={`mt-6 text-xl ${display}`}>
                                        {service.title}
                                    </h2>
                                    <p className="mt-2 text-sm leading-relaxed text-slate-600">
                                        {service.body}
                                    </p>
                                    <ul className="mt-5 flex-1 space-y-2.5 border-t border-site-line pt-5">
                                        {service.points.map((point) => (
                                            <li
                                                key={point}
                                                className="flex items-start gap-2.5 text-sm font-medium text-slate-700"
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
                                        href={service.href}
                                        className="mt-6 inline-flex items-center gap-2 self-start text-sm font-bold text-site-accent-ink hover:underline"
                                    >
                                        {service.cta}
                                        <span className="sr-only">
                                            : {service.title}
                                        </span>
                                        <ArrowRight
                                            className="size-4 transition-transform group-hover:translate-x-1"
                                            aria-hidden
                                        />
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            </section>

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
