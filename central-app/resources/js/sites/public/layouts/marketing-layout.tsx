import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, ChevronDown, Menu, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { PropsWithChildren } from 'react';
import { milesProducts } from '@/sites/public/components/content';
import Analytics from '@/sites/public/components/analytics';
import MetaPixel from '@/sites/public/components/meta-pixel';
import { demoUrl } from '@/sites/public/components/offerings';
import {
    SocialIcon,
    socialLinks,
    supportEmail,
} from '@/sites/public/components/social';
import { button, container } from '@/sites/public/components/styles';
import { login, register } from '@/routes';

type MenuItem = {
    title: string;
    body?: string;
    href: string;
    icon: LucideIcon;
};

/**
 * A link, or a menu of links. `section` lists the paths under which a link
 * stays highlighted (a product's own page under Products).
 */
type NavEntry =
    | { title: string; href: string; section?: string[] }
    | { title: string; id: string; items: MenuItem[] };

const nav: NavEntry[] = [
    { title: 'Home', href: '/' },
    { title: 'Tatook Workspace', href: '/platform' },
    {
        title: 'Products',
        href: '/products',
        section: ['/products', '/erp', '/apps'],
    },
    { title: 'Custom Solutions', href: '/services' },
    { title: 'Downloads', href: '/downloads' },
    { title: 'About Us', href: '/about' },
    { title: 'Contact', href: '/contact' },
];

/** A menu link without its fragment, for the current-page check. */
function pageOf(href: string): string {
    return href.split('#')[0];
}

/**
 * Where a header link stands for the current path: "page" on its own page,
 * "true" under its section (a product page under Products), else none.
 */
function currentOf(
    entry: { href: string; section?: string[] },
    path: string,
): 'page' | 'true' | undefined {
    if (entry.href === path) {
        return 'page';
    }

    return entry.section?.some((prefix) => path.startsWith(`${prefix}/`))
        ? 'true'
        : undefined;
}

/** The logo; `light` has white lettering, for dark bands. */
function Brand({ light = false }: { light?: boolean }) {
    return (
        <Link
            href="/"
            aria-label="Tatook home"
            className="flex shrink-0 items-center"
        >
            <img
                src={
                    light
                        ? '/images/logo-site-light.webp'
                        : '/images/logo-site.webp'
                }
                alt="Tatook"
                width={508}
                height={128}
                className="h-10 w-auto sm:h-11"
            />
        </Link>
    );
}

/** The current page, for aria-current and the highlighted nav item. */
function useCurrentPath(): string {
    const { url } = usePage();

    return url.split('?')[0];
}

/**
 * A menu in the header: a disclosure button over its links. Closes on
 * Escape, on a click elsewhere and when a link is followed.
 */
function NavMenu({
    title,
    id,
    items,
    path,
}: {
    title: string;
    id: string;
    items: MenuItem[];
    path: string;
}) {
    const [open, setOpen] = useState(false);
    const ref = useRef<HTMLLIElement>(null);
    const current = items.some(
        (item) =>
            path === pageOf(item.href) ||
            path.startsWith(`${pageOf(item.href)}/`),
    );

    useEffect(() => {
        if (!open) {
            return;
        }

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        const onPointerDown = (event: PointerEvent) => {
            if (!ref.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        window.addEventListener('keydown', onKeyDown);
        window.addEventListener('pointerdown', onPointerDown);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('pointerdown', onPointerDown);
        };
    }, [open]);

    return (
        <li ref={ref} className="relative">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={`${id}-menu`}
                onClick={() => setOpen((value) => !value)}
                className={`inline-flex items-center gap-1 px-2.5 py-2 transition-colors hover:text-site-ink xl:px-3 ${current ? 'text-site-ink' : ''}`}
            >
                {title}
                <ChevronDown
                    className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`}
                    aria-hidden
                />
            </button>
            <div
                id={`${id}-menu`}
                hidden={!open}
                className="absolute top-full left-1/2 mt-3 max-h-[calc(100svh-6rem)] w-80 -translate-x-1/2 overflow-y-auto rounded-xl border border-site-line bg-white p-2 text-site-ink shadow-xl shadow-site-night/10"
            >
                <ul>
                    {items.map(({ icon: Icon, ...item }) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                onClick={() => setOpen(false)}
                                aria-current={
                                    path === item.href ? 'page' : undefined
                                }
                                className={`flex gap-3 rounded-xl whitespace-normal transition-colors hover:bg-slate-50 aria-[current=page]:bg-site-accent/10 ${item.body ? 'items-start p-3' : 'items-center px-3 py-2'}`}
                            >
                                <span className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-site-accent/10 text-site-accent-ink">
                                    <Icon className="size-4" aria-hidden />
                                </span>
                                <span>
                                    <span className="block font-semibold">
                                        {item.title}
                                    </span>
                                    {item.body && (
                                        <span className="block text-sm font-normal text-slate-600">
                                            {item.body}
                                        </span>
                                    )}
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </li>
    );
}

export default function MarketingLayout({ children }: PropsWithChildren) {
    const { trialDays } = usePage<{ trialDays: number }>().props;
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
        const onScroll = () => setScrolled(window.scrollY > 24);

        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <div className="site-public flex min-h-screen flex-col overflow-x-clip bg-white font-public text-site-ink">
            <Analytics />
            <MetaPixel />
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <header
                className={`fixed inset-x-0 top-0 z-40 border-b bg-white transition-[border-color,box-shadow] duration-300 ${scrolled ? 'border-site-line shadow-[0_6px_20px_-14px_rgb(48_64_72/0.35)]' : 'border-transparent'}`}
            >
                <nav
                    aria-label="Main"
                    className={`${container} flex h-16 items-center gap-4 xl:gap-6`}
                >
                    <Brand />
                    <ul
                        className={`mx-auto hidden items-center text-sm font-medium whitespace-nowrap text-slate-600 lg:flex xl:gap-1 xl:text-[15px]`}
                    >
                        {nav.map((item) =>
                            'items' in item ? (
                                <NavMenu
                                    key={item.id}
                                    title={item.title}
                                    id={item.id}
                                    items={item.items}
                                    path={path}
                                />
                            ) : (
                                <li key={item.href}>
                                    <Link
                                        href={item.href}
                                        aria-current={currentOf(item, path)}
                                        className={`relative block px-2.5 py-2 transition-colors after:absolute after:inset-x-2.5 after:-bottom-[1.0625rem] after:h-0.5 after:scale-x-0 after:bg-site-highlight after:transition-transform hover:text-site-ink aria-[current=page]:font-semibold aria-[current=page]:text-site-ink aria-[current=page]:after:scale-x-100 aria-[current=true]:font-semibold aria-[current=true]:text-site-ink aria-[current=true]:after:scale-x-100 xl:px-3 xl:after:inset-x-3`}
                                    >
                                        {item.title}
                                    </Link>
                                </li>
                            ),
                        )}
                    </ul>
                    <div className="ml-auto hidden items-center gap-4 whitespace-nowrap lg:ml-0 lg:flex xl:gap-5">
                        <a
                            href={login.url()}
                            className={`text-sm font-medium text-slate-600 transition-colors hover:text-site-ink xl:text-[15px]`}
                        >
                            Sign in
                        </a>
                        <Link
                            href="/start"
                            className={`${button.primary} py-2.5 lg:px-4`}
                        >
                            Get Started
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                    <button
                        type="button"
                        className={`ml-auto inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-site-line text-site-ink hover:bg-slate-50 lg:hidden`}
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
                    className="max-h-[calc(100svh-4rem)] overflow-y-auto border-t border-site-line bg-white lg:hidden"
                >
                    <ul className="mx-auto max-w-7xl space-y-1 px-4 py-3 sm:px-6">
                        {nav.map((item) =>
                            'items' in item ? (
                                <li key={item.id}>
                                    <p className="px-3 pt-2 pb-1 text-xs font-bold tracking-wider text-slate-500 uppercase">
                                        {item.title}
                                    </p>
                                    <ul className="space-y-1">
                                        {item.items.map((link) => (
                                            <li key={link.href}>
                                                <MobileLink
                                                    href={link.href}
                                                    path={path}
                                                    onClick={() =>
                                                        setMenuOpen(false)
                                                    }
                                                >
                                                    {link.title}
                                                </MobileLink>
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ) : (
                                <li key={item.href}>
                                    <MobileLink
                                        href={item.href}
                                        section={item.section}
                                        path={path}
                                        onClick={() => setMenuOpen(false)}
                                    >
                                        {item.title}
                                    </MobileLink>
                                </li>
                            ),
                        )}
                    </ul>
                    <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 pb-4 sm:px-6">
                        <Link
                            href="/start"
                            onClick={() => setMenuOpen(false)}
                            className={`${button.primary} py-2.5`}
                        >
                            Get Started: {trialDays}-day free trial
                        </Link>
                        <a
                            href={login.url()}
                            className={`${button.outline} py-2.5`}
                        >
                            Sign in
                        </a>
                    </div>
                </div>
            </header>
            <main id="main" className="flex-1">
                {children}
            </main>
            <footer className="site-dark bg-site-navy text-sm text-slate-300">
                <div
                    className={`${container} grid grid-cols-2 gap-x-6 gap-y-10 py-14 sm:py-16 md:grid-cols-3 lg:grid-cols-[1.6fr_1fr_1fr_1fr]`}
                >
                    <div className="col-span-2 md:col-span-3 lg:col-span-1">
                        <Brand light />
                        <p className="mt-5 max-w-xs leading-relaxed">
                            Bring it all together: Tatook
                            products in one workspace, and custom software for
                            web, desktop and mobile.
                        </p>
                        <Link
                            href="/contact"
                            className="mt-6 inline-flex items-center gap-2 font-semibold text-white hover:underline"
                        >
                            Questions? Talk to us
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                        <div className="mt-5 flex gap-3">
                            {socialLinks.map((link) => (
                                <SocialIcon
                                    key={link.name}
                                    link={link}
                                    className="bg-white/10 text-white ring-1 ring-white/20 hover:bg-white/20 hover:ring-white/40"
                                />
                            ))}
                        </div>
                    </div>
                    {/* Slugs from resources/content/marketing-catalog.json. */}
                    <FooterColumn
                        title="Products"
                        links={[
                            ...milesProducts.map((product) => ({
                                title: product.title,
                                href: `/${product.category === 'erp' ? 'erp' : 'apps'}/${product.slug}`,
                            })),
                            { title: 'All products', href: '/products' },
                            { title: 'ERP Solutions', href: '/products#erp' },
                            {
                                title: 'Business Applications',
                                href: '/products#apps',
                            },
                            { title: 'Downloads', href: '/downloads' },
                            { title: 'Coming soon', href: '/coming-soon' },
                        ]}
                    />
                    <FooterColumn
                        title="Company"
                        links={[
                            { title: 'About Us', href: '/about' },
                            { title: 'Tatook Workspace', href: '/platform' },
                            { title: 'Custom Solutions', href: '/services' },
                            { title: 'Pricing', href: '/pricing' },
                            { title: 'Contact', href: '/contact' },
                        ]}
                    />
                    <FooterColumn
                        title="Account"
                        links={[
                            { title: 'Get started', href: '/start' },
                            { title: 'Request a demo', href: demoUrl },
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
                            {
                                title: 'Download the apps',
                                href: '/downloads',
                            },
                        ]}
                    />
                </div>
                <div className="border-t border-white/15">
                    <div
                        className={`${container} flex flex-wrap items-center justify-between gap-x-6 gap-y-3 py-6`}
                    >
                        <p className="text-slate-400">
                            Tatook is a product of Miles Global Link Co. Ltd · ©{' '}
                            {new Date().getFullYear()}
                        </p>
                        <div className="flex flex-wrap gap-x-6 gap-y-2">
                            <Link
                                href="/privacy"
                                className="hover:text-white hover:underline"
                            >
                                Privacy policy
                            </Link>
                            <Link
                                href="/terms"
                                className="hover:text-white hover:underline"
                            >
                                Terms of service
                            </Link>
                            <a
                                href={`mailto:${supportEmail}`}
                                className="hover:text-white hover:underline"
                            >
                                {supportEmail}
                            </a>
                        </div>
                    </div>
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
    const linkClass = 'inline-flex py-0.5 transition-colors hover:text-white';

    return (
        <div>
            <h2 className="text-sm font-semibold text-white">{title}</h2>
            <ul className="mt-4 space-y-2.5">
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

function MobileLink({
    href,
    section,
    path,
    onClick,
    children,
}: PropsWithChildren<{
    href: string;
    section?: string[];
    path: string;
    onClick: () => void;
}>) {
    return (
        <Link
            href={href}
            aria-current={currentOf({ href, section }, path)}
            onClick={onClick}
            className="block rounded-lg px-3 py-2.5 font-semibold text-slate-700 hover:bg-slate-50 aria-[current=page]:bg-site-accent/10 aria-[current=page]:text-site-accent-ink aria-[current=true]:bg-site-accent/10 aria-[current=true]:text-site-accent-ink"
        >
            {children}
        </Link>
    );
}
