import { Head } from '@inertiajs/react';

// Phase 1 placeholder. Admin modules arrive in Phase 5. This layout is
// deliberately separate from the customer dashboard navigation.
export default function AdminOverview() {
    return (
        <>
            <Head title="System Administration" />
            <div className="min-h-screen bg-background text-foreground">
                <header className="border-b bg-primary text-primary-foreground">
                    <div className="mx-auto max-w-6xl px-4 py-4 font-semibold">
                        Miles Global Softwares · System Administration
                    </div>
                </header>
                <main className="mx-auto max-w-6xl px-4 py-10">
                    <h1 className="text-2xl font-semibold">Overview</h1>
                    <p className="mt-2 text-muted-foreground">
                        Administration modules are not yet available.
                    </p>
                </main>
            </div>
        </>
    );
}
