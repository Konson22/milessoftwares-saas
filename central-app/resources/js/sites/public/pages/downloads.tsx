import { Link } from '@inertiajs/react';
import { ArrowRight, Download, Monitor } from 'lucide-react';
import { CtaBand, Eyebrow } from '@/sites/public/components/blocks';
import PageHero, { Accent, IconBox } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, panel, textLink } from '@/sites/public/components/styles';

type DesktopApp = {
    code: string;
    name: string;
    summary: string;
    needs: string;
    version: string;
    /** Catalog page the app belongs to: erp/pharmacy. */
    product: string;
    /** Null while the installer is not on the server. */
    url: string | null;
    /** Bytes. */
    size: number | null;
};

type Props = {
    apps: DesktopApp[];
    canonical: string;
};

const steps = [
    {
        title: 'Download',
        body: 'Download the installer for the app you need.',
    },
    {
        title: 'Install',
        body: 'Run the installer and choose where to put the app. If Windows SmartScreen says it does not recognize the app, choose "More info", then "Run anyway".',
    },
    {
        title: 'Sign in',
        body: 'Sign in with your Miles account and choose your workspace and branch. The offline edition asks for your pharmacy and its first manager instead.',
    },
];

function megabytes(bytes: number): string {
    return `${Math.round(bytes / 1024 / 1024)} MB`;
}

/** The Windows installers of the desktop apps. */
export default function Downloads({ apps, canonical }: Props) {
    return (
        <>
            <Seo
                title="Download the desktop apps"
                description="Download the Miles desktop apps for Windows: the pharmacy and shop counter, the standalone offline pharmacy and the restaurant."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Miles on your desktop.{' '}
                        <Accent>Download for Windows.</Accent>
                    </>
                }
                intro="The counter apps run on a Windows PC beside your workspace in the browser, with barcode scanners, receipt printers and keyboard shortcuts."
            />

            <section
                aria-labelledby="apps-heading"
                className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24"
            >
                <Eyebrow>Desktop apps</Eyebrow>
                <SectionHeading
                    id="apps-heading"
                    title="Choose your app"
                    lead="Windows 10 or later, 64-bit. For macOS or Linux, talk to us."
                    align="left"
                />
                <ul className="mt-10 grid gap-5 lg:grid-cols-3">
                    {apps.map((app, i) => (
                        <li key={app.code}>
                            <article
                                className={`${panel} flex h-full flex-col p-6 sm:p-7`}
                            >
                                <IconBox icon={Monitor} index={i} />
                                <h3 className="mt-5 text-xl font-semibold">
                                    {app.name}
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">
                                    Version {app.version}
                                    {app.size !== null &&
                                        ` · ${megabytes(app.size)}`}
                                </p>
                                <p className="mt-4 flex-1 text-sm leading-relaxed text-slate-600">
                                    {app.summary}
                                </p>
                                <p className="mt-4 text-sm font-semibold text-slate-700">
                                    {app.needs}
                                </p>
                                <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                                    {app.url !== null ? (
                                        <a
                                            href={app.url}
                                            download
                                            className={button.primary}
                                        >
                                            <Download
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Download for Windows
                                            <span className="sr-only">
                                                : {app.name}
                                            </span>
                                        </a>
                                    ) : (
                                        <span className="inline-flex items-center rounded-full bg-site-soft px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-site-line">
                                            Download coming soon
                                        </span>
                                    )}
                                    <Link
                                        href={`/${app.product}`}
                                        className={textLink}
                                    >
                                        About the product
                                        <span className="sr-only">
                                            {' '}
                                            {app.name}
                                        </span>
                                    </Link>
                                </div>
                            </article>
                        </li>
                    ))}
                </ul>
            </section>

            <section
                aria-labelledby="install-heading"
                className="border-y border-site-line bg-site-soft"
            >
                <div className="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
                    <SectionHeading
                        id="install-heading"
                        title={
                            <>
                                Installing <Accent>in three steps</Accent>
                            </>
                        }
                        align="left"
                    />
                    <ol className="mt-10 grid gap-5 md:grid-cols-3">
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
                    id="downloads-cta-heading"
                    title={
                        <>
                            No workspace yet?{' '}
                            <Accent>Start with a free trial.</Accent>
                        </>
                    }
                    body="The online desktop apps sign in to your Miles workspace. Create one in a few minutes, then download the app for your counter."
                >
                    <Link href="/start" className={button.light}>
                        Get Started
                        <ArrowRight className="size-4" aria-hidden />
                    </Link>
                    <Link href="/contact" className={button.outlineDark}>
                        Talk to us
                    </Link>
                </CtaBand>
            </div>
        </>
    );
}
