import { Link } from '@inertiajs/react';
import { Menu, X } from 'lucide-react';
import { useState } from 'react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/shared/components/app-logo-icon';
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
        <Link href="/" className="flex items-center gap-2 font-semibold">
            {/* Decorative: the name beside it is the link text. */}
            <AppLogoIcon className="size-7" alt="" />
            Miles Global Softwares
        </Link>
    );
}

export default function MarketingLayout({ children }: PropsWithChildren) {
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-background focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <header className="sticky top-0 z-40 border-b bg-background/90 backdrop-blur supports-[backdrop-filter]:bg-background/75">
                <nav
                    aria-label="Main"
                    className="mx-auto flex max-w-6xl items-center gap-8 px-4 py-3"
                >
                    <Brand />
                    <ul className="hidden gap-6 text-sm md:flex">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    className="text-muted-foreground transition-colors hover:text-foreground"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="ml-auto hidden items-center gap-2 text-sm md:flex">
                        <a
                            href={login.url()}
                            className="rounded-md px-4 py-2 hover:bg-accent"
                        >
                            Sign in
                        </a>
                        <a
                            href={register.url()}
                            className="rounded-md bg-primary px-4 py-2 font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            Get started
                        </a>
                    </div>
                    <button
                        type="button"
                        className="ml-auto rounded-md p-2 hover:bg-accent md:hidden"
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
                    className="border-t md:hidden"
                >
                    <ul className="mx-auto max-w-6xl space-y-1 px-4 py-3">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    onClick={() => setMenuOpen(false)}
                                    className="block rounded-md px-3 py-2 hover:bg-accent"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="mx-auto flex max-w-6xl gap-2 px-4 pb-4 text-sm">
                        <a
                            href={login.url()}
                            className="flex-1 rounded-md border px-4 py-2 text-center hover:bg-accent"
                        >
                            Sign in
                        </a>
                        <a
                            href={register.url()}
                            className="flex-1 rounded-md bg-primary px-4 py-2 text-center font-medium text-primary-foreground hover:bg-primary/90"
                        >
                            Get started
                        </a>
                    </div>
                </div>
            </header>
            <main id="main" className="flex-1">
                {children}
            </main>
            <footer className="border-t bg-muted/30 text-sm">
                <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="lg:col-span-2">
                        <Brand />
                        <p className="mt-3 max-w-xs text-muted-foreground">
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
                <div className="border-t">
                    <p className="mx-auto max-w-6xl px-4 py-6 text-muted-foreground">
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
    return (
        <div>
            <h2 className="font-medium">{title}</h2>
            <ul className="mt-3 space-y-2 text-muted-foreground">
                {links.map((link) => (
                    <li key={link.href}>
                        {link.external ? (
                            <a
                                href={link.href}
                                className="hover:text-foreground"
                            >
                                {link.title}
                            </a>
                        ) : (
                            <Link
                                href={link.href}
                                className="hover:text-foreground"
                            >
                                {link.title}
                            </Link>
                        )}
                    </li>
                ))}
            </ul>
        </div>
    );
}
