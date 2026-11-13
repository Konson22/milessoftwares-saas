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
import { CtaBand, cardSurface } from '@/sites/public/components/blocks';
import { Accent, DarkHero } from '@/sites/public/components/page-hero';
import SectionHeading from '@/sites/public/components/section-heading';
import Seo from '@/sites/public/components/seo';
import { button, container, textLink } from '@/sites/public/components/styles';

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
        lead: 'Counter apps for Android phones and tablets. They sign in to your Tatook workspace.',
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
                title="Download the Tatook apps"
                description="Download Tatook for Windows and Android: pharmacy and shop counters that work online or fully offline, the restaurant app and mobile counters."
                canonical={canonical}
            />

            <DarkHero
                title={
                    <>
                        Get the Tatook apps.{' '}
                        <Accent>For Windows and Android.</Accent>
                    </>
                }
                intro="Install the counter apps on your PCs, phones and tablets. Everything you need to set them up is on this page."
                aside={<DevicesPicture />}
            >
                <a href="#windows" className={button.light}>
                    <Monitor className="size-4" aria-hidden />
                    Windows apps
                </a>
                <a href="#android" className={button.outlineDark}>
                    <Smartphone className="size-4" aria-hidden />
                    Android apps
                </a>
                <a
                    href="#setup"
                    className="ml-1 text-sm font-semibold text-site-highlight underline-offset-4 hover:underline"
                >
                    Setup help
                </a>
            </DarkHero>

            {platforms.map((platform, i) => (
                <section
                    key={platform.id}
                    id={platform.id}
                    aria-labelledby={`${platform.id}-heading`}
                    className={`scroll-mt-20 ${i === 0 ? 'bg-white' : 'bg-site-wash'}`}
                >
                    <div className={`${container} py-16 sm:py-24`}>
                        <div className="flex items-center gap-4">
                            <span className="inline-flex size-14 shrink-0 items-center justify-center rounded-2xl bg-linear-160 from-[#0a5a26] to-[#032a16] text-site-highlight shadow-lg shadow-site-night/20">
                                <platform.icon className="size-7" aria-hidden />
                            </span>
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
                        <ul className="mt-10 grid gap-6 lg:grid-cols-2">
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

            <div className="bg-white pt-16 sm:pt-24">
                <CtaBand
                    id="downloads-cta-heading"
                    title={
                        <>
                            No workspace yet?{' '}
                            <Accent>Start with a free trial.</Accent>
                        </>
                    }
                    body="The workspace apps sign in to your Tatook workspace. Create one in a few minutes, then install the app on your counter. The offline pharmacy needs no workspace at all."
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
    const PlatformIcon = windows ? Monitor : Smartphone;

    return (
        <article
            className={`${cardSurface} group flex h-full flex-col overflow-hidden`}
        >
            <div className="site-dark relative flex items-end gap-4 overflow-hidden bg-linear-160 from-[#0a5a26] via-[#063519] to-[#032a16] px-6 pt-8 pb-6 sm:px-8">
                <span
                    aria-hidden
                    className="absolute inset-0 bg-[radial-gradient(rgb(255_255_255/0.12)_1px,transparent_1.5px)] [background-size:14px_14px] [mask-image:linear-gradient(to_left,black,transparent_70%)]"
                />
                <span
                    aria-hidden
                    className="relative inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-white/10 text-site-highlight ring-1 ring-white/15"
                >
                    <PlatformIcon className="size-6" />
                </span>
                <div className="relative min-w-0 flex-1">
                    <h3 className="text-xl leading-snug font-semibold text-white">
                        {app.name}
                    </h3>
                    <p className="mt-1 text-sm text-slate-300">
                        {windows ? 'Windows' : 'Android'}
                        {app.size !== null && ` · ${megabytes(app.size)}`}
                    </p>
                </div>
                <span className="relative shrink-0 rounded-full bg-site-highlight px-3 py-1 text-xs font-extrabold text-site-night">
                    Version {app.version}
                </span>
                <span
                    aria-hidden
                    className="absolute bottom-0 left-6 h-1 w-12 rounded-t-full bg-site-highlight sm:left-8"
                />
            </div>
            <div className="flex flex-1 flex-col p-6 sm:p-8">
                <p className="flex-1 text-sm leading-relaxed text-slate-600">
                    {app.summary}
                </p>
                <p className="mt-5 flex gap-3 rounded-lg bg-site-wash p-4 text-sm text-slate-700 ring-1 ring-site-line">
                    <Cpu
                        className="mt-0.5 size-4 shrink-0 text-site-accent-ink"
                        aria-hidden
                    />
                    <span>
                        <span className="font-semibold text-site-ink">
                            Needs:{' '}
                        </span>
                        {app.needs}
                    </span>
                </p>
                <div className="mt-6 flex flex-wrap items-center gap-x-5 gap-y-3">
                    {app.url !== null && (
                        <a href={app.url} download className={button.primary}>
                            <Download className="size-4" aria-hidden />
                            {windows
                                ? 'Download for Windows'
                                : 'Download the APK'}
                            <span className="sr-only">: {app.name}</span>
                        </a>
                    )}
                    {app.store !== null && (
                        <a
                            href={app.store}
                            className={
                                app.url !== null
                                    ? button.outline
                                    : button.primary
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
                    Only install Tatook apps downloaded from tatook.com.
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
                    Tatook Pharmacy for Windows asks how you want to work the
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
                        <strong>With my Tatook workspace:</strong> sign in with
                        your Tatook account and choose the workspace and branch.
                        Sales made while the internet is down are sent once it
                        is back. Shops sign in to Tatook Retail the same way.
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
                            tatook.com/activate
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
                    Open the app and sign in with your Tatook account. Apps from
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
            className="scroll-mt-20 bg-white"
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
                        <ul className="space-y-1.5 rounded-xl bg-site-wash p-2 text-sm ring-1 ring-site-line">
                            {topics.map(({ icon: Icon, ...topic }) => (
                                <li key={topic.id}>
                                    <a
                                        href={`#${topic.id}`}
                                        className="flex items-center gap-3 rounded-lg px-3 py-2.5 font-semibold text-slate-700 transition-colors hover:bg-white hover:text-site-accent-ink hover:shadow-sm"
                                    >
                                        <Icon
                                            className="size-4 shrink-0 text-site-accent-ink"
                                            aria-hidden
                                        />
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
                            className="scroll-mt-24 rounded-xl bg-white p-6 shadow-[0_1px_2px_rgb(5_69_27/0.06),0_10px_30px_-12px_rgb(5_69_27/0.18)] ring-1 ring-site-line sm:p-8"
                        >
                            <div className="flex items-center gap-3">
                                <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-linear-160 from-[#0a5a26] to-[#032a16] text-site-highlight">
                                    <Icon className="size-5" aria-hidden />
                                </span>
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
            className="scroll-mt-20 bg-site-wash"
        >
            <div className={`${container} py-16 sm:py-24`}>
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
                <div className="mt-12 space-y-5">
                    {apps.map((app) => {
                        const [latest, ...older] = app.releases;
                        const PlatformIcon =
                            app.platform === 'windows' ? Monitor : Smartphone;

                        return (
                            <article
                                key={app.code}
                                id={`notes-${app.code}`}
                                aria-labelledby={`notes-${app.code}-heading`}
                                className="grid scroll-mt-24 gap-6 rounded-xl bg-white p-6 ring-1 ring-site-line sm:p-8 lg:grid-cols-[1fr_2fr] lg:gap-16"
                            >
                                <div className="flex items-start gap-3">
                                    <span className="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-site-accent/10 text-site-accent-ink">
                                        <PlatformIcon
                                            className="size-5"
                                            aria-hidden
                                        />
                                    </span>
                                    <div>
                                        <h3
                                            id={`notes-${app.code}-heading`}
                                            className="text-lg font-semibold"
                                        >
                                            {app.name}
                                        </h3>
                                        <p className="mt-0.5 text-sm text-slate-500">
                                            {app.platform === 'windows'
                                                ? 'Windows'
                                                : 'Android'}
                                        </p>
                                    </div>
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

/**
 * The hero's picture: a counter on a Windows PC with a phone in front of
 * it, and two notes floating beside them. Decorative.
 */
function DevicesPicture() {
    return (
        <div aria-hidden className="relative mx-auto max-w-lg py-6">
            <div className="absolute top-1/2 left-1/2 size-80 -translate-1/2 rounded-full bg-site-accent/20 blur-3xl" />

            {/* The PC: a window with the counter. */}
            <div className="relative overflow-hidden rounded-lg bg-white shadow-[0_30px_60px_-20px_rgb(0_0_0/0.6)] ring-1 ring-white/20">
                <div className="flex items-center gap-2 bg-[#04381a] px-4 py-2.5">
                    <span className="aspect-square w-3 bg-site-highlight [clip-path:polygon(0_0,100%_0,58%_50%,58%_100%,26%_100%,26%_50%,0_50%)]" />
                    <span className="text-xs font-semibold text-white">
                        Counter
                    </span>
                    <span className="ml-auto flex gap-1.5">
                        {[0, 1, 2].map((i) => (
                            <i
                                key={i}
                                className="size-2 rounded-full bg-white/35"
                            />
                        ))}
                    </span>
                </div>
                <div className="grid grid-cols-[1.5fr_1fr] gap-3 bg-site-soft p-4">
                    <div className="grid grid-cols-3 gap-2">
                        {Array.from({ length: 9 }, (_, i) => (
                            <span
                                key={i}
                                className="flex aspect-[4/3] flex-col justify-end gap-1 rounded-lg bg-white p-2 ring-1 ring-site-line"
                            >
                                <i className="h-1.5 w-4/5 rounded-full bg-site-line" />
                                <i className="h-1.5 w-1/2 rounded-full bg-site-accent/40" />
                            </span>
                        ))}
                    </div>
                    <div className="flex flex-col gap-2 rounded-lg bg-white p-3 ring-1 ring-site-line">
                        {[0, 1, 2, 3].map((i) => (
                            <span
                                key={i}
                                className="flex justify-between gap-2"
                            >
                                <i className="h-1.5 w-3/5 rounded-full bg-site-line" />
                                <i className="h-1.5 w-1/5 rounded-full bg-site-line" />
                            </span>
                        ))}
                        <span className="mt-auto block h-7 rounded-md bg-site-accent-ink" />
                    </div>
                </div>
            </div>

            {/* The phone, in front. */}
            <div className="absolute -right-2 -bottom-4 w-32 rotate-[6deg] rounded-[1.75rem] bg-[#0b1f14] p-1.5 shadow-[0_24px_48px_-12px_rgb(0_0_0/0.7)] ring-1 ring-white/15 xl:-right-6">
                <div className="overflow-hidden rounded-[1.4rem] bg-site-soft">
                    <div className="bg-[#04381a] px-3 pt-3 pb-2">
                        <i className="mx-auto block h-1 w-8 rounded-full bg-white/30" />
                        <i className="mt-2 block h-1.5 w-12 rounded-full bg-white/60" />
                    </div>
                    <div className="space-y-1.5 p-2.5">
                        {[0, 1, 2, 3].map((i) => (
                            <span
                                key={i}
                                className="flex items-center gap-1.5 rounded-md bg-white p-1.5 ring-1 ring-site-line"
                            >
                                <i className="size-3 rounded bg-site-accent/30" />
                                <i className="h-1.5 flex-1 rounded-full bg-site-line" />
                            </span>
                        ))}
                        <span className="block h-6 rounded-md bg-site-accent-ink" />
                    </div>
                </div>
            </div>

            <div className="site-float absolute -top-1 -left-4">
                <span className="inline-flex items-center gap-2 rounded-full bg-site-highlight px-4 py-2 text-sm font-bold text-site-night shadow-lg shadow-black/30">
                    <Wifi className="size-4" />
                    Keeps selling offline
                </span>
            </div>
            <div className="site-float-slow absolute bottom-6 -left-6">
                <span className="inline-flex items-center gap-2 rounded-full bg-white px-4 py-2 text-sm font-bold text-site-ink shadow-lg shadow-black/30">
                    <Printer className="size-4 text-site-accent-ink" />
                    Receipts and barcodes
                </span>
            </div>
        </div>
    );
}
