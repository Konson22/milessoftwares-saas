import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Clock, Menu, Sparkles, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import { HeroBackdrop } from '@/sites/public/components/page-hero';
import { button, darkBand } from '@/sites/public/components/styles';
import { login, register } from '@/routes';

// Relative paths on the apex host; sign-in and registration live on the
// auth host, so those are plain links.
const nav = [
    { title: 'Home', href: '/' },
    { title: 'ERP products', href: '/#erp' },
    { title: 'Applications', href: '/#products' },
    { title: 'How it works', href: '/#how-it-works' },
    { title: 'Pricing', href: '/pricing' },
    { title: 'Contact', href: '/contact' },
];

function Brand({ inverted = false }: { inverted?: boolean }) {
    return (
        <Link
            href="/"
            className={`flex items-center gap-2.5 font-public-display text-lg font-semibold tracking-tight ${inverted ? 'text-white' : 'text-site-ink'}`}
        >
            {/* Decorative: the name beside it is the link text. */}
            <AppLogoIcon className="size-9" alt="" />
            Miles Softwares
        </Link>
    );
}

/** The current page, for aria-current and the highlighted nav item. */
function useCurrentPath(): string {
    const { url } = usePage();

    return url.split('?')[0];
}

/**
 * A thin gradient announcement bar, a frosted header that stays on top
 * (it gains a shadow once the page scrolls), the page, and a deep indigo
 * footer under a wave (see styles.ts for the palette).
 */
export default function MarketingLayout({ children }: PropsWithChildren) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const path = useCurrentPath();

    useEffect(() => {
        if (!menuOpen) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setMenuOpen(false);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [menuOpen]);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 8);

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <div className="site-public flex min-h-screen flex-col overflow-x-clip bg-white font-public text-site-ink">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <div className="site-dark site-gradient text-sm text-white">
                <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-center gap-x-6 gap-y-1 px-4 py-2 sm:justify-between sm:px-6">
                    <p className="flex flex-wrap items-center justify-center gap-2">
                        <span className="inline-flex items-center gap-1 rounded-full bg-white/20 px-2.5 py-0.5 text-xs font-bold tracking-wide uppercase">
                            <Sparkles className="size-3" aria-hidden />
                            Free trial
                        </span>
                        Every application starts free, no payment details
                        needed.
                        <Link
                            href="/start"
                            className="inline-flex items-center gap-1 font-bold underline-offset-2 hover:underline"
                        >
                            Start yours
                            <ArrowRight className="size-3.5" aria-hidden />
                        </Link>
                    </p>
                    <p className="hidden items-center gap-1.5 text-white/85 md:inline-flex">
                        <Clock className="size-3.5" aria-hidden />
                        We reply within one working day
                    </p>
                </div>
            </div>
            <header
                className={`sticky top-0 z-40 border-b bg-white/85 backdrop-blur-lg transition-shadow duration-300 ${scrolled ? 'border-site-line shadow-lg shadow-site-ink/5' : 'border-transparent'}`}
            >
                <nav
                    aria-label="Main"
                    className="mx-auto flex h-16 max-w-7xl items-center gap-8 px-4 sm:h-20 sm:px-6"
                >
                    <Brand />
                    <ul className="mx-auto hidden items-center gap-1 rounded-full border border-site-line bg-white/70 p-1 text-[15px] font-semibold text-slate-700 lg:flex">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.href === path ? 'page' : undefined
                                    }
                                    className="block rounded-full px-4 py-2 transition-colors hover:bg-site-soft hover:text-site-accent-ink aria-[current=page]:bg-site-accent/10 aria-[current=page]:text-site-accent-ink"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="hidden items-center gap-5 lg:flex">
                        <a
                            href={login.url()}
                            className="text-[15px] font-semibold text-slate-700 hover:text-site-accent-ink"
                        >
                            Sign in
                        </a>
                        <Link
                            href="/start"
                            aria-current={
                                path === '/start' ? 'page' : undefined
                            }
                            className={`${button.primary} py-3`}
                        >
                            Start free trial
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                    <button
                        type="button"
                        className="ml-auto inline-flex size-10 items-center justify-center rounded-full border border-site-line text-site-ink hover:bg-site-soft lg:hidden"
                        aria-expanded={menuOpen}
                        aria-controls="mobile-menu"
                        onClick={() => setMenuOpen((open) => !open)}
                    >
                        {menuOpen ? (
                            <X className="size-5" aria-hidden />
                        ) : (
                            <Menu className="size-5" aria-hidden />
                        )}
                        <span className="sr-only">Menu</span>
                    </button>
                </nav>
                <div
                    id="mobile-menu"
                    hidden={!menuOpen}
                    className="border-t border-site-line bg-white lg:hidden"
                >
                    <ul className="mx-auto max-w-7xl space-y-1 px-4 py-3 sm:px-6">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.href === path ? 'page' : undefined
                                    }
                                    onClick={() => setMenuOpen(false)}
                                    className="block rounded-xl px-3 py-2.5 font-semibold text-slate-700 hover:bg-site-soft aria-[current=page]:bg-site-accent/10 aria-[current=page]:text-site-accent-ink"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="mx-auto flex max-w-7xl gap-2 px-4 pb-4 sm:px-6">
                        <a
                            href={login.url()}
                            className={`flex-1 ${button.outline} py-2.5`}
                        >
                            Sign in
                        </a>
                        <Link
                            href="/start"
                            onClick={() => setMenuOpen(false)}
                            className={`flex-1 ${button.primary} py-2.5`}
                        >
                            Start free trial
                        </Link>
                    </div>
                </div>
            </header>
            <main id="main" className="flex-1">
                {children}
            </main>
            <footer className={`${darkBand} text-sm`}>
                <HeroBackdrop />
                {/* A wave from the page into the footer. Decorative. */}
                <svg
                    aria-hidden
                    viewBox="0 0 1440 60"
                    preserveAspectRatio="none"
                    className="block h-10 w-full text-white sm:h-14"
                >
                    <path
                        fill="currentColor"
                        d="M0 0h1440v22c-120 22-260 34-420 30C780 46 640 8 460 6 290 4 140 30 0 44Z"
                    />
                </svg>
                <div className="mx-auto max-w-7xl px-4 sm:px-6">
                    <div className="mt-8 flex flex-col items-center justify-between gap-6 rounded-3xl border border-white/10 bg-white/5 px-6 py-8 text-center backdrop-blur sm:px-10 lg:flex-row lg:text-left">
                        <div>
                            <p className="font-public-display text-2xl font-semibold text-white">
                                Questions before you start?
                            </p>
                            <p className="mt-1 text-slate-300">
                                Tell us about your business and we will show you
                                around.
                            </p>
                        </div>
                        <div className="flex flex-wrap justify-center gap-3">
                            <Link href="/contact" className={button.light}>
                                Talk to us
                            </Link>
                            <Link href="/start" className={button.outlineDark}>
                                Start free trial
                            </Link>
                        </div>
                    </div>
                </div>
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:grid-cols-2 sm:px-6 sm:py-16 lg:grid-cols-[2fr_1fr_1fr_1fr]">
                    <div>
                        <Brand inverted />
                        <p className="mt-5 max-w-xs leading-relaxed">
                            Business software for growing organizations: retail,
                            pharmacy, school, finance, HR and more, on one
                            platform.
                        </p>
                    </div>
                    <FooterColumn
                        title="Explore"
                        links={[
                            { title: 'ERP products', href: '/#erp' },
                            { title: 'Applications', href: '/#products' },
                            { title: 'How it works', href: '/#how-it-works' },
                            { title: 'Pricing', href: '/pricing' },
                        ]}
                    />
                    <FooterColumn
                        title="Get started"
                        links={[
                            { title: 'Start free trial', href: '/start' },
                            { title: 'Contact', href: '/contact' },
                        ]}
                    />
                    <FooterColumn
                        title="Account"
                        links={[
                            {
                                title: 'Sign in',
                                href: login.url(),
                                external: true,
                            },
                            {
                                title: 'Create an account',
                                href: register.url(),
                                external: true,
                            },
                        ]}
                    />
                </div>
                <div className="border-t border-white/10">
                    <p className="mx-auto max-w-7xl px-4 py-6 text-slate-400 sm:px-6">
                        © {new Date().getFullYear()} Miles Global Link Co. Ltd
                    </p>
                </div>
            </footer>
        </div>
    );
}

function FooterColumn({
    title,
    links,
}: {
    title: string;
    links: { title: string; href: string; external?: boolean }[];
}) {
    const linkClass =
        'inline-flex items-center gap-2 transition-colors before:h-px before:w-0 before:bg-site-accent-glow before:transition-[width] hover:text-white hover:before:w-3';

    return (
        <div>
            <h2 className="text-base font-semibold text-white">{title}</h2>
            <span
                aria-hidden
                className="site-gradient mt-3 block h-0.5 w-8 rounded-full"
            />
            <ul className="mt-5 space-y-3">
                {links.map((link) => (
                    <li key={link.href}>
                        {link.external ? (
                            <a href={link.href} className={linkClass}>
                                {link.title}
                            </a>
                        ) : (
                            <Link href={link.href} className={linkClass}>
                                {link.title}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
