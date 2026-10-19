import { usePage } from '@inertiajs/react';
import { ArrowLeft, Landmark, Store } from 'lucide-react';
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
import { retailNavigation } from '@/lib/retail-navigation';
import { home } from '@/routes/retail';
import { update as chooseBranch } from '@/routes/retail/branch';
import { logout } from '@/routes/platform/retail';
import { index as products } from '@/routes/retail/products';
import { index as stock } from '@/routes/retail/stock';

/**
 * Retail in the shared shell: its sections, the branch being worked
 * in, and Finance (where its sales, payments and bills are booked).
 */
export default function RetailLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { flash, branch, product, workspace, user } = props;
    const can = useCan();
    const canHere = useCanHere();
    const groups = visibleNavigation(
        can,
        canHere,
        branch !== null,
        retailNavigation,
    );
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
            ...(can('retail.products.view')
                ? [
                      {
                          key: 'products',
                          label: `Products matching “${term}”`,
                          url: products.url({ query: { search: term } }),
                      },
                  ]
                : []),
            ...(can('retail.stock.view')
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
                    <BranchSwitcher update={chooseBranch} />
                    <QuickSearch
                        groups={groups}
                        searches={searches}
                        placeholder="Go to a section, or search products…"
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
                                label: 'Sign out of Retail',
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
                        icon: Store,
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
