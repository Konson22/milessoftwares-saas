import { Link } from '@inertiajs/react';
import {
    ArrowRight,
    Cpu,
    Download,
    KeyRound,
    Monitor,
    Printer,
    ShieldAlert,
    Smartphone,
    Wifi,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { CtaBand } from '@/sites/public/components/blocks';
import PageHero, { Accent, IconBox } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import {
    button,
    container,
    panel,
    textLink,
} from '@/sites/public/components/styles';

type Release = {
    version: string;
    /** YYYY-MM-DD; null before the release has a date. */
    date: string | null;
    changes: string[];
    /** An older version still on the server; null for the current one. */
    url: string | null;
};

type App = {
    code: string;
    platform: 'windows' | 'android';
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
    /** Google Play, when the app is listed there. */
    store: string | null;
    /** Newest first. */
    releases: Release[];
};

type Props = {
    apps: App[];
    canonical: string;
};

const platforms: {
    id: App['platform'];
    title: string;
    lead: string;
    icon: LucideIcon;
}[] = [
    {
        id: 'windows',
        title: 'Windows',
        lead: 'Counter apps for a Windows PC, with barcode scanners, receipt printers and keyboard shortcuts.',
        icon: Monitor,
    },
    {
        id: 'android',
        title: 'Android',
        lead: 'Counter apps for Android phones and tablets. They sign in to your Miles workspace.',
        icon: Smartphone,
    },
];

const months = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** "31 October 2026", without the visitor's time zone moving the day. */
function longDate(date: string): string {
    const [year, month, day] = date.split('-').map(Number);

    return `${day} ${months[month - 1]} ${year}`;
}

function megabytes(bytes: number): string {
    return `${Math.round(bytes / 1024 / 1024)} MB`;
}

/** The Windows and Android apps, how to set them up, and what changed. */
export default function Downloads({ apps, canonical }: Props) {
    return (
        <>
            <Seo
                title="Download the Miles apps"
                description="Download the Miles apps for Windows and Android: the pharmacy and shop counter (online or fully offline), the restaurant, and the mobile counter apps. Setup help and release notes."
                canonical={canonical}
            />

            <PageHero
                title={
                    <>
                        Get the Miles apps.{' '}
                        <Accent>For Windows and Android.</Accent>
                    </>
                }
                intro="Install the counter apps on your PCs, phones and tablets. Everything you need to set them up is on this page."
            >
                <a href="#windows" className={button.primary}>
                    <Monitor className="size-4" aria-hidden />
                    Windows apps
                </a>
                <a href="#android" className={button.outline}>
                    <Smartphone className="size-4" aria-hidden />
                    Android apps
                </a>
                <a href="#setup" className={`${textLink} ml-2 text-sm`}>
                    Setup help
                </a>
            </PageHero>

            {platforms.map((platform, i) => (
                <section
                    key={platform.id}
                    id={platform.id}
                    aria-labelledby={`${platform.id}-heading`}
                    className={`scroll-mt-20 ${i === 0 ? 'border-b border-site-line' : ''}`}
                >
                    <div className={`${container} py-16 sm:py-24`}>
                        <div className="flex items-center gap-4">
                            <IconBox icon={platform.icon} />
                            <div>
                                <h2
                                    id={`${platform.id}-heading`}
                                    className="text-2xl font-semibold tracking-tight sm:text-3xl"
                                >
                                    {platform.title}
                                </h2>
                                <p className="mt-1 text-sm text-slate-600 sm:text-base">
                                    {platform.lead}
                                </p>
                            </div>
                        </div>
                        <ul className="mt-10 grid gap-5 lg:grid-cols-2">
                            {apps
                                .filter((app) => app.platform === platform.id)
                                .map((app) => (
                                    <li key={app.code}>
                                        <AppCard app={app} />
                                    </li>
                                ))}
                        </ul>
                    </div>
                </section>
            ))}

            <SetupHelp />

            <ReleaseNotes apps={apps} />

            <div className="pt-16 sm:pt-24">
                <CtaBand
                    id="downloads-cta-heading"
                    title={
                        <>
                            No workspace yet?{' '}
                            <Accent>Start with a free trial.</Accent>
                        </>
                    }
                    body="The workspace apps sign in to your Miles workspace. Create one in a few minutes, then install the app on your counter. The offline pharmacy needs no workspace at all."
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

/** One app: what it is, what it needs, and its downloads. */
function AppCard({ app }: { app: App }) {
    const windows = app.platform === 'windows';

    return (
        <article className={`${panel} flex h-full flex-col p-6 sm:p-8`}>
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h3 className="text-xl font-semibold">{app.name}</h3>
                <p className="text-sm text-slate-500">
                    Version {app.version}
                    {app.size !== null && ` · ${megabytes(app.size)}`}
                </p>
            </div>
            <p className="mt-3 flex-1 text-sm leading-relaxed text-slate-600">
                {app.summary}
            </p>
            <p className="mt-5 border-t border-site-line pt-4 text-sm text-slate-700">
                <span className="font-semibold">Needs: </span>
                {app.needs}
            </p>
            <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                {app.url !== null && (
                    <a href={app.url} download className={button.primary}>
                        <Download className="size-4" aria-hidden />
                        {windows ? 'Download for Windows' : 'Download the APK'}
                        <span className="sr-only">: {app.name}</span>
                    </a>
                )}
                {app.store !== null && (
                    <a
                        href={app.store}
                        className={
                            app.url !== null ? button.outline : button.primary
                        }
                        rel="noopener"
                    >
                        Get it on Google Play
                        <span className="sr-only">: {app.name}</span>
                    </a>
                )}
                {app.url === null && app.store === null && (
                    <span className="inline-flex items-center rounded-lg bg-site-soft px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-site-line">
                        Download coming soon
                    </span>
                )}
                <Link
                    href={`/${app.product}`}
                    className={`${textLink} text-sm`}
                >
                    About the product
                    <span className="sr-only"> {app.name}</span>
                </Link>
                <a
                    href={`#notes-${app.code}`}
                    className={`${textLink} text-sm`}
                >
                    What's new
                    <span className="sr-only"> in {app.name}</span>
                </a>
            </div>
        </article>
    );
}

const topics: {
    id: string;
    title: string;
    icon: LucideIcon;
    body: ReactNode;
}[] = [
    {
        id: 'requirements',
        title: 'System requirements',
        icon: Cpu,
        body: (
            <ul className="list-disc space-y-1.5 pl-5">
                <li>
                    Windows apps: Windows 10 or 11, 64-bit, with 4 GB of memory
                    and 1 GB of free disk space.
                </li>
                <li>
                    Android apps: a phone or tablet with Android 7 or later and
                    a camera for scanning barcodes.
                </li>
                <li>
                    Workspace apps need the internet to sign in; after that they
                    keep selling when it drops. The offline pharmacy never needs
                    it.
                </li>
                <li>
                    Optional: a USB barcode scanner and an 80 mm or 58 mm
                    receipt printer. For macOS or Linux,{' '}
                    <Link href="/contact" className={textLink}>
                        talk to us
                    </Link>
                    .
                </li>
            </ul>
        ),
    },
    {
        id: 'smartscreen',
        title: 'If Windows says it protected your PC',
        icon: ShieldAlert,
        body: (
            <>
                <p>
                    Windows SmartScreen warns about apps it has not seen often,
                    and new releases are among them. If you downloaded the
                    installer from this page:
                </p>
                <ol className="mt-3 list-decimal space-y-1.5 pl-5">
                    <li>
                        In the "Windows protected your PC" box, choose{' '}
                        <strong>More info</strong>.
                    </li>
                    <li>
                        Check that the publisher and file name match, then
                        choose <strong>Run anyway</strong>.
                    </li>
                </ol>
                <p className="mt-3">
                    Only install Miles apps downloaded from milesoftwares.com.
                </p>
            </>
        ),
    },
    {
        id: 'modes',
        title: 'Offline pharmacy or workspace',
        icon: Wifi,
        body: (
            <>
                <p>
                    Miles Pharmacy for Windows asks how you want to work the
                    first time it opens:
                </p>
                <ul className="mt-3 list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>On this PC only (offline):</strong> the whole
                        pharmacy on one PC, with its own database. No account or
                        internet; free for 90 days, then activated with a
                        licence key. Back it up from Settings, Backups.
                    </li>
                    <li>
                        <strong>With my Miles workspace:</strong> sign in with
                        your Miles account and choose the workspace and branch.
                        Sales made while the internet is down are sent once it
                        is back. Shops sign in to Miles Retail the same way.
                    </li>
                </ul>
            </>
        ),
    },
    {
        id: 'activation',
        title: 'Activating with a licence key',
        icon: KeyRound,
        body: (
            <>
                <p>
                    An offline pharmacy is activated with a licence key such as
                    MILES-XXXX-XXXX-XXXX. In the app, open{' '}
                    <strong>Settings, Activation</strong>:
                </p>
                <ul className="mt-3 list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>With internet:</strong> type the key and choose
                        Activate. It is done in a few seconds.
                    </li>
                    <li>
                        <strong>Without internet on that PC:</strong> scan the
                        QR code with your phone, or open{' '}
                        <Link href="/activate" className={textLink}>
                            milesoftwares.com/activate
                        </Link>{' '}
                        and enter the device code the app shows. Type the
                        activation code you get back into the app, or open the
                        licence file we email you.
                    </li>
                </ul>
                <p className="mt-3">
                    A key works on one PC. Moving to a new PC?{' '}
                    <Link href="/contact" className={textLink}>
                        Contact us
                    </Link>{' '}
                    and we release it.
                </p>
            </>
        ),
    },
    {
        id: 'printer',
        title: 'Setting up a receipt printer',
        icon: Printer,
        body: (
            <>
                <p>
                    In the app, open <strong>Settings, Receipt</strong> and
                    choose how the printer is connected:
                </p>
                <ul className="mt-3 list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>Windows printer (USB):</strong> install the
                        printer's driver first, then pick it from the list.
                    </li>
                    <li>
                        <strong>Network thermal printer:</strong> enter its IP
                        address (printed on its self-test page) and port 9100.
                    </li>
                </ul>
                <p className="mt-3">
                    Choose the paper width (80 mm or 58 mm), check the
                    connection, print a test page, then save.
                </p>
            </>
        ),
    },
    {
        id: 'apk',
        title: 'Installing an Android app',
        icon: Smartphone,
        body: (
            <ol className="list-decimal space-y-1.5 pl-5">
                <li>
                    Open this page on the phone or tablet and download the APK.
                </li>
                <li>
                    Open the downloaded file. If Android asks, allow your
                    browser to install apps, then go back and choose Install.
                </li>
                <li>
                    Open the app and sign in with your Miles account. Apps from
                    Google Play, when listed, update by themselves.
                </li>
            </ol>
        ),
    },
];

/** Setup help: a list of topics beside their answers. */
function SetupHelp() {
    return (
        <section
            id="setup"
            aria-labelledby="setup-heading"
            className="scroll-mt-20 border-y border-site-line bg-site-soft"
        >
            <div
                className={`${container} grid gap-10 py-16 sm:py-24 lg:grid-cols-[1fr_2fr] lg:gap-16`}
            >
                <div className="lg:sticky lg:top-24 lg:self-start">
                    <SectionHeading
                        id="setup-heading"
                        title={
                            <>
                                Installed and working{' '}
                                <Accent>in minutes.</Accent>
                            </>
                        }
                        align="left"
                    />
                    <nav aria-label="Setup topics" className="mt-8">
                        <ul className="space-y-1 text-sm">
                            {topics.map((topic) => (
                                <li key={topic.id}>
                                    <a
                                        href={`#${topic.id}`}
                                        className="block rounded-lg px-3 py-2 font-medium text-slate-600 transition-colors hover:bg-white hover:text-site-ink"
                                    >
                                        {topic.title}
                                    </a>
                                </li>
                            ))}
                        </ul>
                    </nav>
                </div>
                <div className="space-y-5">
                    {topics.map(({ icon: Icon, ...topic }) => (
                        <article
                            key={topic.id}
                            id={topic.id}
                            aria-labelledby={`${topic.id}-heading`}
                            className={`${panel} scroll-mt-24 p-6 sm:p-8`}
                        >
                            <div className="flex items-center gap-3">
                                <Icon
                                    className="size-5 text-site-accent-ink"
                                    aria-hidden
                                />
                                <h3
                                    id={`${topic.id}-heading`}
                                    className="text-lg font-semibold"
                                >
                                    {topic.title}
                                </h3>
                            </div>
                            <div className="mt-4 text-sm leading-relaxed text-slate-600 [&_strong]:font-semibold [&_strong]:text-site-ink">
                                {topic.body}
                            </div>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}

/** What changed in each version, newest first; older ones fold away. */
function ReleaseNotes({ apps }: { apps: App[] }) {
    return (
        <section
            id="release-notes"
            aria-labelledby="notes-heading"
            className={`${container} scroll-mt-20 py-16 sm:py-24`}
        >
            <SectionHeading
                id="notes-heading"
                title={
                    <>
                        What's <Accent>new</Accent>
                    </>
                }
                lead="The changes in each version. Older versions stay here, with their installer while we still keep it."
                align="left"
            />
            <div className="mt-12 divide-y divide-site-line border-y border-site-line">
                {apps.map((app) => {
                    const [latest, ...older] = app.releases;

                    return (
                        <article
                            key={app.code}
                            id={`notes-${app.code}`}
                            aria-labelledby={`notes-${app.code}-heading`}
                            className="grid scroll-mt-24 gap-6 py-10 lg:grid-cols-[1fr_2fr] lg:gap-16"
                        >
                            <div>
                                <h3
                                    id={`notes-${app.code}-heading`}
                                    className="text-lg font-semibold"
                                >
                                    {app.name}
                                </h3>
                                <p className="mt-1 text-sm text-slate-500">
                                    {app.platform === 'windows'
                                        ? 'Windows'
                                        : 'Android'}
                                </p>
                            </div>
                            <div>
                                {latest ? (
                                    <ReleaseEntry release={latest} latest />
                                ) : (
                                    <p className="text-sm text-slate-600">
                                        Notes come with the first release.
                                    </p>
                                )}
                                {older.length > 0 && (
                                    <details className="group mt-6 rounded-xl border border-site-line">
                                        <summary className="cursor-pointer list-none px-5 py-3 text-sm font-semibold text-site-accent-ink marker:hidden">
                                            Older versions ({older.length})
                                        </summary>
                                        <div className="space-y-8 border-t border-site-line px-5 py-6">
                                            {older.map((release) => (
                                                <ReleaseEntry
                                                    key={release.version}
                                                    release={release}
                                                    name={app.name}
                                                />
                                            ))}
                                        </div>
                                    </details>
                                )}
                            </div>
                        </article>
                    );
                })}
            </div>
        </section>
    );
}

function ReleaseEntry({
    release,
    latest = false,
    name,
}: {
    release: Release;
    latest?: boolean;
    name?: string;
}) {
    return (
        <div>
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                <h4 className="font-semibold">Version {release.version}</h4>
                {latest && (
                    <span className="rounded-full bg-site-accent/10 px-2.5 py-0.5 text-xs font-semibold text-site-accent-ink">
                        Latest
                    </span>
                )}
                {release.date && (
                    <time
                        dateTime={release.date}
                        className="text-sm text-slate-500"
                    >
                        {longDate(release.date)}
                    </time>
                )}
            </div>
            <ul className="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-slate-600">
                {release.changes.map((change) => (
                    <li key={change}>{change}</li>
                ))}
            </ul>
            {release.url !== null && (
                <a
                    href={release.url}
                    download
                    className={`${textLink} mt-3 inline-flex items-center gap-1.5 text-sm`}
                >
                    <Download className="size-4" aria-hidden />
                    Download version {release.version}
                    {name && <span className="sr-only"> of {name}</span>}
                </a>
            )}
        </div>
    );
}
