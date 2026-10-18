import { usePage } from '@inertiajs/react';
import {
    Building2,
    Home,
    KeyRound,
    Mail,
    Settings,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import type { ShellNavGroup } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { appIcon } from '@/shared/lib/app-icons';

/**
 * The workspace ({business}.<root>) in the shell every Miles app shares:
 * its own pages, administration for those allowed, and the applications
 * the member can open (Pharmacy, Finance, ...), which link back here.
 * Relative paths: every workspace page is served on its tenant host.
 */
export default function WorkspaceLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { tenant, abilities, apps, auth, platform } = props;
    const name = tenant?.displayName ?? 'Workspace';

    const groups: ShellNavGroup[] = [
        {
            title: 'Workspace',
            items: [
                { title: 'Home', href: '/', icon: Home },
                { title: 'Members', href: '/members', icon: Users },
            ],
        },
        {
            title: 'Manage',
            items: [
                ...(abilities?.manageStructure
                    ? [
                          {
                              title: 'Structure',
                              href: '/manage/structure',
                              icon: Building2,
                          },
                      ]
                    : []),
                ...(abilities?.manageAccess
                    ? [
                          {
                              title: 'Access',
                              href: '/manage/access',
                              icon: KeyRound,
                          },
                          {
                              title: 'Invitations',
                              href: '/manage/invitations',
                              icon: Mail,
                          },
                      ]
                    : []),
            ],
        },
        {
            title: 'Applications',
            items: apps.map((app) => ({
                title: app.name,
                href: app.href,
                icon: appIcon(app.code),
                external: true,
            })),
        },
    ].filter((group) => group.items.length > 0);

    const active = activeItem(groups, url, '/');

    return (
        <AppShell
            crumbs={crumbsFor({ label: name, href: '/' }, active, '/')}
            actions={
                <>
                    <QuickSearch groups={groups} />
                    {auth.user && (
                        <UserMenu
                            user={auth.user}
                            subtitle={name}
                            links={[
                                {
                                    label: 'Account settings',
                                    href: platform.accountUrl,
                                    icon: Settings,
                                    external: true,
                                },
                                {
                                    label: 'Security',
                                    href: platform.securityUrl,
                                    icon: ShieldCheck,
                                    external: true,
                                },
                            ]}
                            signOut={{
                                label: 'Sign out',
                                action: platform.logoutUrl,
                                method: 'post',
                            }}
                        />
                    )}
                </>
            }
            sidebar={(onNavigate) => (
                <ShellSidebar
                    label={name}
                    brand={{
                        title: name,
                        subtitle: 'Workspace',
                        icon: Building2,
                        href: '/',
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
