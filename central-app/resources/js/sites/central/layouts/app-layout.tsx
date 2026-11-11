import { usePage } from '@inertiajs/react';
import {
    Building2,
    Plus,
    Settings,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem } from '@miles/ui/shell/navigation';
import type { Crumb, ShellNavGroup } from '@miles/ui/shell/navigation';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { toUrl } from '@/shared/lib/utils';
import { workspaces } from '@/routes';
import { create as createOrganization } from '@/routes/organizations';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { BreadcrumbItem } from '@/shared/types';

/**
 * The account area on the auth host (your businesses, new business,
 * profile and security) in the shell every Tatook app shares. Pages pass
 * their breadcrumbs as layout props. Each business opens on its own host,
 * with its own shell (WorkspaceLayout).
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

    const groups: ShellNavGroup[] = [
        {
            title: '',
            items: [
                {
                    title: 'Your businesses',
                    href: workspaces(),
                    icon: Building2,
                },
                {
                    title: 'New business',
                    href: createOrganization(),
                    icon: Plus,
                },
            ],
        },
        {
            title: 'Account',
            items: [
                { title: 'Profile', href: editProfile(), icon: UserRound },
                { title: 'Security', href: editSecurity(), icon: ShieldCheck },
            ],
        },
    ];
    const active = activeItem(groups, url, workspaces());
    const crumbs: Crumb[] = breadcrumbs.map((crumb) => ({
        label: crumb.title,
        href: toUrl(crumb.href),
    }));

    return (
        <AppShell
            crumbs={crumbs.length > 0 ? crumbs : [{ label: 'Tatook' }]}
            actions={
                auth.user && (
                    <UserMenu
                        user={auth.user}
                        links={[
                            {
                                label: 'Settings',
                                href: editProfile().url,
                                icon: Settings,
                            },
                            {
                                label: 'Security',
                                href: editSecurity().url,
                                icon: ShieldCheck,
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
                        title: 'Tatook',
                        subtitle: auth.user?.name,
                        icon: Building2,
                        href: workspaces(),
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
