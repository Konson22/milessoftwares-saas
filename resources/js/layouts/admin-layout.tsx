import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

// Relative paths: every admin page is served on admin.<root>. Each item is
// shown only with the permission its pages require.
const navItems: { title: string; href: string; permission?: string }[] = [
    { title: 'Overview', href: '/' },
    {
        title: 'Organizations',
        href: '/organizations',
        permission: 'organizations.view',
    },
    {
        title: 'Support access',
        href: '/support-access',
        permission: 'support.request',
    },
    {
        title: 'Applications',
        href: '/applications',
        permission: 'registry.manage',
    },
    { title: 'Plans', href: '/plans', permission: 'plans.manage' },
    { title: 'Security', href: '/security', permission: 'security.view' },
    { title: 'Staff', href: '/staff', permission: 'staff.manage' },
];

export default function AdminLayout({ children }: PropsWithChildren) {
    const { staff, platform, auth } = usePage().props;
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const permissions = staff?.permissions ?? [];

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="border-b bg-primary text-primary-foreground">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
                    <span className="font-semibold">
                        Miles Global Softwares · System Administration
                    </span>
                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <span className="hidden sm:inline">
                            {auth.user?.email}
                        </span>
                        <Button asChild size="sm" variant="secondary">
                            <Link
                                href={platform.logoutUrl}
                                method="post"
                                as="button"
                            >
                                Sign out
                            </Link>
                        </Button>
                    </div>
                </div>
                <nav
                    className="mx-auto flex max-w-6xl flex-wrap gap-1 px-4 pb-2"
                    aria-label="Administration"
                >
                    {navItems
                        .filter(
                            (item) =>
                                item.permission === undefined ||
                                permissions.includes(item.permission),
                        )
                        .map((item) => (
                            <Link
                                key={item.href}
                                href={item.href}
                                className={cn(
                                    'rounded-md px-3 py-1.5 text-sm hover:bg-primary-foreground/10',
                                    (item.href === '/'
                                        ? isCurrentUrl('/')
                                        : isCurrentOrParentUrl(item.href)) &&
                                        'bg-primary-foreground/15 font-medium',
                                )}
                            >
                                {item.title}
                            </Link>
                        ))}
                </nav>
            </header>
            <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
                {children}
            </main>
        </div>
    );
}
