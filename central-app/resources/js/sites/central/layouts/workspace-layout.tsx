import { Link, usePage } from '@inertiajs/react';
import { Building2, Home, KeyRound, Mail, Users } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { AppContent } from '@/shared/components/app-content';
import { AppShell } from '@/shared/components/app-shell';
import AppLogoIcon from '@/shared/components/app-logo-icon';
import { NavMain } from '@/shared/components/nav-main';
import { NavUser } from '@/shared/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarTrigger,
} from '@/shared/components/ui/sidebar';
import type { NavItem } from '@/shared/types';

// Relative paths: every workspace page is served on its own tenant host.
const baseNavItems: NavItem[] = [
    { title: 'Home', href: '/', icon: Home },
    { title: 'Members', href: '/members', icon: Users },
];

export default function WorkspaceLayout({ children }: PropsWithChildren) {
    const { tenant, abilities } = usePage().props;
    const navItems: NavItem[] = [
        ...baseNavItems,
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
                  { title: 'Access', href: '/manage/access', icon: KeyRound },
                  {
                      title: 'Invitations',
                      href: '/manage/invitations',
                      icon: Mail,
                  },
              ]
            : []),
    ];

    return (
        <AppShell variant="sidebar">
            <Sidebar collapsible="icon" variant="sidebar">
                <SidebarHeader className="border-b border-sidebar-border">
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href="/" prefetch>
                                    <AppLogoIcon className="size-8 shrink-0 rounded-md" />
                                    <div className="grid flex-1 text-left text-sm leading-tight">
                                        <span className="truncate font-semibold">
                                            {tenant?.displayName ?? 'Workspace'}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            Workspace
                                        </span>
                                    </div>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                </SidebarHeader>

                <SidebarContent>
                    <NavMain items={navItems} label="Workspace" />
                </SidebarContent>

                <SidebarFooter>
                    <NavUser />
                </SidebarFooter>
            </Sidebar>

            <AppContent variant="sidebar" className="min-w-0 overflow-x-clip">
                <header className="flex h-12 shrink-0 items-center gap-2 border-b px-4">
                    <SidebarTrigger className="-ml-1" />
                    <span className="truncate text-sm text-muted-foreground">
                        {tenant?.displayName}
                    </span>
                </header>
                <div className="flex flex-1 flex-col px-4 py-4 md:px-6">
                    {children}
                </div>
            </AppContent>
        </AppShell>
    );
}
