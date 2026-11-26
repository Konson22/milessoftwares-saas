import { Link, usePage } from '@inertiajs/react';
import { ModalRoot, ModalStackProvider } from '@inertiaui/modal-react';
import { ArrowLeft, Bell, BookOpen } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';
import { ConfirmHost } from '@/components/dialogs';
import { useCan } from '@/lib/access';
import { visibleNavigation } from '@/lib/navigation';
import { home } from '@/routes';
import { index as notifications } from '@/routes/notifications';
import { logout } from '@/routes/platform';

/**
 * The product in the shell every Miles app shares (@miles/ui): the way
 * back to the workspace, the product's sections, search and sign-out.
 * Sections live in lib/navigation.ts.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { product, workspace, user, flash, unread } = props;
    const can = useCan();
    const groups = visibleNavigation(can);
    const active = activeItem(groups, url, home());

    return (
        <ModalStackProvider>
            <AppShell
                expanded
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
                            <Link
                                href={notifications()}
                                aria-label={
                                    unread > 0
                                        ? `Notifications: ${unread} unread`
                                        : 'Notifications'
                                }
                                className="relative inline-flex size-10 items-center justify-center rounded-full hover:bg-muted"
                            >
                                <Bell className="size-5" />
                                {unread > 0 && (
                                    <span className="absolute top-0.5 right-0.5 min-w-4 rounded-full border-2 border-white bg-primary px-1 text-center text-[10px] leading-3.5 font-medium text-white">
                                        {unread > 99 ? '99+' : unread}
                                    </span>
                                )}
                            </Link>
                        )}
                        {user && (
                            <UserMenu
                                user={user}
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
                        brand={{
                            title: product.name,
                            icon: BookOpen,
                            image: '/logbook/images/logo.png',
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
            <ModalRoot />
            <ConfirmHost />
        </ModalStackProvider>
    );
}
