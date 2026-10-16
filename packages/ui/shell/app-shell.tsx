import { usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '../lib/utils';
import { Breadcrumbs } from './breadcrumbs';
import type { Crumb } from './navigation';

/**
 * The frame of every signed-in page: the navy sidebar (a drawer below lg),
 * a sticky top bar with breadcrumbs and actions, and the page on the grey
 * canvas. The sidebar is rendered by the app, which knows its sections;
 * onNavigate closes the drawer.
 */
export function AppShell({
    sidebar,
    crumbs,
    actions,
    flash,
    children,
}: {
    sidebar: (onNavigate: () => void) => ReactNode;
    crumbs: Crumb[];
    /** Top bar, right side: switchers, search, the user menu. */
    actions?: ReactNode;
    /** A one-line success message from the last action. */
    flash?: string | null;
    children: ReactNode;
}) {
    const { url } = usePage();
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const closeSidebar = useCallback(() => setSidebarOpen(false), []);

    useEffect(() => {
        closeSidebar();
    }, [url, closeSidebar]);

    useEffect(() => {
        if (!sidebarOpen) {
            return;
        }

        function onKey(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                closeSidebar();
            }
        }

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [sidebarOpen, closeSidebar]);

    return (
        <div className="flex min-h-screen bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:z-50 focus:rounded focus:bg-card focus:px-3 focus:py-2"
            >
                Skip to content
            </a>

            {/* Desktop sidebar */}
            <aside className="sticky top-0 hidden h-screen w-64 shrink-0 lg:block">
                {sidebar(closeSidebar)}
            </aside>

            {/* Mobile sidebar drawer */}
            <div
                className={cn(
                    'fixed inset-0 z-40 lg:hidden',
                    sidebarOpen ? 'pointer-events-auto' : 'pointer-events-none',
                )}
                aria-hidden={!sidebarOpen}
                inert={!sidebarOpen}
            >
                <button
                    type="button"
                    aria-label="Close navigation"
                    onClick={closeSidebar}
                    className={cn(
                        'absolute inset-0 bg-black/40 transition-opacity',
                        sidebarOpen ? 'opacity-100' : 'opacity-0',
                    )}
                />
                <aside
                    className={cn(
                        'absolute inset-y-0 left-0 w-72 max-w-[85vw] shadow-xl transition-transform duration-200',
                        sidebarOpen ? 'translate-x-0' : '-translate-x-full',
                    )}
                >
                    {sidebar(closeSidebar)}
                </aside>
            </div>

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b bg-card/95 px-4 backdrop-blur supports-backdrop-filter:bg-card/80">
                    <button
                        type="button"
                        onClick={() => setSidebarOpen(true)}
                        aria-label="Open navigation"
                        className="grid size-9 place-items-center rounded-md border text-muted-foreground hover:bg-accent lg:hidden"
                    >
                        <Menu className="size-4" aria-hidden />
                    </button>
                    <div className="min-w-0 flex-1">
                        <Breadcrumbs crumbs={crumbs} />
                    </div>
                    {actions && (
                        <div className="flex items-center gap-2 sm:gap-3">
                            {actions}
                        </div>
                    )}
                </header>
                <main id="main" className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {flash && (
                        <p
                            role="status"
                            className="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800"
                        >
                            {flash}
                        </p>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
