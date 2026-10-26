import { Link } from '@inertiajs/react';
import { ArrowRight, Layers, MapPin, Store, WifiOff } from 'lucide-react';
import {
    CtaBand,
    Eyebrow,
    FeatureGrid,
} from '@/sites/public/components/blocks';
import { aboutValues } from '@/sites/public/components/content';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo, { organization } from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

/** The story in four lines, beside the full text. */
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
                description="Miles Global Softwares develops ERP solutions, business applications and custom software for pharmacies, shops, restaurants and growing organizations."
                canonical={canonical}
                jsonLd={{
                    '@context': 'https://schema.org',
                    '@type': 'AboutPage',
                    url: canonical,
                    mainEntity: organization(new URL(canonical).origin),
                }}
            />

            <PageHero
                title={
                    <>
                        Software for the businesses that{' '}
                        <Accent>keep things running</Accent>
                    </>
                }
                intro="Miles Global Softwares is the software business of Miles Global Link Co. Ltd."
            />

            <section
                aria-labelledby="story-heading"
                className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20"
            >
                <div className="grid gap-8 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <Eyebrow>Our story</Eyebrow>
                        <SectionHeading
                            id="story-heading"
                            title={
                                <>
                                    We start <Accent>at the counter</Accent>
                                </>
                            }
                            align="left"
                        />
                        <p className="mt-6 text-xl leading-relaxed font-medium text-site-ink sm:text-2xl sm:leading-relaxed">
                            We build industry ERPs for the pharmacy on the
                            corner, the wholesaler on the market road, the
                            school and the NGO: businesses that have outgrown
                            spreadsheets and cash books, but not into a
                            big-vendor rollout.
                        </p>
                    </div>
                    <div className="space-y-5 text-lg leading-relaxed text-slate-700 lg:pt-10">
                        <p>
                            We start where the work happens, at the counter, in
                            the store room and at month-end. Then we make sure
                            every transaction lands in the accounts without
                            being typed twice.
                        </p>
                        <p>
                            Beyond our ready-made products, we develop custom
                            ERPs, web, desktop and mobile applications and
                            integrations, and we customize our own ERPs. Because
                            power and connections are not always reliable, the
                            desktop and mobile apps of our products keep working
                            offline where they have them, and a pharmacy can run
                            a single branch with no internet at all.
                        </p>
                        <p>
                            Every Miles product shares one platform, so a
                            business can begin with one application and add the
                            next when it is ready, without starting again.
                        </p>
                    </div>
                </div>

                <ul className="mt-12 grid gap-8 border-t border-site-line pt-10 sm:grid-cols-2 lg:grid-cols-4">
                    {facts.map(({ icon: Icon, title, body }) => (
                        <li key={title} className="flex gap-4">
                            <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-accent/10 text-site-accent-ink">
                                <Icon className="size-5" aria-hidden />
                            </span>
                            <div>
                                <p className="font-semibold text-site-ink">
                                    {title}
                                </p>
                                <p className="mt-1 text-sm leading-relaxed text-slate-600">
                                    {body}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            </section>

            <section
                aria-labelledby="values-heading"
                className="border-t border-site-line bg-white"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-20">
                    <SectionHeading
                        id="values-heading"
                        title={
                            <>
                                What we <Accent>hold to</Accent>
                            </>
                        }
                    />
                    <div className="mt-10 sm:mt-14">
                        <FeatureGrid features={aboutValues} columns={4} />
                    </div>
                </div>
            </section>

            <div>
                <CtaBand
                    id="about-cta-heading"
                    title={
                        <>
                            Let's <Accent>talk</Accent>
                        </>
                    }
                    body="Tell us about your business: what you sell, how many branches you run and where the work slows down today. We will suggest the Miles product that fits, show you how it works, or scope a custom system with you. There is no cost and no obligation, and we reply within one working day by email or WhatsApp."
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
