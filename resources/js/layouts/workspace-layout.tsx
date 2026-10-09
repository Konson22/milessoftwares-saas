import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';

// Relative paths: every workspace page is served on its own tenant host.
const baseNavItems = [
    { title: 'Home', href: '/' },
    { title: 'Members', href: '/members' },
];

export default function WorkspaceLayout({ children }: PropsWithChildren) {
    const { tenant, platform, auth, abilities } = usePage().props;
    const navItems = [
        ...baseNavItems,
        ...(abilities?.manageStructure
            ? [{ title: 'Structure', href: '/manage/structure' }]
            : []),
        ...(abilities?.manageAccess
            ? [{ title: 'Access', href: '/manage/access' }]
            : []),
    ];
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <header className="border-b">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
                    <div className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-6 fill-current text-primary" />
                        {tenant?.displayName}
                    </div>

                    <nav className="flex gap-1" aria-label="Workspace">
                        {navItems.map((item) => (
                            <Button
                                key={item.href}
                                asChild
                                size="sm"
                                variant="ghost"
                                className={cn({
                                    'bg-muted':
                                        item.href === '/'
                                            ? isCurrentUrl('/')
                                            : isCurrentOrParentUrl(item.href),
                                })}
                            >
                                <Link href={item.href}>{item.title}</Link>
                            </Button>
                        ))}
                    </nav>

                    <div className="ml-auto flex items-center gap-3 text-sm">
                        <span className="hidden text-muted-foreground sm:inline">
                            {auth.user?.name}
                        </span>
                        <Button asChild size="sm" variant="outline">
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
            </header>

            <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
                {children}
            </main>
        </div>
    );
}
