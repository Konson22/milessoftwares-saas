import { Link } from '@inertiajs/react';
import { ArrowRight, Menu, X } from 'lucide-react';
import { useState } from 'react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import { button } from '@/sites/public/components/styles';
import { login, register } from '@/routes';

// Relative paths on the apex host; sign-in and registration live on the
// auth host, so those are plain links.
const nav = [
    { title: 'Products', href: '/#products' },
    { title: 'Pricing', href: '/pricing' },
    { title: 'Book a demo', href: '/demo' },
    { title: 'Contact', href: '/contact' },
];

function Brand() {
    return (
        <Link
            href="/"
            className="flex items-center gap-2.5 font-semibold tracking-tight text-white"
        >
            {/* Decorative: the name beside it is the link text. */}
            <AppLogoIcon className="size-8" alt="" />
            Miles Global Softwares
        </Link>
    );
}

/**
 * Header and footer are dark in both themes so they frame the dark band
 * each page opens with; the content between follows the visitor's theme.
 */
export default function MarketingLayout({ children }: PropsWithChildren) {
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="flex min-h-screen flex-col overflow-x-clip bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-background focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <header className="sticky top-0 z-40 border-b border-white/10 bg-zinc-950 text-zinc-300">
                <nav
                    aria-label="Main"
                    className="mx-auto flex h-16 max-w-6xl items-center gap-10 px-4"
                >
                    <Brand />
                    <ul className="hidden gap-1 text-sm md:flex">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    className="rounded-md px-3 py-2 transition-colors hover:bg-white/5 hover:text-white"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="ml-auto hidden items-center gap-2 text-sm md:flex">
                        <a
                            href={login.url()}
                            className="rounded-md px-3 py-2 transition-colors hover:bg-white/5 hover:text-white"
                        >
                            Sign in
                        </a>
                        <a
                            href={register.url()}
                            className="group inline-flex items-center gap-1.5 rounded-lg bg-white px-4 py-2 font-medium text-zinc-950 transition-colors hover:bg-zinc-200"
                        >
                            Get started
                            <ArrowRight
                                className="size-3.5 transition-transform group-hover:translate-x-0.5"
                                aria-hidden
                            />
                        </a>
                    </div>
                    <button
                        type="button"
                        className="ml-auto rounded-md p-2 text-white hover:bg-white/10 md:hidden"
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
                    className="border-t border-white/10 md:hidden"
                >
                    <ul className="mx-auto max-w-6xl space-y-1 px-4 py-3">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    onClick={() => setMenuOpen(false)}
                                    className="block rounded-md px-3 py-2.5 hover:bg-white/5 hover:text-white"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="mx-auto flex max-w-6xl gap-2 px-4 pb-4 text-sm">
                        <a
                            href={login.url()}
                            className={`flex-1 ${button.outlineDark} py-2.5`}
                        >
                            Sign in
                        </a>
                        <a
                            href={register.url()}
                            className={`flex-1 ${button.light} py-2.5`}
                        >
                            Get started
                        </a>
                    </div>
                </div>
            </header>
            <main id="main" className="flex-1">
                {children}
            </main>
            <footer className="relative isolate overflow-hidden bg-zinc-950 text-sm text-zinc-400">
                <div
                    aria-hidden
                    className="absolute -bottom-40 left-1/2 -z-10 h-80 w-3xl -translate-x-1/2 rounded-full bg-primary/15 blur-[120px]"
                />
                <div className="mx-auto grid max-w-6xl gap-10 px-4 py-16 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="lg:col-span-2">
                        <Brand />
                        <p className="mt-4 max-w-xs">
                            Business software for growing organizations, on one
                            account and one subscription.
                        </p>
                    </div>
                    <FooterColumn
                        title="Explore"
                        links={[
                            { title: 'Applications', href: '/#products' },
                            { title: 'Pricing', href: '/pricing' },
                            { title: 'Book a demo', href: '/demo' },
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
                                title: 'Create a workspace',
                                href: register.url(),
                                external: true,
                            },
                        ]}
                    />
                </div>
                <div className="border-t border-white/10">
                    <p className="mx-auto max-w-6xl px-4 py-6 text-zinc-500">
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
    const linkClass = 'transition-colors hover:text-white';

    return (
        <div>
            <h2 className="font-medium text-white">{title}</h2>
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
