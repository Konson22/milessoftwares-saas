import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import {
    CtaBand,
    Eyebrow,
    FeatureGrid,
} from '@/sites/public/components/blocks';
import { platformGroups } from '@/sites/public/components/content';
import PageHero, { Accent } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button } from '@/sites/public/components/styles';

type Props = {
    canonical: string;
};

/** What every Miles product stands on: workspace, sign-in, roles, data. */
export default function Platform({ canonical }: Props) {
    return (
        <>
            <Seo
                title="Platform and security"
                description="One sign-in for every application, passkeys, roles per branch, separation of duties and an append-only audit log."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        One workspace. Every app. <Accent>Your rules.</Accent>
                    </>
                }
                intro="Every Miles product runs on the same foundation: one sign-in, one organization chart, one set of permissions, and a record of who did what."
            >
                <nav
                    aria-label="On this page"
                    className="mt-9 flex flex-wrap justify-center gap-2.5"
                >
                    {platformGroups.map((group) => (
                        <a
                            key={group.id}
                            href={`#${group.id}`}
                            className="rounded-full bg-white/10 px-4 py-2 text-sm font-semibold text-white ring-1 ring-white/25 transition-colors hover:bg-white/20"
                        >
                            {group.title}
                        </a>
                    ))}
                </nav>
            </PageHero>

            {platformGroups.map((group, i) => (
                <section
                    key={group.id}
                    id={group.id}
                    aria-labelledby={`${group.id}-heading`}
                    className={`scroll-mt-24 ${i % 2 === 1 ? 'border-y border-site-line bg-site-soft' : ''}`}
                >
                    <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                        <Eyebrow>{String(i + 1).padStart(2, '0')}</Eyebrow>
                        <SectionHeading
                            id={`${group.id}-heading`}
                            title={group.title}
                            lead={group.lead}
                            align="left"
                        />
                        <div className="mt-10">
                            <FeatureGrid features={group.points} />
                        </div>
                    </div>
                </section>
            ))}

            <div className="pt-16 sm:pt-24">
                <CtaBand
                    id="platform-cta-heading"
                    title={
                        <>
                            Questions about <Accent>security?</Accent>
                        </>
                    }
                    body="Ask us anything about how your data is kept, who can see it and how access is controlled."
                >
                    <Link href="/contact" className={button.light}>
                        Talk to us
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link href="/start" className={button.outlineDark}>
                        Start free trial
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}
