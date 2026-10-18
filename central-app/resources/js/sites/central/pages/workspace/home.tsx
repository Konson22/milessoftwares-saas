import { Head } from '@inertiajs/react';

type Props = {
    organization: { id: string; displayName: string };
    modules: { code: string; name: string; href: string }[];
};

// Modules are only listed once entitlement and role checks exist (Phases 4–6).
export default function WorkspaceHome({ organization, modules }: Props) {
    return (
        <>
            <Head title={organization.displayName} />

            <h1 className="text-2xl font-semibold">Applications</h1>
            {modules.length === 0 ? (
                <p className="mt-4 text-muted-foreground">
                    No applications are available to you in this workspace yet.
                </p>
            ) : (
                <ul className="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {modules.map((module) => (
                        <li key={module.code}>
                            <a
                                href={module.href}
                                className="block rounded-lg border p-4 hover:border-primary"
                            >
                                {module.name}
                            </a>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
