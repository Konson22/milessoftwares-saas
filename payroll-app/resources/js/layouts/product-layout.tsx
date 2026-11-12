import { usePage } from '@inertiajs/react';
import { ArrowLeft, LayoutGrid } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { useCan } from '@/lib/access';
import { visibleNavigation } from '@/lib/navigation';
import { home } from '@/routes';
import { logout } from '@/routes/platform';

/**
 * The product in the shell every Miles app shares (@miles/ui): the way
 * back to the workspace, the product's sections, search and sign-out.
 * Sections live in lib/navigation.ts.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { product, workspace, user, flash } = props;
    const can = useCan();
    const groups = visibleNavigation(can);
    const active = activeItem(groups, url, home());

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
                    <QuickSearch groups={groups} />
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
                        icon: LayoutGrid,
                        href: home(),
                    }}
                    groups={groups}
                    active={active?.item ?? null}
                    onNavigate={onNavigate}
                />
            )}
        >
            {children}
        </AppShell>
    );
}
