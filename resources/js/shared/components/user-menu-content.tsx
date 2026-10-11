import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Settings } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/shared/components/ui/dropdown-menu';
import { UserInfo } from '@/shared/components/user-info';
import { useMobileNavigation } from '@/shared/hooks/use-mobile-navigation';
import { edit } from '@/routes/profile';
import type { User } from '@/shared/types';

type Props = {
    user: User;
};

export function UserMenuContent({ user }: Props) {
    const cleanup = useMobileNavigation();
    const { platform } = usePage().props;

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    {platform.isAuthHost ? (
                        <Link
                            className="block w-full cursor-pointer"
                            href={edit()}
                            prefetch
                            onClick={cleanup}
                        >
                            <Settings className="mr-2" />
                            Settings
                        </Link>
                    ) : (
                        // Account settings live on the auth host: full page load.
                        <a
                            className="block w-full cursor-pointer"
                            href={platform.accountUrl}
                        >
                            <Settings className="mr-2" />
                            Account settings
                        </a>
                    )}
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full cursor-pointer"
                    href={platform.logoutUrl}
                    method="post"
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    Log out
                </Link>
            </DropdownMenuItem>
        </>
    );
}
