import { Breadcrumbs } from '@/shared/components/breadcrumbs';
import { SidebarTrigger } from '@/shared/components/ui/sidebar';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/shared/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    return (
        <header className="flex h-12 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear">
            <div className="flex items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
