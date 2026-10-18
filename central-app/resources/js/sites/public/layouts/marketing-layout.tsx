import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Menu, X } from 'lucide-react';
import { useState } from 'react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import { button } from '@/sites/public/components/styles';
import { login, register } from '@/routes';

// Relative paths on the apex host; sign-in and registration live on the
// auth host, so those are plain links.
const nav = [
    { title: 'ERP products', href: '/#erp' },
    { title: 'Applications', href: '/#products' },
    { title: 'How it works', href: '/#how-it-works' },
    { title: 'Pricing', href: '/pricing' },
    { title: 'Contact', href: '/contact' },
];

function Brand() {
    return (
        <Link
            href="/"
            className="flex items-center gap-2.5 text-lg font-bold tracking-tight text-site-ink"
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
 * The public site is always light (see HandleAppearance): a slim dark
 * announcement bar, a white header, white and cool-grey sections, and a
 * cool-grey footer.
 */
export default function MarketingLayout({ children }: PropsWithChildren) {
    const [menuOpen, setMenuOpen] = useState(false);
    const path = useCurrentPath();

    return (
        <div className="site-public flex min-h-screen flex-col overflow-x-clip bg-white font-public text-site-ink">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <p className="bg-site-ink px-4 py-2.5 text-center text-sm text-slate-300">
                Every application starts with a free trial, no payment details
                needed.{' '}
                <Link
                    href="/start"
                    className="inline-flex items-center gap-1 font-semibold text-white hover:underline"
                >
                    Start yours
                    <ArrowRight className="size-3.5" aria-hidden />
                </Link>
            </p>
            <header className="sticky top-0 z-40 border-b border-site-line bg-white/90 backdrop-blur-lg">
                <nav
                    aria-label="Main"
                    className="mx-auto flex h-18 max-w-7xl items-center gap-8 px-4 sm:px-6"
                >
                    <Brand />
                    <ul className="mx-auto hidden gap-1 text-[15px] font-medium text-slate-600 lg:flex">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    aria-current={
                                        item.href === path ? 'page' : undefined
                                    }
                                    className="rounded-lg px-3.5 py-2 transition-colors hover:text-brand-blue aria-[current=page]:text-brand-blue"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="hidden items-center gap-3 lg:flex">
                        <a
                            href={login.url()}
                            className={`${button.outline} py-2.5`}
                        >
                            Sign in
                        </a>
                        <Link
                            href="/start"
                            aria-current={
                                path === '/start' ? 'page' : undefined
                            }
                            className={`${button.primary} py-2.5`}
                        >
                            Start free trial
                        </Link>
                    </div>
                    <button
                        type="button"
                        className="ml-auto inline-flex size-10 items-center justify-center rounded-lg border border-site-line text-site-ink hover:bg-site-soft lg:hidden"
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
                    className="border-t border-site-line lg:hidden"
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
                                    className="block rounded-lg px-3 py-2.5 font-medium text-slate-700 hover:bg-site-soft aria-[current=page]:bg-brand-blue/10 aria-[current=page]:text-brand-blue"
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
            <footer className="border-t border-site-line bg-site-soft text-sm text-slate-600">
                <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:grid-cols-2 sm:px-6 lg:grid-cols-[2fr_1fr_1fr_1fr]">
                    <div>
                        <Brand />
                        <p className="mt-5 max-w-xs leading-relaxed">
                            Business software for growing organizations, on one
                            account and one subscription.
                        </p>
                        <Link
                            href="/start"
                            className={`mt-6 ${button.primary} py-2.5`}
                        >
                            Start free trial
                        </Link>
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
                <div className="border-t border-site-line">
                    <p className="mx-auto max-w-7xl px-4 py-6 text-slate-500 sm:px-6">
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
    const linkClass = 'transition-colors hover:text-brand-blue';

    return (
        <div>
            <h2 className="font-semibold text-site-ink">{title}</h2>
            <ul className="mt-4 space-y-3">
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
