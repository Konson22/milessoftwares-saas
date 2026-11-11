import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Layers,
    MapPin,
    Quote,
    Store,
    WifiOff,
} from 'lucide-react';
import { CtaBand, FeatureCards } from '@/sites/public/components/blocks';
import { aboutValues } from '@/sites/public/components/content';
import { Accent, DarkHero } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization } from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

/** The story in four lines, beside the hero's title. */
const facts = [
    {
        icon: MapPin,
        title: 'Based in Juba',
        body: 'Built in South Sudan, for businesses across the region.',
    },
    {
        icon: Store,
        title: 'Industry ERPs and custom software',
        body: 'Pharmacy, retail and finance today, with more on the way.',
    },
    {
        icon: WifiOff,
        title: 'Works when the internet does not',
        body: 'Desktop and mobile apps that keep going offline.',
    },
    {
        icon: Layers,
        title: 'One platform',
        body: 'Start with one application and add the next when ready.',
    },
];

type Props = {
    canonical: string;
};

export default function About({ canonical }: Props) {
    return (
        <>
            <Seo
                title="About us"
                description="Tatook develops ERP solutions, business applications and custom software for pharmacies, shops, restaurants and growing organizations."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'AboutPage',
                    url: canonical,
                    mainEntity: organization(new URL(canonical).origin),
                }}
            />

            <DarkHero
                title={
                    <>
                        Software for the businesses that{' '}
                        <Accent>keep things running</Accent>
                    </>
                }
                intro="Tatook is a product of Miles Global Link Co. Ltd."
                asideClassName=""
                aside={
                    <ul className="grid gap-3 sm:grid-cols-2">
                        {facts.map(({ icon: Icon, title, body }, i) => (
                            <li
                                key={title}
                                className={`rounded-xl bg-white/[0.07] p-5 ring-1 ring-white/10 backdrop-blur-sm transition-colors hover:bg-white/[0.1] ${i % 2 === 1 ? 'sm:translate-y-6' : ''}`}
                            >
                                <span className="inline-flex size-10 items-center justify-center rounded-xl bg-site-highlight text-site-night">
                                    <Icon className="size-5" aria-hidden />
                                </span>
                                <p className="mt-4 font-semibold text-white">
                                    {title}
                                </p>
                                <p className="mt-1 text-sm leading-relaxed text-slate-300">
                                    {body}
                                </p>
                            </li>
                        ))}
                    </ul>
                }
            >
                <Link href="/contact" className={button.light}>
                    Contact us
                    <ArrowRight className="size-4" aria-hidden />
                </Link>
                <Link href="/products" className={button.outlineDark}>
                    See our products
                </Link>
            </DarkHero>

            <section aria-labelledby="story-heading" className="bg-white">
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1.1fr_1fr] lg:gap-16 lg:px-8">
                    <div className="site-dark relative overflow-hidden rounded-xl bg-linear-160 from-[#0a5a26] via-[#063519] to-[#032a16] p-8 shadow-xl shadow-site-night/20 sm:p-10">
                        <span
                            aria-hidden
                            className="absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.12)_1px,transparent_1.5px)] [background-size:16px_16px] [mask-image:linear-gradient(to_top_left,black,transparent_60%)]"
                        />
                        <Quote
                            aria-hidden
                            className="absolute -top-2 right-6 size-28 rotate-180 text-white/[0.07]"
                        />
                        <div className="relative">
                            <SectionHeading
                                id="story-heading"
                                title={
                                    <>
                                        We start <Accent>at the counter</Accent>
                                    </>
                                }
                                align="left"
                            />
                            <p className="mt-6 text-xl leading-relaxed font-medium text-white sm:text-2xl sm:leading-relaxed">
                                We build industry ERPs for the pharmacy on the
                                corner, the wholesaler on the market road, the
                                school and the NGO: businesses that have
                                outgrown spreadsheets and cash books, but not
                                into a big-vendor rollout.
                            </p>
                        </div>
                    </div>
                    <div className="space-y-6 text-lg leading-relaxed text-slate-700 lg:self-center">
                        <p className="border-l-4 border-site-highlight pl-5">
                            We start where the work happens, at the counter, in
                            the store room and at month-end. Then we make sure
                            every transaction lands in the accounts without
                            being typed twice.
                        </p>
                        <p className="border-l-4 border-site-accent pl-5">
                            Beyond our ready-made products, we develop custom
                            ERPs, web, desktop and mobile applications and
                            integrations, and we customize our own ERPs. Because
                            power and connections are not always reliable, the
                            desktop and mobile apps of our products keep working
                            offline where they have them, and a pharmacy can run
                            a single branch with no internet at all.
                        </p>
                        <p className="border-l-4 border-site-accent-ink pl-5">
                            Every Tatook product shares one platform, so a
                            business can begin with one application and add the
                            next when it is ready, without starting again.
                        </p>
                    </div>
                </div>
            </section>

            <section aria-labelledby="values-heading" className="bg-site-wash">
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24 lg:px-8">
                    <SectionHeading
                        id="values-heading"
                        title={
                            <>
                                What we <Accent>hold to</Accent>
                            </>
                        }
                    />
                    <div className="mt-10 sm:mt-14">
                        <FeatureCards features={aboutValues} columns={4} />
                    </div>
                </div>
            </section>

            <div className="bg-white pt-16 sm:pt-24">
                <CtaBand
                    id="about-cta-heading"
                    title={
                        <>
                            Let's <Accent>talk</Accent>
                        </>
                    }
                    body="Tell us about your business: what you sell, how many branches you run and where the work slows down today. We will suggest the Tatook product that fits, show you how it works, or scope a custom system with you. There is no cost and no obligation, and we reply within one working day by email or WhatsApp."
                >
                    <Link href="/contact" className={button.light}>
                        Contact us
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link href="/products" className={button.outlineDark}>
                        See our products
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}
