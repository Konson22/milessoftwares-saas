import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
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
import { Field, formatDate, SelectField, TextField } from '@/components/fields';
import type { Option } from '@/components/fields';
import { destroy, open } from '@/routes/documents';
import { show } from '@/routes/employees';
import { store } from '@/routes/employees/documents';

type Document = {
    id: string;
    category: string;
    title: string;
    issuedOn: string | null;
    expiresOn: string | null;
    expiry: 'expired' | 'expiring' | null;
    note: string | null;
    fileName: string;
    size: number;
    uploadedBy: string;
    uploadedAt: string;
    removedBy: string | null;
    removedAt: string | null;
    canRemove: boolean;
};

type Props = {
    employee: { id: string; number: string; name: string; status: string };
    documents: Document[];
    /** Identity documents the member may not see. */
    hidden: number;
    canUpload: boolean;
    categories: Option[];
    limits: { types: string[]; megabytes: number };
};

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

export default function Documents({
    employee,
    documents,
    hidden,
    canUpload,
    categories,
    limits,
}: Props) {
    const [uploading, setUploading] = useState(false);
    const [removing, setRemoving] = useState<string | null>(null);
    const current = documents.filter((document) => !document.removedAt);
    const removed = documents.filter((document) => document.removedAt);

    return (
        <>
            <Head title={`${employee.name}: documents`} />
            <PageHeader
                title="Documents"
                description={
                    <>
                        <Link
                            href={show(employee.id)}
                            className="hover:underline"
                        >
                            {employee.name}
                        </Link>{' '}
                        · {employee.number} · Downloads are recorded in the HR
                        audit log.
                    </>
                }
                actions={
                    canUpload && !uploading ? (
                        <Button onClick={() => setUploading(true)}>
                            Upload a document
                        </Button>
                    ) : undefined
                }
            />

            {uploading && (
                <Panel className="mb-3">
                    <PanelHeader
                        title="Upload a document"
                        description={`${limits.types
                            .filter((type) => type !== 'jpeg')
                            .map((type) => type.toUpperCase())
                            .join(', ')}, up to ${limits.megabytes} MB.`}
                    />
                    <PanelBody>
                        <Form
                            {...store.form(employee.id)}
                            onSuccess={() => setUploading(false)}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="file"
                                        label="File"
                                        error={errors.file}
                                        className="sm:col-span-2"
                                    >
                                        <Input
                                            id="file"
                                            name="file"
                                            type="file"
                                            accept={limits.types
                                                .map((type) => `.${type}`)
                                                .join(',')}
                                            required
                                        />
                                    </Field>
                                    <SelectField
                                        name="category"
                                        label="Kind"
                                        options={categories}
                                        error={errors.category}
                                    />
                                    <TextField
                                        name="title"
                                        label="Title"
                                        error={errors.title}
                                        required
                                    />
                                    <TextField
                                        name="issued_on"
                                        label="Issued on"
                                        type="date"
                                        error={errors.issued_on}
                                    />
                                    <TextField
                                        name="expires_on"
                                        label="Expires on"
                                        type="date"
                                        error={errors.expires_on}
                                    />
                                    <div className="sm:col-span-2">
                                        <TextField
                                            name="note"
                                            label="Note"
                                            error={errors.note}
                                        />
                                    </div>
                                    <div className="flex gap-2 sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Upload
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() => setUploading(false)}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel className="mb-3">
                <PanelHeader
                    title="On file"
                    description={
                        hidden > 0
                            ? `${hidden} identity ${hidden === 1 ? 'document is' : 'documents are'} only shown to those who can see personal details.`
                            : undefined
                    }
                />
                {current.length === 0 ? (
                    <p className="px-3 py-4 text-sm text-muted-foreground">
                        No documents yet.
                    </p>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Document</TableHead>
                                <TableHead>Issued</TableHead>
                                <TableHead>Expires</TableHead>
                                <TableHead>Uploaded</TableHead>
                                <TableHead className="text-right">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {current.map((document) => (
                                <TableRow key={document.id}>
                                    <TableCell>
                                        {/* A file, not a page: a plain link. */}
                                        <a
                                            href={open.url(document.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {document.title}
                                        </a>
                                        <span className="block text-xs text-muted-foreground">
                                            {document.category} ·{' '}
                                            {document.fileName} ·{' '}
                                            {formatSize(document.size)}
                                        </span>
                                        {document.note && (
                                            <span className="block text-xs text-muted-foreground">
                                                {document.note}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(document.issuedOn)}
                                    </TableCell>
                                    <TableCell className="tabular-nums">
                                        {formatDate(document.expiresOn)}
                                        {document.expiry === 'expired' && (
                                            <StatusBadge
                                                tone="danger"
                                                className="ml-2"
                                            >
                                                Expired
                                            </StatusBadge>
                                        )}
                                        {document.expiry === 'expiring' && (
                                            <StatusBadge
                                                tone="warning"
                                                className="ml-2"
                                            >
                                                Expires soon
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {formatDate(
                                            document.uploadedAt.slice(0, 10),
                                        )}
                                        <span className="block text-xs text-muted-foreground">
                                            {document.uploadedBy}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {document.canRemove &&
                                            (removing === document.id ? (
                                                <span className="inline-flex gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="destructive"
                                                        onClick={() =>
                                                            router.delete(
                                                                destroy.url(
                                                                    document.id,
                                                                ),
                                                                {
                                                                    preserveScroll: true,
                                                                    onFinish:
                                                                        () =>
                                                                            setRemoving(
                                                                                null,
                                                                            ),
                                                                },
                                                            )
                                                        }
                                                    >
                                                        Remove for good
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            setRemoving(null)
                                                        }
                                                    >
                                                        Keep
                                                    </Button>
                                                </span>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        setRemoving(document.id)
                                                    }
                                                >
                                                    Remove
                                                    <span className="sr-only">
                                                        {' '}
                                                        {document.title}
                                                    </span>
                                                </Button>
                                            ))}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {removed.length > 0 && (
                <Panel>
                    <PanelHeader
                        title="Removed"
                        description="The files are deleted; the record of them stays."
                    />
                    <Table>
                        <TableBody>
                            {removed.map((document) => (
                                <TableRow key={document.id}>
                                    <TableCell>
                                        {document.title}
                                        <span className="block text-xs text-muted-foreground">
                                            {document.category}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        Removed{' '}
                                        {formatDate(
                                            document.removedAt?.slice(0, 10) ??
                                                null,
                                        )}{' '}
                                        by {document.removedBy}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>
            )}
        </>
    );
}
