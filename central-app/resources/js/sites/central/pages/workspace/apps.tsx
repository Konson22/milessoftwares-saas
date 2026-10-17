import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/shared/components/ui/button';
import { appIcon } from '@/shared/lib/app-icons';

type Props = {
    organization: { id: string; displayName: string };
    modules: {
        code: string;
        name: string;
        description: string | null;
        href: string;
    }[];
};

/**
 * What members who do not administer the workspace see: their applications
 * and nothing else. Renders its own chrome, without the workspace sidebar.
 */
export default function WorkspaceApps({ organization, modules }: Props) {
    const { auth, platform } = usePage().props;

    return (
        <>
            <Head title={organization.displayName} />
            <div className="min-h-screen bg-background text-foreground">
                <header className="flex items-center justify-between gap-4 border-b px-4 py-3 sm:px-6">
                    <span className="truncate font-semibold">
                        {organization.displayName}
                    </span>
                    <div className="flex items-center gap-2 text-sm">
                        {auth.user && (
                            <a
                                href={platform.accountUrl}
                                className="hidden truncate text-muted-foreground hover:text-foreground sm:inline"
                            >
                                {auth.user.name}
                            </a>
                        )}
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => router.post(platform.logoutUrl)}
                        >
                            Sign out
                        </Button>
                    </div>
                </header>

                <main className="mx-auto max-w-3xl px-4 py-10 sm:px-6">
                    <h1 className="text-2xl font-semibold">
                        Choose an application
                    </h1>
                    {modules.length === 0 ? (
                        <p className="mt-4 text-muted-foreground">
                            No applications are available to you in this
                            workspace yet. Ask your workspace administrator to
                            give you a role.
                        </p>
                    ) : (
                        <ul className="mt-6 grid gap-4 sm:grid-cols-2">
                            {modules.map((module) => {
                                const Icon = appIcon(module.code);

                                return (
                                    <li key={module.code}>
                                        <a
                                            href={module.href}
                                            className="flex h-full items-start gap-3 rounded-lg border p-4 hover:border-primary"
                                        >
                                            <Icon
                                                className="mt-0.5 size-5 shrink-0 text-primary"
                                                aria-hidden="true"
                                            />
                                            <span>
                                                <span className="block font-medium">
                                                    {module.name}
                                                </span>
                                                {module.description && (
                                                    <span className="mt-1 block text-sm text-muted-foreground">
                                                        {module.description}
                                                    </span>
                                                )}
                                            </span>
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </main>
            </div>
        </>
    );
}
