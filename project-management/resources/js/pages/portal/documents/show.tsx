import { Form, Head, Link, router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select, invalid } from '@/components/field';
import { formatDateTime } from '@/lib/status';
import { archive, index, restore, update } from '@/routes/documents';
import { download, store as addVersion } from '@/routes/documents/versions';
import type { Option } from '@/types';

type DocumentDetails = {
    id: string;
    title: string;
    category: string;
    description: string | null;
    confidential: boolean;
    archived: boolean;
    version: number;
};

type Version = {
    id: string;
    version: number;
    name: string;
    size: number;
    sha256: string;
    note: string | null;
    by: string | null;
    at: string;
};

const keep = { preserveScroll: true };

function fileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * One document: every version of its file, and, for those who manage documents,
 * its details and a new version.
 */
export default function DocumentPage({
    document,
    versions,
    categories,
    canManage,
}: {
    document: DocumentDetails;
    versions: Version[];
    categories: Option[];
    canManage: boolean;
}) {
    const category =
        categories.find((option) => option.value === document.category)
            ?.label ?? document.category;

    return (
        <>
            <Head title={document.title} />
            <p className="mb-2 text-sm">
                <Link href={index()} className="underline underline-offset-2">
                    All documents
                </Link>
            </p>
            <PageHeader
                title={document.title}
                description={[
                    category,
                    document.confidential ? 'Confidential' : null,
                    document.archived ? 'Archived' : null,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                actions={
                    canManage && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                router.post(
                                    (document.archived ? restore : archive)(
                                        document.id,
                                    ).url,
                                    {},
                                    keep,
                                )
                            }
                        >
                            {document.archived ? 'Restore' : 'Archive'}
                        </Button>
                    )
                }
            />

            {document.description && (
                <p className="mb-4 text-sm">{document.description}</p>
            )}

            <Panel>
                <PanelHeader title="Versions" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Version</TableHead>
                            <TableHead>File</TableHead>
                            <TableHead>Added</TableHead>
                            <TableHead>Note</TableHead>
                            <TableHead />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {versions.map((version) => (
                            <TableRow key={version.id}>
                                <TableCell>{version.version}</TableCell>
                                <TableCell>
                                    {version.name}
                                    <span className="block text-xs text-muted-foreground">
                                        {fileSize(version.size)}
                                    </span>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {formatDateTime(version.at)}
                                    <span className="block text-xs">
                                        {version.by ?? 'Unknown'}
                                    </span>
                                </TableCell>
                                <TableCell className="text-muted-foreground">
                                    {version.note ?? '—'}
                                </TableCell>
                                <TableCell className="text-right">
                                    {/* A file, not a page: a plain link. */}
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={
                                                download({
                                                    document: document.id,
                                                    version: version.id,
                                                }).url
                                            }
                                        >
                                            <Download
                                                className="size-4"
                                                aria-hidden
                                            />
                                            Download
                                        </a>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>

            {canManage && !document.archived && (
                <>
                    <Panel className="mt-4">
                        <PanelHeader title="Add a new version" />
                        <PanelBody>
                            <Form
                                {...addVersion.form(document.id)}
                                options={keep}
                                resetOnSuccess
                                className="grid gap-4 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="file"
                                            label="File"
                                            error={errors.file}
                                            hint="Up to 25 MB: PDF, Office, text or pictures."
                                        >
                                            <Input
                                                id="file"
                                                name="file"
                                                type="file"
                                                required
                                                {...invalid(
                                                    'file',
                                                    errors.file,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="note"
                                            label="What changed"
                                            error={errors.note}
                                            hint="Optional."
                                        >
                                            <Input
                                                id="note"
                                                name="note"
                                                maxLength={255}
                                                {...invalid(
                                                    'note',
                                                    errors.note,
                                                )}
                                            />
                                        </Field>
                                        <div className="sm:col-span-2">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Add version
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>

                    <Panel className="mt-4">
                        <PanelHeader title="Details" />
                        <PanelBody>
                            <Form
                                {...update.form(document.id)}
                                options={keep}
                                className="grid gap-4 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="title"
                                            label="Title"
                                            error={errors.title}
                                        >
                                            <Input
                                                id="title"
                                                name="title"
                                                required
                                                maxLength={200}
                                                defaultValue={document.title}
                                                {...invalid(
                                                    'title',
                                                    errors.title,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="category"
                                            label="Category"
                                            error={errors.category}
                                        >
                                            <Select
                                                id="category"
                                                name="category"
                                                options={categories}
                                                defaultValue={document.category}
                                            />
                                        </Field>
                                        <Field
                                            id="description"
                                            label="Description"
                                            error={errors.description}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="description"
                                                name="description"
                                                defaultValue={
                                                    document.description ?? ''
                                                }
                                                {...invalid(
                                                    'description',
                                                    errors.description,
                                                )}
                                            />
                                        </Field>
                                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                            <input
                                                type="checkbox"
                                                name="confidential"
                                                value="1"
                                                defaultChecked={
                                                    document.confidential
                                                }
                                            />
                                            Confidential: only those who manage
                                            documents can see it
                                        </label>
                                        <div className="sm:col-span-2">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Save details
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                </>
            )}
        </>
    );
}
