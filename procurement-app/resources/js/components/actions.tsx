import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
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
import { Errors, Status, TextArea } from '@/components/fields';
import type { Approval, Documents } from '@/lib/procurement';
import { formatDateTime } from '@/lib/procurement';
import { download, store as storeDocument } from '@/routes/documents';

/**
 * A button that posts, optionally after asking for a reason. Errors show
 * under the button.
 */
export function ActionButton({
    url,
    label,
    reason,
    reasonOptional,
    variant = 'default',
    confirm,
    method = 'post',
}: {
    url: string;
    label: string;
    reason?: string;
    reasonOptional?: boolean;
    variant?: 'default' | 'outline' | 'destructive' | 'secondary';
    confirm?: string;
    method?: 'post' | 'delete';
}) {
    const [open, setOpen] = useState(false);
    const [text, setText] = useState('');
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    const send = (data: Record<string, string>) => {
        setBusy(true);
        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                setText('');
            },
            onFinish: () => setBusy(false),
        });
    };

    if (reason && open) {
        return (
            <div className="grid w-full gap-2 rounded-md border p-3">
                <TextArea
                    label={reason}
                    value={text}
                    onChange={setText}
                    error={errors.reason}
                    rows={2}
                />
                <div className="flex gap-2">
                    <Button
                        size="sm"
                        variant={variant}
                        disabled={
                            busy || (!reasonOptional && text.trim() === '')
                        }
                        onClick={() => send({ reason: text })}
                    >
                        {label}
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => setOpen(false)}
                    >
                        Back
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <Button
            size="sm"
            variant={variant}
            disabled={busy}
            onClick={() => {
                if (reason) {
                    setOpen(true);
                } else if (!confirm || window.confirm(confirm)) {
                    send({});
                }
            }}
        >
            {label}
        </Button>
    );
}

/**
 * The approval block: where the document stands, the decision form for
 * whoever may decide the waiting step, and every decision so far.
 */
export function ApprovalPanel({
    approval,
    decideUrl,
}: {
    approval: Approval;
    decideUrl: string;
}) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    if (approval.history.length === 0) {
        return null;
    }

    const decide = (decision: 'approve' | 'reject' | 'return') => {
        setBusy(true);
        router.post(
            decideUrl,
            { decision, note },
            {
                preserveScroll: true,
                onSuccess: () => setNote(''),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <Panel>
            <PanelHeader
                title="Approvals"
                description={
                    approval.waiting
                        ? `Waiting: ${approval.waiting}`
                        : 'No step is waiting.'
                }
            />
            <PanelBody className="grid gap-4">
                {approval.waiting && approval.canDecide && (
                    <div className="grid gap-2 rounded-md border p-3">
                        <TextArea
                            label="Note (needed to reject or return)"
                            value={note}
                            onChange={setNote}
                            error={errors.note}
                            rows={2}
                        />
                        <Errors
                            errors={
                                errors.decision
                                    ? { decision: errors.decision }
                                    : {}
                            }
                        />
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                disabled={busy}
                                onClick={() => decide('approve')}
                            >
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy}
                                onClick={() => decide('return')}
                            >
                                Return for changes
                            </Button>
                            <Button
                                size="sm"
                                variant="destructive"
                                disabled={busy}
                                onClick={() => decide('reject')}
                            >
                                Reject
                            </Button>
                        </div>
                    </div>
                )}
                {approval.waiting &&
                    !approval.canDecide &&
                    approval.refusal && (
                        <p className="text-sm text-muted-foreground">
                            {approval.refusal}
                        </p>
                    )}
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Round</TableHead>
                            <TableHead>Step</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Decided by</TableHead>
                            <TableHead>Note</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {approval.history.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>{row.round}</TableCell>
                                <TableCell>
                                    {row.step}. {row.approver}
                                </TableCell>
                                <TableCell>
                                    <Status value={row.status} />
                                </TableCell>
                                <TableCell>
                                    {row.decidedBy ?? '—'}
                                    {row.decidedAt && (
                                        <span className="block text-xs text-muted-foreground">
                                            {formatDateTime(row.decidedAt)}
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="max-w-xs whitespace-normal">
                                    {row.note ?? ''}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </PanelBody>
        </Panel>
    );
}

/**
 * Supporting documents of a record: download, and upload when allowed.
 */
export function DocumentsPanel({ documents }: { documents: Documents }) {
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <Panel>
            <PanelHeader title="Documents" />
            <PanelBody className="grid gap-3">
                {documents.items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No documents yet.
                    </p>
                ) : (
                    <ul className="grid gap-1 text-sm">
                        {documents.items.map((doc) => (
                            <li key={doc.id}>
                                <a
                                    href={download(doc.id).url}
                                    className="hover:underline"
                                >
                                    {doc.name}
                                </a>{' '}
                                <span className="text-xs text-muted-foreground">
                                    {Math.max(1, Math.round(doc.size / 1024))}{' '}
                                    KB · {formatDateTime(doc.uploadedAt)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {documents.canAdd && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Input
                            type="file"
                            className="max-w-xs"
                            onChange={(e) =>
                                setFile(e.target.files?.[0] ?? null)
                            }
                        />
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!file || busy}
                            onClick={() => {
                                if (!file) {
                                    return;
                                }
                                setBusy(true);
                                router.post(
                                    storeDocument({
                                        type: documents.type,
                                        id: documents.id,
                                    }).url,
                                    { file },
                                    {
                                        forceFormData: true,
                                        preserveScroll: true,
                                        onSuccess: () => setFile(null),
                                        onFinish: () => setBusy(false),
                                    },
                                );
                            }}
                        >
                            Upload
                        </Button>
                        {errors.file && (
                            <p className="text-xs text-destructive">
                                {errors.file}
                            </p>
                        )}
                    </div>
                )}
            </PanelBody>
        </Panel>
    );
}
