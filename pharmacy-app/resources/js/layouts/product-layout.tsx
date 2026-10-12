import { Form, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, LogOut, MapPin } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { useCan } from '@/lib/access';
import { navigation } from '@/lib/navigation';
import { home } from '@/routes';
import { edit as chooseBranch } from '@/routes/branch';
import { logout } from '@/routes/platform';

/**
 * The pharmacy inside a workspace: the way back to the workspace, the
 * sections this member may use, the branch they work in, and sign-out of
 * the pharmacy (the workspace stays signed in).
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { product, workspace, user, branch, canSwitchBranch, flash } =
        usePage().props;
    const can = useCan();

    return (
        <div className="flex min-h-screen flex-col bg-background text-foreground">
            <a
                href="#main"
                className="sr-only focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded focus:bg-background focus:px-3 focus:py-2"
            >
                Skip to content
            </a>
            <header className="border-b">
                <div className="mx-auto flex max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3">
                    {workspace && (
                        <a
                            href={workspace.url}
                            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                        >
                            <ArrowLeft className="size-4" aria-hidden />
                            {workspace.name}
                        </a>
                    )}
                    <Link href={home()} className="font-semibold">
                        {product.name}
                    </Link>
                    <nav
                        aria-label="Pharmacy"
                        className="flex flex-wrap gap-4 text-sm"
                    >
                        {navigation
                            .filter((item) => can(item.permission))
                            .map((item) => (
                                <Link
                                    key={item.title}
                                    href={item.href}
                                    className="hover:underline"
                                >
                                    {item.title}
                                </Link>
                            ))}
                    </nav>
                    <div className="ml-auto flex items-center gap-4 text-sm">
                        {branch && (
                            <span className="inline-flex items-center gap-1">
                                <MapPin
                                    className="size-4 text-muted-foreground"
                                    aria-hidden
                                />
                                <span>{branch.name}</span>
                                {canSwitchBranch && (
                                    <Link
                                        href={chooseBranch()}
                                        className="text-muted-foreground underline-offset-2 hover:underline"
                                    >
                                        Change
                                    </Link>
                                )}
                            </span>
                        )}
                        {user && (
                            <>
                                <span className="text-muted-foreground">
                                    {user.name}
                                </span>
                                <Form {...logout.form()}>
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                    >
                                        <LogOut
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Sign out
                                    </Button>
                                </Form>
                            </>
                        )}
                    </div>
                </div>
            </header>
            <main
                id="main"
                className="mx-auto w-full max-w-6xl flex-1 px-4 py-8"
            >
                {flash.success && (
                    <p
                        role="status"
                        className="mb-6 rounded-md border px-4 py-2 text-sm"
                    >
                        {flash.success}
                    </p>
                )}
                {children}
            </main>
        </div>
    );
}
