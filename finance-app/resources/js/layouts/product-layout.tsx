import { usePage } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useState,
    type PropsWithChildren,
} from 'react';
import { AppSidebar } from '@/components/layout/app-sidebar';
import { AppTopbar } from '@/components/layout/app-topbar';
import { useCan } from '@/lib/access';
import { activeItem, visibleNavigation } from '@/lib/navigation';
import { cn } from '@/lib/utils';

/**
 * The Finance shell: navy sidebar, top bar, and the page on a light canvas.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { flash } = props;
    const can = useCan();
    const groups = visibleNavigation(can);
    const active = activeItem(groups, url);
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
                <AppSidebar groups={groups} active={active?.item ?? null} />
            </aside>

            {/* Mobile sidebar drawer */}
            <div
                className={cn(
                    'fixed inset-0 z-40 lg:hidden',
                    sidebarOpen ? 'pointer-events-auto' : 'pointer-events-none',
                )}
                aria-hidden={!sidebarOpen}
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
                    <AppSidebar
                        groups={groups}
                        active={active?.item ?? null}
                        onNavigate={closeSidebar}
                    />
                </aside>
            </div>

            <div className="flex min-w-0 flex-1 flex-col">
                <AppTopbar
                    groups={groups}
                    active={active}
                    onOpenSidebar={() => setSidebarOpen(true)}
                />
                <main id="main" className="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {flash.success && (
                        <p
                            role="status"
                            className="mb-5 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800"
                        >
                            {flash.success}
                        </p>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
