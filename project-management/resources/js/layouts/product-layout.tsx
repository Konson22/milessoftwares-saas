import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Building2,
    LayoutGrid,
    LayoutDashboard,
    UserCog,
} from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import type { UserMenuLink } from '@miles/ui/shell/user-menu';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { useCan } from '@/lib/access';
import { visibleNavigation } from '@/lib/navigation';
import { home, search } from '@/routes';
import { index as notifications } from '@/routes/notifications';
import { logout } from '@/routes/platform';

/**
 * Pages inside an organization's workspace: the sidebar with the sections
 * the member may use, search, the switcher to other workspaces and the
 * account menu (members, roles and the account are on the platform). Sections live in lib/navigation.ts.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const {
        product,
        organization,
        organizations: others,
        user,
        flash,
        unreadNotifications,
        donorPortal,
    } = props;

    const can = useCan();
    const groups = visibleNavigation(
        can,
        donorPortal,
        organization?.funds ?? false,
        organization?.modules,
    );
    const active = activeItem(groups, url, home());

    const links: UserMenuLink[] = [
        ...others
            .filter((other) => other.slug !== organization?.slug)
            .map((other) => ({
                label: other.name,
                href: other.url,
                icon: Building2,
                // Another workspace: another host, a full page load.
                external: true,
            })),
        ...(organization && can('organization.manage')
            ? [
                  {
                      label: 'Members and roles',
                      href: organization.membersUrl,
                      icon: UserCog,
                      external: true,
                  },
              ]
            : []),
        ...(organization
            ? [
                  {
                      label: 'Workspace',
                      href: organization.workspaceUrl,
                      icon: LayoutDashboard,
                      external: true,
                  },
              ]
            : []),
    ];

    return (
        <AppShell
            flash={flash.success}
            warning={flash.warning}
            crumbs={crumbsFor(
                {
                    label: organization?.name ?? product.name,
                    href: home().url,
                },
                active,
                home(),
            )}
            actions={
                <>
                    {!donorPortal?.confined && (
                        <QuickSearch
                            groups={groups}
                            placeholder="Go to a section, or search…"
                            searches={(term) => [
                                {
                                    key: 'everything',
                                    label: `Search for “${term}”`,
                                    url: search.url({ query: { q: term } }),
                                },
                            ]}
                        />
                    )}
                    <Link
                        href={notifications()}
                        aria-label={
                            unreadNotifications > 0
                                ? `Notifications, ${unreadNotifications} unread`
                                : 'Notifications'
                        }
                        className="relative inline-flex size-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <Bell className="size-4" aria-hidden />
                        {unreadNotifications > 0 && (
                            <span
                                className="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 font-medium text-primary-foreground tabular-nums"
                                aria-hidden
                            >
                                {unreadNotifications > 99
                                    ? '99+'
                                    : unreadNotifications}
                            </span>
                        )}
                    </Link>
                    {user && (
                        <UserMenu
                            user={user}
                            subtitle={organization?.name}
                            links={links}
                            signOut={{ label: 'Sign out', ...logout.form() }}
                        />
                    )}
                </>
            }
            sidebar={(onNavigate) => (
                <ShellSidebar
                    label={product.name}
                    brand={{
                        title: organization?.name ?? product.name,
                        subtitle: product.name,
                        icon: LayoutGrid,
                        image: '/images/logo-icon-light.png',
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
