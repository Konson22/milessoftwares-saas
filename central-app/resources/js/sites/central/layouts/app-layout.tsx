import { usePage } from '@inertiajs/react';
import { LayoutGrid, Plus, Settings } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem } from '@miles/ui/shell/navigation';
import type { Crumb, ShellNavGroup } from '@miles/ui/shell/navigation';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { toUrl } from '@/shared/lib/utils';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { create as createOrganization } from '@/routes/organizations';
import type { BreadcrumbItem } from '@/shared/types';

/**
 * The account area (dashboard on app., settings on auth.) in the shell
 * every Miles app shares. Pages pass their breadcrumbs as layout props.
 * The dashboard lives on another host than settings, so links that cross
 * hosts are full page loads.
 */
export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: ReactNode;
}) {
    const { url, props } = usePage();
    const { auth, platform } = props;
    const onApp = platform.host === 'app';

    const groups: ShellNavGroup[] = [
        {
            title: '',
            items: [
                {
                    title: 'Dashboard',
                    href: dashboard(),
                    icon: LayoutGrid,
                    external: !onApp,
                },
                {
                    title: 'New organization',
                    href: createOrganization(),
                    icon: Plus,
                    external: !onApp,
                },
            ],
        },
    ];
    const active = activeItem(groups, url, dashboard());
    const crumbs: Crumb[] = breadcrumbs.map((crumb) => ({
        label: crumb.title,
        href: toUrl(crumb.href),
    }));

    return (
        <AppShell
            crumbs={crumbs.length > 0 ? crumbs : [{ label: 'Miles Softwares' }]}
            actions={
                auth.user && (
                    <UserMenu
                        user={auth.user}
                        links={[
                            platform.isAuthHost
                                ? {
                                      label: 'Settings',
                                      href: editProfile().url,
                                      icon: Settings,
                                  }
                                : {
                                      label: 'Account settings',
                                      href: platform.accountUrl,
                                      icon: Settings,
                                      external: true,
                                  },
                        ]}
                        signOut={{
                            label: 'Sign out',
                            action: platform.logoutUrl,
                            method: 'post',
                        }}
                    />
                )
            }
            sidebar={(onNavigate) => (
                <ShellSidebar
                    label="Account"
                    brand={{
                        title: 'Miles Softwares',
                        subtitle: auth.user?.name,
                        icon: LayoutGrid,
                        href: dashboard(),
                        external: !onApp,
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
