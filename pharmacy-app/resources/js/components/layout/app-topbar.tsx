import { Menu } from 'lucide-react';
import type { NavGroup, NavItem } from '@/lib/navigation';
import { BranchSwitcher } from '@/components/layout/branch-switcher';
import { Breadcrumbs } from '@/components/layout/breadcrumbs';
import { QuickSearch } from '@/components/layout/quick-search';
import { UserMenu } from '@/components/layout/user-menu';

/**
 * The sticky header: breadcrumbs, branch, search, and who is signed in.
 */
export function AppTopbar({
    groups,
    active,
    onOpenSidebar,
}: {
    groups: NavGroup[];
    active: { group: NavGroup; item: NavItem } | null;
    onOpenSidebar: () => void;
}) {
    return (
        <header className="sticky top-0 z-20 flex h-14 shrink-0 items-center gap-3 border-b bg-card/95 px-4 backdrop-blur supports-backdrop-filter:bg-card/80">
            <button
                type="button"
                onClick={onOpenSidebar}
                aria-label="Open navigation"
                className="grid size-9 place-items-center rounded-md border text-muted-foreground hover:bg-accent lg:hidden"
            >
                <Menu className="size-4" aria-hidden />
            </button>
            <div className="min-w-0 flex-1">
                <Breadcrumbs active={active} />
            </div>
            <div className="flex items-center gap-2 sm:gap-3">
                <BranchSwitcher />
                <QuickSearch groups={groups} />
                <UserMenu />
            </div>
        </header>
    );
}
