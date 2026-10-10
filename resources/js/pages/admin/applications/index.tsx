import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Application = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    kind: string;
    status: string;
    routePrefix: string | null;
    tagline: string | null;
    features: string;
    sortOrder: number;
    catalogVersions: {
        version: string;
        status: string;
        current: boolean;
        publishedAt: string | null;
    }[];
};

type Props = { applications: Application[] };

const statuses = ['planned', 'beta', 'available', 'deprecated', 'retired'];
const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm';

export default function ApplicationsIndex({ applications }: Props) {
    return (
        <>
            <Head title="Applications" />

            <h1 className="text-2xl font-semibold">Applications</h1>
            <p className="mt-1 text-sm text-muted-foreground">
                The registry every workspace, plan and role catalog refers to.
                Catalog versions are immutable once published.
            </p>

            <section className="mt-6 max-w-xl rounded-lg border p-4">
                <h2 className="font-medium">Import a role catalog</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    A JSON catalog creates the application if needed and
                    publishes a new version.
                </p>
                <Form
                    action="/applications/catalogs"
                    method="post"
                    resetOnSuccess
                    className="mt-3 flex flex-wrap items-start gap-2"
                >
                    {({ errors, processing }) => (
                        <>
                            <Input
                                name="catalog"
                                type="file"
                                accept="application/json,.json"
                                required
                                className="max-w-xs"
                                aria-label="Catalog file"
                            />
                            <Button type="submit" disabled={processing}>
                                Import
                            </Button>
                            <InputError
                                message={errors.catalog}
                                className="w-full"
                            />
                        </>
                    )}
                </Form>
            </section>

            <ul className="mt-8 space-y-4">
                {applications.map((application) => (
                    <li key={application.id} className="rounded-lg border p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="font-medium">{application.name}</p>
                            <code className="text-xs text-muted-foreground">
                                {application.code}
                            </code>
                            <Badge variant="outline">{application.kind}</Badge>
                            <Badge variant="secondary">
                                {application.status}
                            </Badge>
                        </div>

                        {application.kind !== 'platform' && (
                            <Form
                                action={`/applications/${application.id}`}
                                method="put"
                                options={{ preserveScroll: true }}
                                className="mt-4 grid gap-3 sm:grid-cols-4"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1.5 sm:col-span-2">
                                            <Label
                                                htmlFor={`name-${application.id}`}
                                            >
                                                Name
                                            </Label>
                                            <Input
                                                id={`name-${application.id}`}
                                                name="name"
                                                defaultValue={application.name}
                                                required
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`status-${application.id}`}
                                            >
                                                Status
                                            </Label>
                                            <select
                                                id={`status-${application.id}`}
                                                name="status"
                                                defaultValue={
                                                    application.status
                                                }
                                                className={selectClass}
                                            >
                                                {statuses.map((status) => (
                                                    <option key={status}>
                                                        {status}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`route-${application.id}`}
                                            >
                                                Workspace path
                                            </Label>
                                            <Input
                                                id={`route-${application.id}`}
                                                name="route_prefix"
                                                defaultValue={
                                                    application.routePrefix ??
                                                    ''
                                                }
                                            />
                                            <InputError
                                                message={errors.route_prefix}
                                            />
                                        </div>
                                        <div className="grid gap-1.5 sm:col-span-3">
                                            <Label
                                                htmlFor={`description-${application.id}`}
                                            >
                                                Description
                                            </Label>
                                            <Input
                                                id={`description-${application.id}`}
                                                name="description"
                                                defaultValue={
                                                    application.description ??
                                                    ''
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5 sm:col-span-4">
                                            <Label
                                                htmlFor={`tagline-${application.id}`}
                                            >
                                                Public tagline
                                            </Label>
                                            <Input
                                                id={`tagline-${application.id}`}
                                                name="tagline"
                                                maxLength={200}
                                                defaultValue={
                                                    application.tagline ?? ''
                                                }
                                            />
                                        </div>
                                        <div className="grid gap-1.5 sm:col-span-4">
                                            <Label
                                                htmlFor={`features-${application.id}`}
                                            >
                                                Public feature list (one per
                                                line)
                                            </Label>
                                            <textarea
                                                id={`features-${application.id}`}
                                                name="features"
                                                rows={4}
                                                defaultValue={
                                                    application.features
                                                }
                                                className="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                                            />
                                        </div>
                                        <div className="grid gap-1.5">
                                            <Label
                                                htmlFor={`sort-${application.id}`}
                                            >
                                                Order
                                            </Label>
                                            <Input
                                                id={`sort-${application.id}`}
                                                name="sort_order"
                                                type="number"
                                                min={0}
                                                defaultValue={
                                                    application.sortOrder
                                                }
                                            />
                                        </div>
                                        <div className="sm:col-span-4">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Save
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        )}

                        {application.catalogVersions.length > 0 && (
                            <p className="mt-3 text-sm text-muted-foreground">
                                Catalog:{' '}
                                {application.catalogVersions
                                    .map(
                                        (version) =>
                                            `${version.version}${version.current ? ' (current)' : ` (${version.status})`}`,
                                    )
                                    .join(', ')}
                            </p>
                        )}
                    </li>
                ))}
            </ul>
        </>
    );
}
