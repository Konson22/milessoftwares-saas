import { Head } from '@inertiajs/react';

type Props = {
    organization: { displayName: string };
};

export default function WorkspaceSuspended({ organization }: Props) {
    return (
        <>
            <Head title="Workspace suspended" />
            <main className="flex min-h-screen items-center justify-center bg-background p-4 text-foreground">
                <div className="max-w-md text-center">
                    <h1 className="text-2xl font-semibold">
                        {organization.displayName} is suspended
                    </h1>
                    <p className="mt-3 text-muted-foreground">
                        This workspace is temporarily unavailable. Please
                        contact your organization owner or Miles Global
                        Softwares support.
                    </p>
                </div>
            </main>
        </>
    );
}
