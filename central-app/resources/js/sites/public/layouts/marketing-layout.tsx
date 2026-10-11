import { Link } from '@inertiajs/react';
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

export default function MarketingLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded focus:bg-background focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <header className="border-b">
                <nav
                    aria-label="Main"
                    className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-4"
                >
                    <Link
                        href="/"
                        className="flex items-center gap-2 font-semibold"
                    >
                        <AppLogoIcon
                            className="size-6 fill-current text-primary"
                            aria-hidden
                        />
                        Miles Global Softwares
                    </Link>
                    <ul className="flex flex-wrap gap-4 text-sm">
                        {nav.map((item) => (
                            <li key={item.href}>
                                <Link
                                    href={item.href}
                                    className="hover:underline"
                                >
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </ul>
                    <div className="ml-auto flex items-center gap-2 text-sm">
                        <a
                            href={login.url()}
                            className="rounded-md px-4 py-2 hover:bg-accent"
                        >
                            Sign in
                        </a>
                        <a
                            href={register.url()}
                            className="rounded-md bg-primary px-4 py-2 text-primary-foreground hover:bg-primary/90"
                        >
                            Get started
                        </a>
                    </div>
                </nav>
            </header>
            <main id="main" className="flex-1">
                {children}
            </main>
            <footer className="border-t py-8 text-sm text-muted-foreground">
                <div className="mx-auto flex max-w-6xl flex-wrap justify-between gap-4 px-4">
                    <p>
                        © {new Date().getFullYear()} Miles Global Link Co. Ltd
                    </p>
                    <ul className="flex gap-4">
                        <li>
                            <Link href="/pricing" className="hover:underline">
                                Pricing
                            </Link>
                        </li>
                        <li>
                            <Link href="/contact" className="hover:underline">
                                Contact
                            </Link>
                        </li>
                    </ul>
                </div>
            </footer>
        </div>
    );
}
