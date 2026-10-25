import { Link, usePage } from "@inertiajs/react";
import {
    ArrowRight,
    Boxes,
    ChevronDown,
    Layers,
    Download,
    LayoutGrid,
    Menu,
    X,
} from "lucide-react";
import type { LucideIcon } from "lucide-react";
import { useEffect, useRef, useState } from "react";
import type { PropsWithChildren } from "react";
import { catalogIcon } from "@/sites/public/components/catalog";
import { milesProducts } from "@/sites/public/components/content";
import MetaPixel from "@/sites/public/components/meta-pixel";
import { demoUrl, services } from "@/sites/public/components/offerings";
import { HeroBackdrop } from "@/sites/public/components/page-hero";
import { SocialIcon, socialLinks } from "@/sites/public/components/social";
import { button, darkBand } from "@/sites/public/components/styles";
import { login, register } from "@/routes";

type MenuItem = {
    title: string;
    body?: string;
    href: string;
    icon: LucideIcon;
};

type NavEntry =
    | { title: string; href: string }
    | { title: string; id: string; items: MenuItem[] };

// Relative paths on the apex host; sign-in and registration live on the
// auth host, so those are plain links.
const products: MenuItem[] = [
    ...milesProducts.map((product) => ({
        title: product.title,
        href: `/${product.category === "erp" ? "erp" : "apps"}/${product.slug}`,
        icon: catalogIcon(product.icon),
    })),
    {
        title: "ERP Solutions",
        body: "Ready-made systems for your industry",
        href: "/erp",
        icon: Layers,
    },
    {
        title: "Business Applications",
        body: "Standalone apps that work together",
        href: "/apps",
        icon: LayoutGrid,
    },
    {
        title: "Desktop downloads",
        body: "Windows apps for the counter",
        href: "/downloads",
        icon: Download,
    },
];

const customSolutions: MenuItem[] = [
    {
        title: "All custom solutions",
        body: "Software built or adapted for you",
        href: "/services",
        icon: Boxes,
    },
    ...services.map((service) => ({
        title: service.title,
        href: `/services#${service.id}`,
        icon: service.icon,
    })),
];

const nav: NavEntry[] = [
    { title: "Home", href: "/" },
    { title: "Miles Cloud", href: "/platform" },
    { title: "Products", id: "products", items: products },
    { title: "Custom Solutions", id: "custom", items: customSolutions },
    { title: "Industries", href: "/industries" },
    { title: "About Us", href: "/about" },
    { title: "Contact", href: "/contact" },
];

/** A menu link without its fragment, for the current-page check. */
function pageOf(href: string): string {
    return href.split("#")[0];
}

function Brand({ inverted = false }: { inverted?: boolean }) {
    return (
        <Link
            href="/"
            aria-label="Miles Global Softwares home"
            className="flex shrink-0 items-center"
        >
            {/* The public site's logo; white lettering over the dark header and footer. */}
            <img
                src={
                    inverted
                        ? "/images/logo-site-light.png"
                        : "/images/logo-site.png"
                }
                alt="Miles Global Softwares"
                width={1000}
                height={265}
                className="h-10 w-auto sm:h-12"
            />
        </Link>
    );
}

/** The current page, for aria-current and the highlighted nav item. */
function useCurrentPath(): string {
    const { url } = usePage();

    return url.split("?")[0];
}

/**
 * A white header fixed over the page, with a hairline that turns into a
 * soft shadow and a slimmer bar once the page scrolls. Then the page, and
 * a deep navy footer (see styles.ts for the palette).
 */
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
            if (event.key === "Escape") {
                setOpen(false);
            }
        };
        const onPointerDown = (event: PointerEvent) => {
            if (!ref.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };

        window.addEventListener("keydown", onKeyDown);
        window.addEventListener("pointerdown", onPointerDown);

        return () => {
            window.removeEventListener("keydown", onKeyDown);
            window.removeEventListener("pointerdown", onPointerDown);
        };
    }, [open]);

    return (
        <li ref={ref} className="relative">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={`${id}-menu`}
                onClick={() => setOpen((value) => !value)}
                className={`inline-flex items-center gap-1 px-3 py-2 font-semibold 2xl:px-4 transition-colors hover:text-site-accent-ink ${current ? "text-site-accent-ink" : ""}`}
            >
                {title}
                <ChevronDown
                    className={`size-4 transition-transform ${open ? "rotate-180" : ""}`}
                    aria-hidden
                />
            </button>
            <div
                id={`${id}-menu`}
                hidden={!open}
                className="absolute top-full left-1/2 mt-3 max-h-[calc(100svh-6rem)] w-80 -translate-x-1/2 overflow-y-auto rounded-2xl border border-site-line bg-white p-2 text-site-ink shadow-xl shadow-site-night/10"
            >
                <ul>
                    {items.map(({ icon: Icon, ...item }) => (
                        <li key={item.href}>
                            <Link
                                href={item.href}
                                onClick={() => setOpen(false)}
                                aria-current={
                                    path === item.href ? "page" : undefined
                                }
                                className={`flex gap-3 rounded-xl whitespace-normal transition-colors hover:bg-site-soft aria-[current=page]:bg-site-accent/10 ${item.body ? "items-start p-3" : "items-center px-3 py-2"}`}
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
            if (event.key === "Escape") {
                setMenuOpen(false);
            }
        };

        window.addEventListener("keydown", onKeyDown);

        return () => window.removeEventListener("keydown", onKeyDown);
    }, [menuOpen]);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 24);

        onScroll();
        window.addEventListener("scroll", onScroll, { passive: true });

        return () => window.removeEventListener("scroll", onScroll);
    }, []);

    return (
        <div className="site-public flex min-h-screen flex-col overflow-x-clip bg-white font-public text-site-ink">
            <MetaPixel />
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-white focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <header
                className={`fixed inset-x-0 top-0 z-40 transition-colors duration-300 border-b border-site-line bg-white ${scrolled ? "shadow-[0_8px_24px_-16px_rgb(6_36_78/0.35)]" : ""}`}
            >
                <nav
                    aria-label="Main"
                    className={`mx-auto flex max-w-7xl items-center gap-6 px-4 transition-[height] duration-300 sm:px-6 ${scrolled ? "h-14 sm:h-16" : "h-16 sm:h-[4.5rem]"}`}
                >
                    <Brand />
                    <ul
                        className={`mx-auto hidden items-center gap-1 text-[15px] font-semibold whitespace-nowrap xl:flex text-slate-700`}
                    >
                        {nav.map((item) =>
                            "items" in item ? (
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
                                        aria-current={
                                            item.href === path
                                                ? "page"
                                                : undefined
                                        }
                                        className={`relative block px-3 py-2 transition-colors after:absolute after:inset-x-3 2xl:px-4 2xl:after:inset-x-4 after:bottom-0 after:h-0.5 after:origin-left after:scale-x-0 after:rounded-full after:transition-transform hover:after:scale-x-100 aria-[current=page]:after:scale-x-100 after:bg-site-accent hover:text-site-accent-ink aria-[current=page]:text-site-accent-ink`}
                                    >
                                        {item.title}
                                    </Link>
                                </li>
                            ),
                        )}
                    </ul>
                    <div className="ml-auto hidden items-center gap-5 whitespace-nowrap lg:flex xl:ml-0">
                        <a
                            href={login.url()}
                            className={`text-[15px] font-semibold transition-colors text-slate-700 hover:text-site-accent-ink`}
                        >
                            Sign in
                        </a>
                        <Link
                            href="/start"
                            className={`${button.primary} py-3`}
                        >
                            Get Started
                            <ArrowRight className="size-4" aria-hidden />
                        </Link>
                    </div>
                    <button
                        type="button"
                        className={`ml-auto inline-flex size-10 shrink-0 items-center justify-center rounded-full border lg:ml-0 xl:hidden border-site-line text-site-ink hover:bg-site-soft`}
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
                    className="max-h-[calc(100svh-3.5rem)] overflow-y-auto border-t border-site-line bg-white xl:hidden"
                >
                    <ul className="mx-auto max-w-7xl space-y-1 px-4 py-3 sm:px-6">
                        {nav.map((item) =>
                            "items" in item ? (
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
            <footer className={`${darkBand} text-sm`}>
                <HeroBackdrop />
                <div className="mx-auto grid max-w-7xl grid-cols-2 gap-x-6 gap-y-10 px-4 py-14 sm:px-6 sm:py-20 md:grid-cols-3 lg:grid-cols-[1.6fr_1fr_1fr_1fr_1fr]">
                    <div className="col-span-2 md:col-span-3 lg:col-span-1">
                        <Brand inverted />
                        <p className="mt-5 max-w-xs leading-relaxed">
                            Business software that works together: Miles
                            Products on Miles Cloud, and custom software for
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
                                    className="bg-white/10 text-white hover:bg-white/20"
                                />
                            ))}
                        </div>
                    </div>
                    {/* Slugs from resources/content/marketing-catalog.json. */}
                    <FooterColumn
                        title="Products"
                        links={[
                            ...products,
                            { title: "Coming soon", href: "/coming-soon" },
                        ]}
                    />
                    <FooterColumn
                        title="Industries"
                        links={[
                            {
                                title: "Pharmacies",
                                href: "/industries#pharmacies",
                            },
                            { title: "Shops", href: "/industries#shops" },
                            {
                                title: "Restaurants",
                                href: "/industries#restaurants",
                            },
                            { title: "Schools", href: "/industries#schools" },
                            { title: "NGOs", href: "/industries#ngos" },
                            { title: "All industries", href: "/industries" },
                        ]}
                    />
                    <FooterColumn
                        title="Company"
                        links={[
                            { title: "About Us", href: "/about" },
                            { title: "Miles Cloud", href: "/platform" },
                            { title: "Custom Solutions", href: "/services" },
                            { title: "Pricing", href: "/pricing" },
                            { title: "Contact", href: "/contact" },
                        ]}
                    />
                    <FooterColumn
                        title="Account"
                        links={[
                            { title: "Get started", href: "/start" },
                            { title: "Request a demo", href: demoUrl },
                            {
                                title: "Sign in",
                                href: login.url(),
                                external: true,
                            },
                            {
                                title: "Create an account",
                                href: register.url(),
                                external: true,
                            },
                            {
                                title: "Download desktop apps",
                                href: "/downloads",
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
        "inline-flex items-center py-0.5 transition-colors before:h-px before:w-0 before:bg-site-accent-glow before:transition-[width,margin] hover:text-white hover:before:mr-2 hover:before:w-3";

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

function MobileLink({
    href,
    path,
    onClick,
    children,
}: PropsWithChildren<{ href: string; path: string; onClick: () => void }>) {
    return (
        <Link
            href={href}
            aria-current={href === path ? "page" : undefined}
            onClick={onClick}
            className="block rounded-xl px-3 py-2.5 font-semibold text-slate-700 hover:bg-site-soft aria-[current=page]:bg-site-accent/10 aria-[current=page]:text-site-accent-ink"
        >
            {children}
        </Link>
    );
}
