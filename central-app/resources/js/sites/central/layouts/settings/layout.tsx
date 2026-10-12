import { Link } from '@inertiajs/react';
import { KeyRound, Monitor, Palette, UserRound } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import Heading from '@/shared/components/heading';
import { Separator } from '@/shared/components/ui/separator';
import { useCurrentUrl } from '@/shared/hooks/use-current-url';
import { cn, toUrl } from '@/shared/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as sessionsIndex } from '@/routes/sessions';
import type { NavItem } from '@/shared/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: UserRound,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: KeyRound,
    },
    {
        title: 'Sessions',
        href: sessionsIndex(),
        icon: Monitor,
    },
    {
        title: 'Appearance',
        href: editAppearance(),
        icon: Palette,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="px-4 py-5 md:px-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div className="flex flex-col gap-6 lg:flex-row lg:gap-8">
                <aside className="w-full shrink-0 lg:w-56">
                    <nav
                        className="flex flex-col gap-0.5 rounded-md border bg-card p-1.5"
                        aria-label="Settings"
                    >
                        {sidebarNavItems.map((item, index) => {
                            const active = isCurrentOrParentUrl(item.href);

                            return (
                                <Link
                                    key={`${toUrl(item.href)}-${index}`}
                                    href={item.href}
                                    className={cn(
                                        'flex items-center gap-2 rounded-md px-3 py-2 text-sm transition-colors',
                                        active
                                            ? 'bg-primary/10 font-medium text-primary shadow-[inset_3px_0_0_0_var(--primary)]'
                                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                    )}
                                >
                                    {item.icon && (
                                        <item.icon className="size-4 shrink-0" />
                                    )}
                                    {item.title}
                                </Link>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="lg:hidden" />

                <div className="min-w-0 flex-1">
                    <section className="max-w-3xl space-y-4">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}
