import { AppContent } from '@/shared/components/app-content';
import { AppShell } from '@/shared/components/app-shell';
import { AppSidebar } from '@/shared/components/app-sidebar';
import { AppSidebarHeader } from '@/shared/components/app-sidebar-header';
import type { AppLayoutProps } from '@/shared/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
