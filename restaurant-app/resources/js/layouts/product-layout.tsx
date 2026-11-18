import { usePage } from '@inertiajs/react';
import { ArrowLeft, Landmark, UtensilsCrossed } from 'lucide-react';
import { useCallback } from 'react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import type { ShellNavGroup } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import type { QuickSearchTarget } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { BranchSwitcher } from '@/components/branch-switcher';
import { useCan, useCanHere } from '@/lib/access';
import {
    branchNavigation,
    managementNavigation,
    visible,
} from '@/lib/navigation';
import { home, overview, start } from '@/routes';
import { index as menu } from '@/routes/menu';
import { logout } from '@/routes/platform';
import { setUrlDefaults } from '@/wayfinder';

/**
 * The restaurant in the shell every Miles app shares (@miles/ui), as one of
 * two workspaces, each with its own sidebar and header:
 *
 * - management (area "management"): the whole restaurant, for those who
 *   oversee every branch; branches open from it in new tabs;
 * - a branch (area "branch"): one branch's service, its name in the header.
 *
 * Every link in a branch page stays in that branch: the branch of the page
 * this tab shows is the default for every route (setUrlDefaults), set from
 * the page itself, so tabs on different branches never mix.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const {
        product,
        workspace,
        user,
        flash,
        branch,
        area,
        overview: oversees,
    } = props;

    // Before the page renders: its links are made with this tab's branch.
    setUrlDefaults({ branch: branch?.id });

    const can = useCan();
    const canHere = useCanHere();
    const inBranch = area === 'branch' && branch !== null;
    const groups = inBranch
        ? visible(branchNavigation(), canHere)
        : visible(managementNavigation(workspace?.url ?? null), can);
    const root = inBranch ? home() : oversees ? overview() : start();
    const active = activeItem(groups, url, root);
    const shared: ShellNavGroup[] =
        workspace && !inBranch
            ? [
                  {
                      title: 'Shared',
                      items: [
                          {
                              title: 'Finance',
                              href: new URL(
                                  'finance/',
                                  workspace.url,
                              ).toString(),
                              icon: Landmark,
                              external: true,
                          },
                      ],
                  },
              ]
            : [];

    const searches = useCallback(
        (term: string): QuickSearchTarget[] =>
            !inBranch && can('restaurant.menu.view')
                ? [
                      {
                          key: 'menu',
                          label: `Menu items matching “${term}”`,
                          url: menu.url({ query: { search: term } }),
                      },
                  ]
                : [],
        [can, inBranch],
    );

    const title = inBranch ? branch.name : 'Restaurant management';

    return (
        <AppShell
            flash={flash.success}
            crumbs={crumbsFor({ label: title, href: root.url }, active, root)}
            actions={
                <>
                    <BranchSwitcher />
                    <QuickSearch
                        groups={groups}
                        searches={searches}
                        placeholder={
                            inBranch
                                ? `Go to a section of ${branch.name}…`
                                : 'Go to a section, or search the menu…'
                        }
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
                                label: `Sign out of ${product.name}`,
                                ...logout.form(),
                            }}
                        />
                    )}
                </>
            }
            sidebar={(onNavigate) => (
                <ShellSidebar
                    label={`${product.name}: ${title}`}
                    back={
                        workspace && !inBranch
                            ? {
                                  label: `${workspace.name} home`,
                                  href: workspace.url,
                              }
                            : undefined
                    }
                    brand={{
                        title: inBranch ? branch.name : product.name,
                        subtitle: inBranch
                            ? `${product.name} · ${workspace?.name ?? ''}`
                            : `Management · ${workspace?.name ?? ''}`,
                        icon: UtensilsCrossed,
                        href: root,
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
