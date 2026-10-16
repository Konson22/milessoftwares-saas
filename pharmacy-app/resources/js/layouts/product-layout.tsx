import { usePage } from '@inertiajs/react';
import { ArrowLeft, Landmark, Pill } from 'lucide-react';
import { useCallback } from 'react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import type { ShellNavGroup } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import type { QuickSearchTarget } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { BranchSwitcher } from '@/components/layout/branch-switcher';
import { useCan, useCanHere } from '@/lib/access';
import { visibleNavigation } from '@/lib/navigation';
import { home } from '@/routes';
import { index as medicines } from '@/routes/medicines';
import { logout } from '@/routes/platform';
import { index as stock } from '@/routes/stock';

/**
 * The pharmacy in the shared shell: its sections, the branch being worked
 * in, and Finance (where its sales, payments and bills are booked).
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { flash, branch, product, workspace, user } = props;
    const can = useCan();
    const canHere = useCanHere();
    const groups = visibleNavigation(can, canHere, branch !== null);
    const active = activeItem(groups, url, home());
    const shared: ShellNavGroup[] = workspace
        ? [
              {
                  title: 'Shared',
                  items: [
                      {
                          title: 'Finance',
                          href: new URL('finance/', workspace.url).toString(),
                          icon: Landmark,
                          external: true,
                      },
                  ],
              },
          ]
        : [];

    const searches = useCallback(
        (term: string): QuickSearchTarget[] => [
            ...(can('pharmacy.medicines.view')
                ? [
                      {
                          key: 'medicines',
                          label: `Medicines matching “${term}”`,
                          url: medicines.url({ query: { search: term } }),
                      },
                  ]
                : []),
            ...(can('pharmacy.stock.view')
                ? [
                      {
                          key: 'stock',
                          label: `Stock of “${term}”`,
                          url: stock.url({ query: { search: term } }),
                      },
                  ]
                : []),
        ],
        [can],
    );

    return (
        <AppShell
            flash={flash.success}
            crumbs={crumbsFor(
                { label: product.name, href: home().url },
                active,
                home(),
            )}
            actions={
                <>
                    <BranchSwitcher />
                    <QuickSearch
                        groups={groups}
                        searches={searches}
                        placeholder="Go to a section, or search medicines…"
                    />
                    {user && (
                        <UserMenu
                            user={user}
                            subtitle={workspace?.name}
                            links={
                                workspace
                                    ? [
                                          {
                                              label: `${workspace.name} home`,
                                              href: workspace.url,
                                              icon: ArrowLeft,
                                              external: true,
                                          },
                                      ]
                                    : []
                            }
                            signOut={{
                                label: 'Sign out of the pharmacy',
                                ...logout.form(),
                            }}
                        />
                    )}
                </>
            }
            sidebar={(onNavigate) => (
                <ShellSidebar
                    label={product.name}
                    back={
                        workspace
                            ? {
                                  label: `${workspace.name} home`,
                                  href: workspace.url,
                              }
                            : undefined
                    }
                    brand={{
                        title: product.name,
                        subtitle: workspace?.name,
                        icon: Pill,
                        href: home(),
                    }}
                    groups={[...groups, ...shared]}
                    active={active?.item ?? null}
                    onNavigate={onNavigate}
                />
            )}
        >
            {children}
        </AppShell>
    );
}
