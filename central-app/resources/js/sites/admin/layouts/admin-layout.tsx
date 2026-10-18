import { usePage } from '@inertiajs/react';
import {
    AppWindow,
    Building2,
    CreditCard,
    Gauge,
    Inbox,
    LifeBuoy,
    Package,
    ReceiptText,
    ShieldCheck,
    ShieldHalf,
    UserCog,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { AppShell } from '@miles/ui/shell/app-shell';
import { activeItem, crumbsFor } from '@miles/ui/shell/navigation';
import type { ShellNavGroup } from '@miles/ui/shell/navigation';
import { QuickSearch } from '@miles/ui/shell/quick-search';
import { ShellSidebar } from '@miles/ui/shell/sidebar';
import { UserMenu } from '@miles/ui/shell/user-menu';

type AdminItem = {
    title: string;
    href: string;
    icon: LucideIcon;
    permission?: string;
};

// Relative paths: every admin page is served on admin.<root>. Each item is
// shown only with the permission its pages require.
const navigation: { title: string; items: AdminItem[] }[] = [
    {
        title: 'Customers',
        items: [
            { title: 'Overview', href: '/', icon: Gauge },
            {
                title: 'Organizations',
                href: '/organizations',
                icon: Building2,
                permission: 'organizations.view',
            },
            {
                title: 'Support access',
                href: '/support-access',
                icon: LifeBuoy,
                permission: 'support.request',
            },
            {
                title: 'Billing',
                href: '/billing',
                icon: ReceiptText,
                permission: 'billing.manage',
            },
            {
                title: 'Leads',
                href: '/leads',
                icon: Inbox,
                permission: 'leads.manage',
            },
        ],
    },
    {
        title: 'Catalog',
        items: [
            {
                title: 'Applications',
                href: '/applications',
                icon: AppWindow,
                permission: 'registry.manage',
            },
            {
                title: 'Products',
                href: '/products',
                icon: Package,
                permission: 'plans.manage',
            },
            {
                title: 'Plans',
                href: '/plans',
                icon: CreditCard,
                permission: 'plans.manage',
            },
        ],
    },
    {
        title: 'Platform',
        items: [
            {
                title: 'Security',
                href: '/security',
                icon: ShieldCheck,
                permission: 'security.view',
            },
            {
                title: 'Staff',
                href: '/staff',
                icon: UserCog,
                permission: 'staff.manage',
            },
        ],
    },
];

/**
 * The staff console (admin.<root>) in the shell every Miles app shares,
 * marked with an amber tile so it is never mistaken for a customer
 * workspace.
 */
export default function AdminLayout({ children }: PropsWithChildren) {
    const { url, props } = usePage();
    const { staff, platform, auth } = props;
    const permissions = staff?.permissions ?? [];

    const groups: ShellNavGroup[] = navigation
        .map((group) => ({
            title: group.title,
            items: group.items.filter(
                (item) =>
                    item.permission === undefined ||
                    permissions.includes(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
    const active = activeItem(groups, url, '/');

    return (
        <AppShell
            crumbs={crumbsFor(
                { label: 'Administration', href: '/' },
                active,
                '/',
            )}
            actions={
                <>
                    <QuickSearch groups={groups} />
                    {auth.user && (
                        <UserMenu
                            user={auth.user}
                            subtitle="Staff"
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
                    label="Administration"
                    brand={{
                        title: 'Administration',
                        subtitle: 'Miles Softwares staff',
                        icon: ShieldHalf,
                        href: '/',
                        tone: 'amber',
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
