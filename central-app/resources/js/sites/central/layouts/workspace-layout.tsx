import { usePage } from '@inertiajs/react';
import {
    ArrowLeftRight,
    Building2,
    Compass,
    CreditCard,
    Home,
    KeyRound,
    Mail,
    Package,
    Plus,
    ReceiptText,
    Settings,
    ShieldCheck,
    SlidersHorizontal,
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
import { workspaceRoutes } from '@/sites/central/lib/workspace-routes';

/**
 * The workspace ({business}.<root>) in the shell every Miles app shares:
 * its own pages, administration for those allowed, and the applications
 * the member can open (Pharmacy, Finance, ...), which link back here. The
 * owner also gets the business's plans, subscription, billing and settings.
 * Relative paths: every workspace page is served on its tenant host; the
 * list of businesses and new ones are on the auth host.
 */
export default function WorkspaceLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { tenant, abilities, apps, auth, platform } = props;
    const isOwner = tenant?.isOwner ?? false;
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
            title: 'Business',
            items: isOwner
                ? [
                      {
                          title: 'Explore the ERP',
                          href: workspaceRoutes.explore,
                          icon: Compass,
                      },
                      {
                          title: 'Plans',
                          href: workspaceRoutes.plans,
                          icon: Package,
                      },
                      {
                          title: 'Subscription',
                          href: workspaceRoutes.subscription,
                          icon: CreditCard,
                      },
                      {
                          title: 'Billing',
                          href: workspaceRoutes.billing,
                          icon: ReceiptText,
                      },
                      {
                          title: 'General settings',
                          href: workspaceRoutes.settings,
                          icon: SlidersHorizontal,
                      },
                  ]
                : [],
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
                                    label: 'Your businesses',
                                    href: platform.workspacesUrl,
                                    icon: ArrowLeftRight,
                                    external: true,
                                },
                                {
                                    label: 'New business',
                                    href: platform.newBusinessUrl,
                                    icon: Plus,
                                    external: true,
                                },
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
