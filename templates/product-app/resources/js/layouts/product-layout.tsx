import { Form, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, LogOut } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { useCan } from '@/lib/access';
import { logout } from '@/routes/platform';
import { home } from '@/routes';
import { index as records } from '@/routes/records';

/**
 * The product's chrome inside a workspace: product and workspace name, the
 * way back to the workspace, and sign-out of this product.
 */
export default function ProductLayout({ children }: PropsWithChildren) {
    const { product, workspace, user, flash } = usePage().props;
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
                    <nav aria-label="Product" className="flex gap-4 text-sm">
                        {can('blueprint.records.view') && (
                            <Link href={records()} className="hover:underline">
                                Records
                            </Link>
                        )}
                    </nav>
                    {user && (
                        <div className="ml-auto flex items-center gap-3 text-sm">
                            <span className="text-muted-foreground">
                                {user.name}
                            </span>
                            <Form {...logout.form()}>
                                <Button type="submit" variant="ghost" size="sm">
                                    <LogOut className="size-4" aria-hidden />
                                    Sign out
                                </Button>
                            </Form>
                        </div>
                    )}
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
