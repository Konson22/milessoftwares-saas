import { usePage } from '@inertiajs/react';
import { ArrowLeft, Fuel, Landmark } from 'lucide-react';
import { useCallback } from 'react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import type { ShellNavGroup } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { BranchSwitcher } from '@/components/branch-switcher';
import { useCan, useCanHere } from '@/lib/access';
import { visibleNavigation } from '@/lib/navigation';
import { home } from '@/routes';
import { logout } from '@/routes/platform';

/**
 * Petrol Station in the shared Miles shell: back to the workspace, the
 * station being worked in, Finance (where bills are booked), and sign-out.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { product, workspace, user, flash, branch } = props;
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

    const searches = useCallback(() => [], []);

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
                        placeholder="Go to a section…"
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
                        icon: Fuel,
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
