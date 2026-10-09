import { Head, Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { login, register } from '@/routes';

// Phase 1 placeholder. The catalog-driven marketing site arrives in Phase 7.
export default function MarketingHome() {
    return (
        <>
            <Head title="Business software for growing organizations" />
            <div className="flex min-h-screen flex-col bg-background text-foreground">
                <header className="border-b">
                    <nav className="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
                        <span className="flex items-center gap-2 font-semibold">
                            <AppLogoIcon className="size-6 fill-current text-primary" />
                            Miles Global Softwares
                        </span>
                        <div className="flex items-center gap-2 text-sm">
                            <Link
                                href={login()}
                                className="rounded-md px-4 py-2 hover:bg-accent"
                            >
                                Sign in
                            </Link>
                            <Link
                                href={register()}
                                className="rounded-md bg-primary px-4 py-2 text-primary-foreground hover:bg-primary/90"
                            >
                                Get started
                            </Link>
                        </div>
                    </nav>
                </header>
                <main className="mx-auto flex w-full max-w-6xl flex-1 flex-col justify-center px-4 py-16">
                    <p className="text-sm font-medium text-primary">
                        Miles Global Link Co. Ltd
                    </p>
                    <h1 className="mt-3 max-w-2xl text-4xl font-semibold tracking-tight">
                        One account for all your business software.
                    </h1>
                    <p className="mt-4 max-w-xl text-muted-foreground">
                        Retail, pharmacy, school, finance, HR and payroll
                        applications sharing a single identity, organization and
                        subscription platform.
                    </p>
                </main>
                <footer className="border-t py-6 text-center text-sm text-muted-foreground">
                    © {new Date().getFullYear()} Miles Global Link Co. Ltd
                </footer>
            </div>
        </>
    );
}
