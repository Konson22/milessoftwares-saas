import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
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
import { Layers } from 'lucide-react';
import { Field, invalid, Select } from '@/components/field';
import { Progress, Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import { archive, index, restore, update } from '@/routes/programmes';
import {
    destroy as removeProject,
    store as addProject,
} from '@/routes/programmes/projects';
import { show as showProject } from '@/routes/projects';
import type { Option } from '@/types';

type Props = {
    programme: {
        id: string;
        code: string;
        name: string;
        description: string | null;
        manager: string | null;
        managerId: string | null;
        archived: boolean;
    };
    projects: {
        id: string;
        code: string;
        name: string;
        manager: string | null;
        status: string;
        dueOn: string | null;
        progress: number;
        health: string | null;
        overdueTasks: number;
        budgetMinor: number | null;
        actualMinor: number | null;
        currency: string;
    }[];
    summary: { projects: number; open: number; overdueTasks: number };
    totals:
        | {
              currency: string;
              budgetMinor: number;
              actualMinor: number;
              cancelledActualMinor: number;
          }[]
        | null;
    available: Option[];
    people: Option[];
    canManage: boolean;
};

/**
 * One programme: how its projects stand, together and each on its own.
 */
export default function Programme({
    programme,
    projects,
    summary,
    totals,
    available,
    people,
    canManage,
}: Props) {
    const [editing, setEditing] = useState(false);
    const manage = canManage && !programme.archived;

    return (
        <>
            <Head title={`${programme.code} ${programme.name}`} />
            <PageHeader
                title={`${programme.code} · ${programme.name}`}
                description={
                    <>
                        <Link href={index()} className="hover:underline">
                            Programmes
                        </Link>
                        {programme.manager && ` · ${programme.manager}`}
                        {programme.archived && ' · archived'}
                    </>
                }
                actions={
                    canManage && (
                        <span className="flex items-center gap-2">
                            {manage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() =>
                                        setEditing((value) => !value)
                                    }
                                >
                                    {editing ? 'Close' : 'Edit'}
                                </Button>
                            )}
                            <Form
                                {...(programme.archived
                                    ? restore.form(programme.id)
                                    : archive.form(programme.id))}
                            >
                                {({ errors, processing }) => (
                                    <span className="flex items-center gap-2">
                                        {errors.programme && (
                                            <span
                                                role="alert"
                                                className="text-xs text-destructive"
                                            >
                                                {errors.programme}
                                            </span>
                                        )}
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            {programme.archived
                                                ? 'Restore'
                                                : 'Archive'}
                                        </Button>
                                    </span>
                                )}
                            </Form>
                        </span>
                    )
                }
            />

            <div className="grid gap-4">
                {editing && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Edit programme" />
                        <PanelBody>
                            <Form
                                {...update.form(programme.id)}
                                onSuccess={() => setEditing(false)}
                                className="grid gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="code"
                                            label="Code"
                                            error={errors.code}
                                        >
                                            <Input
                                                id="code"
                                                name="code"
                                                required
                                                maxLength={10}
                                                defaultValue={programme.code}
                                                {...invalid(
                                                    'code',
                                                    errors.code,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="name"
                                            label="Name"
                                            error={errors.name}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="name"
                                                name="name"
                                                required
                                                maxLength={255}
                                                defaultValue={programme.name}
                                                {...invalid(
                                                    'name',
                                                    errors.name,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="description"
                                            label="Description"
                                            error={errors.description}
                                            className="sm:col-span-3"
                                        >
                                            <textarea
                                                id="description"
                                                name="description"
                                                rows={3}
                                                maxLength={5000}
                                                defaultValue={
                                                    programme.description ?? ''
                                                }
                                                className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                                            />
                                        </Field>
                                        <Field
                                            id="manager_id"
                                            label="Manager"
                                            error={errors.manager_id}
                                            className="sm:col-span-3"
                                        >
                                            <Select
                                                id="manager_id"
                                                name="manager_id"
                                                defaultValue={
                                                    programme.managerId ?? ''
                                                }
                                                placeholder="Not yet chosen"
                                                options={people}
                                            />
                                        </Field>
                                        <div className="sm:col-span-3">
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Save programme
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                )}

                {programme.description && !editing && (
                    <Panel>
                        <PanelBody>
                            <p className="text-sm whitespace-pre-wrap">
                                {programme.description}
                            </p>
                        </PanelBody>
                    </Panel>
                )}

                <Panel>
                    <PanelHeader title="Together" />
                    <PanelBody>
                        <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm">
                            <div>
                                <dt className="text-muted-foreground">
                                    Open projects
                                </dt>
                                <dd className="font-medium tabular-nums">
                                    {summary.open} of {summary.projects}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-muted-foreground">
                                    Overdue tasks
                                </dt>
                                <dd
                                    className={
                                        summary.overdueTasks > 0
                                            ? 'font-medium text-destructive tabular-nums'
                                            : 'font-medium tabular-nums'
                                    }
                                >
                                    {summary.overdueTasks}
                                </dd>
                            </div>
                            {totals?.map((total) => (
                                <div key={total.currency}>
                                    <dt className="text-muted-foreground">
                                        Spent / budget ({total.currency})
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {formatMoney(
                                            total.actualMinor,
                                            total.currency,
                                        )}{' '}
                                        /{' '}
                                        {formatMoney(
                                            total.budgetMinor,
                                            total.currency,
                                        )}
                                    </dd>
                                    {total.cancelledActualMinor > 0 && (
                                        <dd className="text-xs text-muted-foreground tabular-nums">
                                            Includes{' '}
                                            {formatMoney(
                                                total.cancelledActualMinor,
                                                total.currency,
                                            )}{' '}
                                            spent on cancelled projects
                                        </dd>
                                    )}
                                </div>
                            ))}
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Projects" />
                    {projects.length === 0 ? (
                        <EmptyState
                            icon={Layers}
                            title="No projects in this programme yet"
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Project</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="w-48">
                                        Progress
                                    </TableHead>
                                    <TableHead>Due</TableHead>
                                    {totals && (
                                        <TableHead className="text-right">
                                            Spent / budget
                                        </TableHead>
                                    )}
                                    {manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {projects.map((project) => (
                                    <TableRow key={project.id}>
                                        <TableCell>
                                            <Link
                                                href={showProject({
                                                    project: project.id,
                                                })}
                                                className="font-medium hover:underline"
                                            >
                                                <span className="text-muted-foreground">
                                                    {project.code}
                                                </span>{' '}
                                                {project.name}
                                            </Link>
                                            {project.manager && (
                                                <p className="text-xs text-muted-foreground">
                                                    {project.manager}
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-wrap gap-1">
                                                <Status
                                                    status={project.status}
                                                />
                                                {project.health && (
                                                    <Status
                                                        status={project.health}
                                                    />
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <Progress
                                                value={project.progress}
                                            />
                                            {project.overdueTasks > 0 && (
                                                <p className="text-xs text-destructive">
                                                    {project.overdueTasks}{' '}
                                                    overdue
                                                </p>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {project.dueOn
                                                ? formatDate(project.dueOn)
                                                : '—'}
                                        </TableCell>
                                        {totals && (
                                            <TableCell className="text-right tabular-nums">
                                                {formatMoney(
                                                    project.actualMinor ?? 0,
                                                    project.currency,
                                                )}{' '}
                                                /{' '}
                                                {formatMoney(
                                                    project.budgetMinor ?? 0,
                                                    project.currency,
                                                )}
                                            </TableCell>
                                        )}
                                        {manage && (
                                            <TableCell className="text-right">
                                                <Form
                                                    {...removeProject.form({
                                                        programme: programme.id,
                                                        project: project.id,
                                                    })}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                    >
                                                        Take out
                                                    </Button>
                                                </Form>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    {manage && available.length > 0 && (
                        <PanelBody>
                            <Form
                                {...addProject.form(programme.id)}
                                options={{ preserveScroll: true }}
                                className="flex items-end gap-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="project_id"
                                            label="Put a project in this programme"
                                            error={
                                                errors.project_id ??
                                                errors.programme_id
                                            }
                                            className="max-w-md flex-1"
                                        >
                                            <Select
                                                id="project_id"
                                                name="project_id"
                                                required
                                                options={available}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Add
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>
            </div>
        </>
    );
}
