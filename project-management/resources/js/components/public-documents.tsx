import { FileText } from 'lucide-react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { download } from '@/routes/marketplace/documents';

/** Documents an organization published with its profile or a listing. */
export function PublicDocuments({
    documents,
}: {
    documents: { id: string; title: string }[];
}) {
    if (documents.length === 0) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader title="Documents" />
            <PanelBody>
                <ul className="grid gap-2 text-sm">
                    {documents.map((document) => (
                        <li key={document.id}>
                            {/* A download: a full request, not an Inertia visit. */}
                            <a
                                href={download.url({ document: document.id })}
                                className="inline-flex items-center gap-2 text-primary hover:underline"
                            >
                                <FileText className="size-4" aria-hidden />
                                {document.title}
                            </a>
                        </li>
                    ))}
                </ul>
            </PanelBody>
        </Panel>
    );
}
