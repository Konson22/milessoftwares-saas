import { Form, Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
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
import { formatDate } from '@/lib/status';
import { index, show, store } from '@/routes/documents';
import type { Option } from '@/types';

type DocumentRow = {
    id: string;
    title: string;
    category: string;
    confidential: boolean;
    archived: boolean;
    version: number;
    updatedAt: string;
};

/**
 * Documents: the organization's policies, contracts, certificates and the like.
 */
export default function Documents({
    documents,
    categories,
    filters,
    canManage,
}: {
    documents: {
        data: DocumentRow[];
        currentPage: number;
        lastPage: number;
        total: number;
    };
    categories: Option[];
    filters: { category: string };
    canManage: boolean;
}) {
    const label = (category: string) =>
        categories.find((option) => option.value === category)?.label ??
        category;
    const visit = (query: Record<string, string | number>) =>
        router.get(index.url({ query }), {}, { preserveScroll: true });

    return (
        <>
            <Head title="Documents" />
            <PageHeader
                title="Documents"
                description="Your organization's policies, contracts, certificates and other records, with every version kept."
            />

            {canManage && (
                <Panel className="mb-4">
                    <PanelHeader title="Add a document" />
                    <PanelBody>
                        <Form
                            {...store.form()}
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
                                            {...invalid('title', errors.title)}
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
                                        />
                                    </Field>
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
                                            {...invalid('file', errors.file)}
                                        />
                                    </Field>
                                    <Field
                                        id="description"
                                        label="Description"
                                        error={errors.description}
                                        hint="Optional."
                                    >
                                        <Input
                                            id="description"
                                            name="description"
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
                                        />
                                        Confidential: only those who manage
                                        documents can see it
                                    </label>
                                    <div className="sm:col-span-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add document
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelToolbar>
                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                        Category
                        <Select
                            aria-label="Category"
                            placeholder="All"
                            options={categories}
                            value={filters.category}
                            onChange={(event) =>
                                visit(
                                    event.target.value
                                        ? { category: event.target.value }
                                        : {},
                                )
                            }
                        />
                    </label>
                    <span className="ml-auto text-sm text-muted-foreground">
                        {documents.total}{' '}
                        {documents.total === 1 ? 'document' : 'documents'}
                    </span>
                </PanelToolbar>
                {documents.data.length === 0 ? (
                    <EmptyState icon={FileText} title="No documents yet" />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Title</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Version</TableHead>
                                <TableHead>Updated</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {documents.data.map((document) => (
                                <TableRow key={document.id}>
                                    <TableCell>
                                        <Link
                                            href={show(document.id)}
                                            className="font-medium underline-offset-2 hover:underline"
                                        >
                                            {document.title}
                                        </Link>
                                        {document.confidential && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                Confidential
                                            </span>
                                        )}
                                        {document.archived && (
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                Archived
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {label(document.category)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {document.version}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDate(document.updatedAt)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
                {documents.lastPage > 1 && (
                    <div className="flex items-center justify-between gap-3 border-t px-4 py-3 text-sm">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={documents.currentPage <= 1}
                            onClick={() =>
                                visit({
                                    ...(filters.category
                                        ? { category: filters.category }
                                        : {}),
                                    page: documents.currentPage - 1,
                                })
                            }
                        >
                            Previous
                        </Button>
                        <span className="text-muted-foreground">
                            Page {documents.currentPage} of {documents.lastPage}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={
                                documents.currentPage >= documents.lastPage
                            }
                            onClick={() =>
                                visit({
                                    ...(filters.category
                                        ? { category: filters.category }
                                        : {}),
                                    page: documents.currentPage + 1,
                                })
                            }
                        >
                            Next
                        </Button>
                    </div>
                )}
            </Panel>
        </>
    );
}
