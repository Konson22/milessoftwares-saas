import { ShieldCheck } from 'lucide-react';

/**
 * Sign-in pages of the admin host: plain and clearly not the customer
 * sign-in, so staff always know which door they are at.
 */
export default function AdminAuthLayout({
    title = '',
    description = '',
    children,
}: {
    title?: string;
    description?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="flex min-h-svh items-center justify-center bg-muted/40 px-4 py-10">
            <div className="w-full max-w-sm space-y-6">
                <div className="flex flex-col items-center gap-2 text-center">
                    <span className="flex size-10 items-center justify-center rounded-lg bg-primary text-primary-foreground">
                        <ShieldCheck className="size-5" aria-hidden />
                    </span>
                    <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                        Miles Softwares administration
                    </p>
                    <h1 className="text-xl font-semibold">{title}</h1>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                <div className="rounded-xl border bg-background p-6 shadow-sm">
                    {children}
                </div>
                <p className="text-center text-xs text-muted-foreground">
                    Staff only. Every sign-in is recorded.
                </p>
            </div>
        </div>
    );
}
