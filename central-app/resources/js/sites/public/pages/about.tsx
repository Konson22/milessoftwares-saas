import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
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

type Props = {
    canonical: string;
};

export default function About({ canonical }: Props) {
    return (
        <>
            <Seo
                title="About us"
                description="Miles Global Softwares builds ready-made and custom ERPs, websites, desktop and mobile apps for pharmacies, shops, schools and NGOs."
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
                className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 sm:py-24 lg:grid-cols-[1fr_1.4fr] lg:gap-16"
            >
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
                </div>
                <div className="space-y-5 text-lg leading-relaxed text-slate-700">
                    <p>
                        We build industry ERPs for the pharmacy on the corner,
                        the wholesaler on the market road, the school and the
                        NGO: businesses that have outgrown spreadsheets and cash
                        books, but not into a big-vendor rollout.
                    </p>
                    <p>
                        We start where the work happens, at the counter, in the
                        store room and at month-end. Then we make sure every
                        transaction lands in the accounts without being typed
                        twice.
                    </p>
                    <p>
                        Beyond our ready-made ERPs, we build custom ERPs,
                        websites, desktop apps and mobile apps. Because power
                        and connections are not always reliable, our desktop and
                        mobile apps keep working offline, and a single branch
                        can run with no internet at all.
                    </p>
                    <p>
                        Every Miles product shares one platform, so a business
                        can begin with one application and add the next when it
                        is ready, without starting again.
                    </p>
                </div>
            </section>

            <section
                aria-labelledby="values-heading"
                className="border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
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

            <div className="pt-16 sm:pt-24">
                <CtaBand
                    id="about-cta-heading"
                    title={
                        <>
                            Let's <Accent>talk</Accent>
                        </>
                    }
                    body="Tell us about your business. We reply within one working day."
                >
                    <Link href="/contact" className={button.light}>
                        Contact us
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link href="/industries" className={button.outlineDark}>
                        See industries
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}
