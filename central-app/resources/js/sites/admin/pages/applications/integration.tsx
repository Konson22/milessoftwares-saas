import { Form, Head, Link, router } from '@inertiajs/react';
import InputError from '@/shared/components/input-error';
import { Badge } from '@/shared/components/ui/badge';
import { Button } from '@/shared/components/ui/button';
import { Input } from '@/shared/components/ui/input';
import { Label } from '@/shared/components/ui/label';

type Client = {
    id: string;
    name: string;
    type: 'confidential' | 'public' | 'service';
    clientId: string;
    status: 'active' | 'disabled';
    tenantRedirectPath: string | null;
    redirectUris: string[];
    tenantCallbacks: number;
};

type Props = {
    application: {
        id: string;
        code: string;
        name: string;
        provisioningMethod: string;
        integrationEndpoint: string | null;
    };
    clients: Client[];
    webhook: { url: string; status: string } | null;
    deliveries: {
        id: string;
        eventType: string;
        status: string;
        attempts: number;
        lastResponseCode: number | null;
        lastError: string | null;
        createdAt: string | null;
    }[];
    provisioningSteps: {
        id: string;
        organizationId: string;
        organization: string | null;
        status: string;
        attempts: number;
        lastError: string | null;
    }[];
    issuedSecret: {
        label: string;
        client_id: string | null;
        client_secret: string | null;
    } | null;
};

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';

export default function Integration({
    application,
    clients,
    webhook,
    deliveries,
    provisioningSteps,
    issuedSecret,
}: Props) {
    const base = `/applications/${application.id}/integration`;

    return (
        <>
            <Head title={`${application.name} integration`} />

            <Link
                href="/applications"
                className="text-sm text-muted-foreground underline"
            >
                Applications
            </Link>
            <h1 className="mt-3 text-2xl font-semibold">
                {application.name} integration
            </h1>
            <p className="mt-1 text-sm text-muted-foreground">
                Contracts are described in <code>docs/integration.md</code>.
                Secrets are shown once; store them in the product&apos;s secret
                manager.
            </p>

            {issuedSecret && (
                <div
                    role="alert"
                    className="mt-6 space-y-1 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm dark:border-amber-500/40 dark:bg-amber-500/10"
                >
                    <p className="font-medium">
                        {issuedSecret.label}: copy this now, it will not be
                        shown again.
                    </p>
                    {issuedSecret.client_id && (
                        <p>
                            Client ID:{' '}
                            <code className="break-all">
                                {issuedSecret.client_id}
                            </code>
                        </p>
                    )}
                    {issuedSecret.client_secret ? (
                        <p>
                            Secret:{' '}
                            <code className="break-all">
                                {issuedSecret.client_secret}
                            </code>
                        </p>
                    ) : (
                        <p>Public client: no secret, PKCE only.</p>
                    )}
                </div>
            )}

            <div className="mt-8 grid gap-10 xl:grid-cols-2">
                <section className="space-y-4">
                    <h2 className="text-lg font-medium">Clients</h2>
                    <ul className="space-y-3">
                        {clients.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                No clients yet.
                            </li>
                        )}
                        {clients.map((client) => (
                            <li
                                key={client.id}
                                className="rounded-lg border p-3 text-sm"
                            >
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="font-medium">
                                        {client.name}
                                    </span>
                                    <Badge variant="outline">
                                        {client.type}
                                    </Badge>
                                    {client.status === 'disabled' && (
                                        <Badge variant="destructive">
                                            disabled
                                        </Badge>
                                    )}
                                </div>
                                <p className="mt-1 break-all text-muted-foreground">
                                    Client ID: {client.clientId}
                                </p>
                                {client.redirectUris.map((uri) => (
                                    <p
                                        key={uri}
                                        className="break-all text-muted-foreground"
                                    >
                                        Callback: {uri}
                                    </p>
                                ))}
                                {client.tenantRedirectPath && (
                                    <p className="text-muted-foreground">
                                        Per tenant: https://&lt;slug&gt;…
                                        {client.tenantRedirectPath} (
                                        {client.tenantCallbacks} organizations)
                                    </p>
                                )}
                                {client.status === 'active' && (
                                    <div className="mt-2 flex gap-2">
                                        {client.type !== 'public' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/clients/${client.id}/rotate`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Rotate secret
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            onClick={() =>
                                                router.post(
                                                    `${base}/clients/${client.id}/disable`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Disable
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>

                    <Form
                        action={`${base}/sign-in-clients`}
                        method="post"
                        resetOnSuccess
                        options={{ preserveScroll: true }}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        {({ errors, processing }) => (
                            <>
                                <h3 className="font-medium">
                                    New sign-in client
                                </h3>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="client-name">Name</Label>
                                    <Input
                                        id="client-name"
                                        name="name"
                                        required
                                        defaultValue={`${application.name} web`}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="client-type">Type</Label>
                                    <select
                                        id="client-type"
                                        name="confidential"
                                        className={selectClass}
                                        defaultValue="1"
                                    >
                                        <option value="1">
                                            Confidential (server-side web app)
                                        </option>
                                        <option value="0">
                                            Public (SPA or mobile, PKCE only)
                                        </option>
                                    </select>
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="tenant-path">
                                        Per-tenant callback path
                                    </Label>
                                    <Input
                                        id="tenant-path"
                                        name="tenant_redirect_path"
                                        placeholder={`/${application.code}/auth/callback`}
                                    />
                                    <InputError
                                        message={errors.tenant_redirect_path}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="redirect-uris">
                                        Fixed callback URLs (one per line)
                                    </Label>
                                    <textarea
                                        id="redirect-uris"
                                        name="redirect_uris"
                                        rows={2}
                                        className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Register
                                </Button>
                            </>
                        )}
                    </Form>

                    <Form
                        action={`${base}/service-clients`}
                        method="post"
                        options={{ preserveScroll: true }}
                        className="flex flex-wrap items-end gap-2 rounded-lg border p-4"
                    >
                        {({ processing }) => (
                            <>
                                <div className="grid flex-1 gap-1.5">
                                    <Label htmlFor="service-name">
                                        New service client (API)
                                    </Label>
                                    <Input
                                        id="service-name"
                                        name="name"
                                        required
                                        defaultValue={`${application.name} backend`}
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Register
                                </Button>
                            </>
                        )}
                    </Form>
                </section>

                <section className="space-y-6">
                    <div className="space-y-3">
                        <h2 className="text-lg font-medium">Webhooks</h2>
                        <Form
                            action={`${base}/webhook`}
                            method="put"
                            options={{ preserveScroll: true }}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="webhook-url">
                                            Endpoint URL
                                        </Label>
                                        <Input
                                            id="webhook-url"
                                            name="url"
                                            type="url"
                                            required
                                            defaultValue={webhook?.url ?? ''}
                                        />
                                        <InputError message={errors.url} />
                                    </div>
                                    {webhook && (
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                name="rotate_secret"
                                                value="1"
                                            />{' '}
                                            Issue a new signing secret
                                        </label>
                                    )}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        {webhook ? 'Save' : 'Create endpoint'}
                                    </Button>
                                </>
                            )}
                        </Form>

                        {deliveries.length > 0 && (
                            <ul className="divide-y rounded-lg border text-sm">
                                {deliveries.map((delivery) => (
                                    <li
                                        key={delivery.id}
                                        className="flex flex-wrap items-center gap-2 p-3"
                                    >
                                        <Badge
                                            variant={
                                                delivery.status === 'delivered'
                                                    ? 'secondary'
                                                    : delivery.status ===
                                                        'failed'
                                                      ? 'destructive'
                                                      : 'outline'
                                            }
                                        >
                                            {delivery.status}
                                        </Badge>
                                        <span className="font-medium">
                                            {delivery.eventType}
                                        </span>
                                        <span className="flex-1 text-muted-foreground">
                                            {delivery.attempts} attempts
                                            {delivery.lastResponseCode &&
                                                ` · HTTP ${delivery.lastResponseCode}`}
                                            {delivery.lastError &&
                                                ` · ${delivery.lastError}`}
                                        </span>
                                        {delivery.status !== 'delivered' && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/deliveries/${delivery.id}/redeliver`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Redeliver
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <div className="space-y-3">
                        <h2 className="text-lg font-medium">Provisioning</h2>
                        <Form
                            action={`${base}/provisioning`}
                            method="put"
                            options={{ preserveScroll: true }}
                            className="space-y-3 rounded-lg border p-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="provisioning-method">
                                            Method
                                        </Label>
                                        <select
                                            id="provisioning-method"
                                            name="provisioning_method"
                                            className={selectClass}
                                            defaultValue={
                                                application.provisioningMethod
                                            }
                                        >
                                            <option value="none">None</option>
                                            <option value="manual">
                                                Manual
                                            </option>
                                            <option value="sync_api">
                                                API, synchronous
                                            </option>
                                            <option value="async_api">
                                                API, asynchronous (callback)
                                            </option>
                                        </select>
                                    </div>
                                    <div className="grid gap-1.5">
                                        <Label htmlFor="integration-endpoint">
                                            Base URL (POST {'{base}'}/tenants)
                                        </Label>
                                        <Input
                                            id="integration-endpoint"
                                            name="integration_endpoint"
                                            type="url"
                                            defaultValue={
                                                application.integrationEndpoint ??
                                                ''
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors.integration_endpoint
                                            }
                                        />
                                    </div>
                                    <Button
                                        type="submit"
                                        size="sm"
                                        disabled={processing}
                                    >
                                        Save
                                    </Button>
                                </>
                            )}
                        </Form>

                        {provisioningSteps.length > 0 && (
                            <ul className="divide-y rounded-lg border text-sm">
                                {provisioningSteps.map((step) => (
                                    <li
                                        key={step.id}
                                        className="flex flex-wrap items-center gap-2 p-3"
                                    >
                                        <Badge
                                            variant={
                                                step.status === 'succeeded'
                                                    ? 'secondary'
                                                    : step.status === 'failed'
                                                      ? 'destructive'
                                                      : 'outline'
                                            }
                                        >
                                            {step.status}
                                        </Badge>
                                        <span className="flex-1">
                                            {step.organization}
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {step.attempts} attempts
                                                {step.lastError &&
                                                    ` · ${step.lastError}`}
                                            </span>
                                        </span>
                                        {step.status === 'failed' && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.post(
                                                        `${base}/provisioning/${step.id}/retry`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Retry
                                            </Button>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            </div>
        </>
    );
}
