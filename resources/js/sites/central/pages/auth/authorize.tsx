import { Head } from '@inertiajs/react';
import { Button } from '@/shared/components/ui/button';
import { approve, deny } from '@/routes/passport/authorizations';

type Props = {
    client: { id: string; name: string };
    scopes: { id: string; description: string }[];
    authToken: string;
    csrfToken: string;
    state: string | null;
    nonce: string | null;
};

// Approve and deny redirect to the client application, so they are plain
// HTML form posts rather than Inertia visits.
export default function Authorize({
    client,
    scopes,
    authToken,
    csrfToken,
    state,
    nonce,
}: Props) {
    const approveUrl = approve.url(nonce ? { query: { nonce } } : undefined);

    return (
        <>
            <Head title="Authorize application" />

            <div className="space-y-6">
                <p className="text-sm">
                    <span className="font-medium">{client.name}</span> is
                    requesting access to your account.
                </p>

                {scopes.length > 0 && (
                    <div className="space-y-2">
                        <p className="text-sm font-medium">
                            This application will be able to:
                        </p>
                        <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                            {scopes.map((scope) => (
                                <li key={scope.id}>{scope.description}</li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="flex gap-2">
                    <form method="post" action={approveUrl} className="flex-1">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="state" value={state ?? ''} />
                        <input
                            type="hidden"
                            name="client_id"
                            value={client.id}
                        />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button type="submit" className="w-full">
                            Authorize
                        </Button>
                    </form>

                    <form method="post" action={deny.url()} className="flex-1">
                        <input type="hidden" name="_token" value={csrfToken} />
                        <input type="hidden" name="_method" value="DELETE" />
                        <input type="hidden" name="state" value={state ?? ''} />
                        <input
                            type="hidden"
                            name="client_id"
                            value={client.id}
                        />
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            type="submit"
                            variant="outline"
                            className="w-full"
                        >
                            Cancel
                        </Button>
                    </form>
                </div>
            </div>
        </>
    );
}

Authorize.layout = {
    title: 'Authorize application',
    description: 'Review what this application is asking for.',
};
