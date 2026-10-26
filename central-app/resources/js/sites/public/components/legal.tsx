import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import PageHero from '@/sites/public/components/page-hero';
import { supportEmail } from '@/sites/public/components/social';

export type LegalSection = {
    id: string;
    title: string;
    body: ReactNode;
};

/**
 * The privacy policy and terms pages: a short hero with the date they
 * last changed, a contents list beside the text (above it on phones),
 * then numbered sections.
 */
export default function LegalPage({
    title,
    intro,
    updated,
    sections,
}: {
    title: ReactNode;
    intro: string;
    /** ISO date, e.g. 2026-10-26. */
    updated: string;
    sections: LegalSection[];
}) {
    const date = new Date(`${updated}T00:00:00Z`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });

    return (
        <>
            <PageHero
                title={title}
                intro={intro}
                above={
                    <p className="text-sm font-semibold text-white/80">
                        Last updated <time dateTime={updated}>{date}</time>
                    </p>
                }
            />

            <div className="mx-auto grid max-w-7xl gap-10 px-4 py-12 sm:px-6 sm:py-16 lg:grid-cols-[16rem_1fr] lg:gap-16">
                <nav
                    aria-label="On this page"
                    className="lg:sticky lg:top-28 lg:self-start"
                >
                    <p className="text-sm font-bold tracking-wider text-site-accent-ink uppercase">
                        On this page
                    </p>
                    <ol className="mt-4 space-y-2 border-l border-site-line text-sm">
                        {sections.map((section, i) => (
                            <li key={section.id}>
                                <a
                                    href={`#${section.id}`}
                                    className="-ml-px block border-l-2 border-transparent py-0.5 pl-4 text-slate-600 hover:border-site-accent-ink hover:text-site-accent-ink"
                                >
                                    {i + 1}. {section.title}
                                </a>
                            </li>
                        ))}
                    </ol>
                </nav>

                <div className="max-w-3xl space-y-12 text-base leading-relaxed text-slate-700">
                    {sections.map((section, i) => (
                        <section
                            key={section.id}
                            id={section.id}
                            aria-labelledby={`${section.id}-heading`}
                            className="scroll-mt-28 space-y-4 [&_a]:font-semibold [&_a]:text-site-accent-ink [&_a]:underline-offset-2 [&_a:hover]:underline [&_li]:pl-1 [&_ul]:list-disc [&_ul]:space-y-2 [&_ul]:pl-5"
                        >
                            <h2
                                id={`${section.id}-heading`}
                                className="text-xl font-semibold text-site-ink sm:text-2xl"
                            >
                                {i + 1}. {section.title}
                            </h2>
                            {section.body}
                        </section>
                    ))}

                    <p className="border-t border-site-line pt-8 text-sm text-slate-600">
                        Questions about this page? Email{' '}
                        <a
                            href={`mailto:${supportEmail}`}
                            className="font-semibold text-site-accent-ink hover:underline"
                        >
                            {supportEmail}
                        </a>{' '}
                        or use the{' '}
                        <Link
                            href="/contact"
                            className="font-semibold text-site-accent-ink hover:underline"
                        >
                            contact form
                        </Link>
                        .
                    </p>
                </div>
            </div>
        </>
    );
}
