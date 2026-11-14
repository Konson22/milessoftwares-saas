import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { COLLECTED, FORM_STATUS, PURPOSES } from '@/lib/collect';
import type { CollectedStatus, FormStatus } from '@/lib/collect';
import { formatDateTime } from '@/lib/mel';
import { index as assignmentsPage } from '@/routes/collect/assignments';
import {
    archive,
    build,
    duplicate,
    edit,
    restore,
    version as versionPage,
} from '@/routes/collect/forms';
import { close as closeVersion } from '@/routes/collect/forms/versions';

type Props = {
    form: {
        id: string;
        name: string;
        purpose: string;
        status: FormStatus;
        programme: { id: string; label: string } | null;
        unit: string | null;
        version: number | null;
        description: string | null;
        containsPersonalData: boolean;
        acceptUntil: string | null;
        archivedAt: string | null;
        draftUpdatedAt: string;
        questions: number;
        draftIssues: number;
    };
    versions: {
        id: string;
        number: number;
        note: string | null;
        publishedBy: string;
        publishedAt: string;
        current: boolean;
        closedAt: string | null;
        acceptUntil: string | null;
        accepting: boolean;
        submissions: number;
    }[];
    counts: Record<CollectedStatus, number>;
    collectors: number;
    can: { design: boolean; publish: boolean; assign: boolean; export: boolean };
};

export default function FormPage({
    form,
    versions: history,
    counts,
    collectors,
    can,
}: Props) {
    const archived = form.status === 'archived';
    const total = Object.values(counts).reduce((sum, n) => sum + n, 0);

    return (
        <>
            <Head title={form.name} />
            <PageHeader
                title={form.name}
                description={[
                    PURPOSES[form.purpose] ?? form.purpose,
                    form.programme?.label ?? 'No programme',
                    form.unit,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    <StatusBadge tone={FORM_STATUS[form.status].tone}>
                        {FORM_STATUS[form.status].label}
                        {form.version !== null && ` · version ${form.version}`}
                    </StatusBadge>
                }
                actions={
                    <div className="flex flex-wrap gap-2">
                        {can.design && !archived && (
                            <Button asChild>
                                <Link href={build(form.id)}>
                                    {form.version === null
                                        ? 'Build the form'
                                        : 'Change the form'}
                                </Link>
                            </Button>
                        )}
                        {can.design && !archived && (
                            <Button variant="outline" asChild>
                                <Link href={edit(form.id)}>Details</Link>
                            </Button>
                        )}
                        {can.design && (
                            <Form {...duplicate.form(form.id)}>
                                <Button type="submit" variant="outline">
                                    Duplicate
                                </Button>
                            </Form>
                        )}
                    </div>
                }
            />

            <div className="grid gap-3 lg:grid-cols-3">
                <Panel className="lg:col-span-2">
                    <PanelHeader
                        title="Collected"
                        description={`${total} submission${total === 1 ? '' : 's'} so far.`}
                    />
                    <PanelBody>
                        <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            {(Object.keys(COLLECTED) as CollectedStatus[]).map(
                                (status) => (
                                    <div
                                        key={status}
                                        className="rounded-md border p-3"
                                    >
                                        <dt className="text-xs text-muted-foreground">
                                            {COLLECTED[status].label}
                                        </dt>
                                        <dd className="text-xl font-semibold tabular-nums">
                                            {counts[status]}
                                        </dd>
                                    </div>
                                ),
                            )}
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Collection" />
                    <PanelBody className="grid gap-3 text-sm">
                        <p>
                            <span className="font-medium tabular-nums">
                                {collectors}
                            </span>{' '}
                            {collectors === 1 ? 'person' : 'people'} assigned.{' '}
                            <Link
                                href={assignmentsPage({
                                    query: { form: form.id },
                                })}
                                className="underline"
                            >
                                {can.assign && !archived
                                    ? 'Manage assignments'
                                    : 'See assignments'}
                            </Link>
                        </p>
                        {form.version === null && !archived && (
                            <p className="text-muted-foreground">
                                Not published yet: phones get the form once it
                                is.
                                {form.draftIssues > 0 &&
                                    ` The draft has ${form.draftIssues} issue${form.draftIssues === 1 ? '' : 's'} to fix first.`}
                            </p>
                        )}
                        <p className="text-muted-foreground">
                            {form.containsPersonalData
                                ? 'Answers may identify people.'
                                : 'Answers do not identify people.'}
                        </p>
                        {archived && (
                            <p>
                                Archived {formatDateTime(form.archivedAt)}.{' '}
                                {form.acceptUntil
                                    ? `Submissions still accepted until ${formatDateTime(form.acceptUntil)}.`
                                    : 'No new submissions are accepted.'}
                            </p>
                        )}
                        {form.description && (
                            <p className="whitespace-pre-line">
                                {form.description}
                            </p>
                        )}
                    </PanelBody>
                </Panel>
            </div>

            <Panel className="mt-3">
                <PanelHeader
                    title="Versions"
                    description="Each published version stays as it was. Submissions are always shown with the version they were filled in on."
                />
                {history.length === 0 ? (
                    <PanelBody className="text-sm text-muted-foreground">
                        No version published yet.
                    </PanelBody>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Version</TableHead>
                                <TableHead>Published</TableHead>
                                <TableHead>Accepting</TableHead>
                                <TableHead className="text-right">
                                    Submissions
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {history.map((version) => (
                                <TableRow key={version.id}>
                                    <TableCell>
                                        <Link
                                            href={versionPage([
                                                form.id,
                                                version.id,
                                            ])}
                                            className="font-medium hover:underline"
                                        >
                                            Version {version.number}
                                        </Link>
                                        {version.current && (
                                            <StatusBadge
                                                tone="success"
                                                className="ml-2"
                                            >
                                                In use
                                            </StatusBadge>
                                        )}
                                        {version.note && (
                                            <div className="text-xs text-muted-foreground">
                                                {version.note}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDateTime(version.publishedAt)}{' '}
                                        by {version.publishedBy}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {version.current
                                            ? 'Yes'
                                            : version.accepting
                                              ? `Until ${formatDateTime(version.acceptUntil)}`
                                              : 'No'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {version.submissions}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {can.publish &&
                                            !version.current &&
                                            version.accepting && (
                                                <Form
                                                    {...closeVersion.form([
                                                        form.id,
                                                        version.id,
                                                    ])}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="ghost"
                                                    >
                                                        Stop accepting now
                                                    </Button>
                                                </Form>
                                            )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>

            {can.publish && (
                <Panel className="mt-3">
                    <PanelHeader
                        title={archived ? 'Restore' : 'Archive'}
                        description={
                            archived
                                ? 'Restored, the form can be changed and collected with again.'
                                : 'Archived, the form leaves the phones and the list. Everything collected stays.'
                        }
                    />
                    <PanelBody>
                        {archived ? (
                            <Form {...restore.form(form.id)}>
                                <Button type="submit" variant="outline">
                                    Restore the form
                                </Button>
                            </Form>
                        ) : (
                            <Form
                                {...archive.form(form.id)}
                                className="flex flex-wrap items-end gap-2"
                            >
                                {({ processing }) => (
                                    <>
                                        <label className="grid gap-1.5 text-sm">
                                            Accept submissions already filled
                                            in for
                                            <select
                                                name="accept_days"
                                                defaultValue="7"
                                                className="h-9 rounded-md border bg-transparent px-3"
                                            >
                                                <option value="0">
                                                    No more days
                                                </option>
                                                <option value="7">7 days</option>
                                                <option value="30">
                                                    30 days
                                                </option>
                                            </select>
                                        </label>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Archive the form
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
