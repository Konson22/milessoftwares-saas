import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { logout } from '@/routes';

type Props = {
    homeUrl: string;
    dashboardUrl: string;
};

export default function SignOut({ homeUrl, dashboardUrl }: Props) {
    return (
        <>
            <Head title="Signed out" />

            <div className="space-y-4">
                <Button asChild className="w-full">
                    <Link
                        href={logout()}
                        as="button"
                        data-test="central-logout"
                    >
                        Sign out of Miles Softwares on this browser
                    </Link>
                </Button>

                <div className="flex justify-between text-sm">
                    <a className="underline" href={dashboardUrl}>
                        Back to dashboard
                    </a>
                    <a className="underline" href={homeUrl}>
                        Stay signed in
                    </a>
                </div>
            </div>
        </>
    );
}

SignOut.layout = {
    title: 'You signed out of the app',
    description:
        'You are still signed in to your Miles Softwares account on this browser, so other apps can sign you in without a password.',
};
