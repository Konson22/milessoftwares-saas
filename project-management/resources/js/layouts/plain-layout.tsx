import { usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { AppLogo } from '@/components/app-logo';

/**
 * Outside a workspace one belongs to: a donor's invitation link opened on the
 * donor's workspace. A top bar with the logo, linking to the website.
 */
export default function PlainLayout({ children }: PropsWithChildren) {
    const { product, flash } = usePage().props;

    return (
        <div className="min-h-screen bg-background text-foreground">
            <header className="flex items-center justify-between gap-4 border-b bg-card px-4 py-3 sm:px-6">
                <a href={product.url} className="flex items-center">
                    <AppLogo className="h-8 w-auto" />
                </a>
            </header>
            <main id="main" className="mx-auto w-full max-w-xl px-4 py-8">
                {flash.warning && (
                    <p
                        role="alert"
                        className="mb-4 border border-brand-200 bg-brand-50 px-3 py-2 text-sm text-brand-900 dark:border-brand-900 dark:bg-brand-950 dark:text-brand-100"
                    >
                        {flash.warning}
                    </p>
                )}
                {children}
            </main>
        </div>
    );
}
