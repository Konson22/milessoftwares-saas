import { Form, router } from '@inertiajs/react';
import { Check, Flag, RotateCcw, Trash2, Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
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
import { Field, invalid, Select } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { Progress } from '@/components/status';
import { formatDate, formatMinutes } from '@/lib/status';
import {
    destroy as removeMember,
    store as addMember,
} from '@/routes/projects/members';
import {
    destroy as removeMilestone,
    store as addMilestone,
    toggle,
} from '@/routes/projects/milestones';
import type { Option, ProjectHeader } from '@/types';

type Props = {
    project: ProjectHeader;
    members: {
        id: string;
        personId: string;
        name: string;
        title: string | null;
        role: string | null;
        allocation: number;
        totalAllocation: number;
        openTasks: number;
        minutes: number;
    }[];
    people: Option[];
    milestones: {
        id: string;
        name: string;
        dueOn: string;
        completedAt: string | null;
        tasks: number;
        done: number;
        late: boolean;
    }[];
};

/**
 * Who works on the project and how much of their time it takes, and the
 * milestones it's heading for.
 */
export default function Team({ project, members, people, milestones }: Props) {
    const manage = project.can.manage;

    return (
        <ProjectFrame project={project} title="Team">
            <div className="grid gap-4 lg:grid-cols-[3fr_2fr]">
                <Panel>
                    <PanelHeader
                        title="Team"
                        description="Allocation is the share of each person's time; over 100% across projects means they're overbooked."
                    />
                    {members.length === 0 ? (
                        <EmptyState
                            icon={Users}
                            title="Nobody on the team yet"
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Person</TableHead>
                                    <TableHead className="text-right">
                                        Here
                                    </TableHead>
                                    <TableHead className="text-right">
                                        All projects
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Open tasks
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Logged
                                    </TableHead>
                                    {manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {members.map((member) => (
                                    <TableRow key={member.id}>
                                        <TableCell>
                                            <span className="font-medium">
                                                {member.name}
                                            </span>
                                            <p className="text-xs text-muted-foreground">
                                                {member.role ??
                                                    member.title ??
                                                    'Team member'}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {member.allocation}%
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {member.totalAllocation > 100 ? (
                                                <StatusBadge tone="danger">
                                                    {member.totalAllocation}%
                                                </StatusBadge>
                                            ) : (
                                                <span className="tabular-nums">
                                                    {member.totalAllocation}%
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {member.openTasks}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMinutes(member.minutes)}
                                        </TableCell>
                                        {manage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Take ${member.name} off the team`}
                                                    onClick={() =>
                                                        router.delete(
                                                            removeMember.url({
                                                                project:
                                                                    project.id,
                                                                member: member.id,
                                                            }),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    {manage && people.length > 0 && (
                        <PanelBody className="border-t">
                            <Form
                                {...addMember.form(project.id)}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-[2fr_2fr_1fr_auto] sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="member-person"
                                            label="Add someone"
                                            error={errors.person_id}
                                        >
                                            <Select
                                                id="member-person"
                                                name="person_id"
                                                required
                                                defaultValue=""
                                                placeholder="Choose"
                                                options={people}
                                            />
                                        </Field>
                                        <Field
                                            id="member-role"
                                            label="Role here"
                                            error={errors.role}
                                        >
                                            <Input
                                                id="member-role"
                                                name="role"
                                                placeholder="Developer"
                                            />
                                        </Field>
                                        <Field
                                            id="member-allocation"
                                            label="Time %"
                                            error={errors.allocation_percent}
                                        >
                                            <Input
                                                id="member-allocation"
                                                name="allocation_percent"
                                                type="number"
                                                min={1}
                                                max={100}
                                                required
                                                defaultValue={50}
                                                {...invalid(
                                                    'member-allocation',
                                                    errors.allocation_percent,
                                                )}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
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

                <Panel>
                    <PanelHeader title="Milestones" />
                    {milestones.length === 0 ? (
                        <EmptyState icon={Flag} title="No milestones yet" />
                    ) : (
                        <ul className="divide-y">
                            {milestones.map((milestone) => (
                                <li
                                    key={milestone.id}
                                    className="grid gap-1 px-3 py-2 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span
                                            className={
                                                milestone.completedAt
                                                    ? 'flex-1 text-muted-foreground line-through'
                                                    : 'flex-1 font-medium'
                                            }
                                        >
                                            {milestone.name}
                                        </span>
                                        {milestone.late && (
                                            <StatusBadge tone="danger">
                                                Late
                                            </StatusBadge>
                                        )}
                                        <span className="text-xs text-muted-foreground">
                                            {formatDate(milestone.dueOn)}
                                        </span>
                                        {manage && (
                                            <>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={
                                                        milestone.completedAt
                                                            ? `Reopen ${milestone.name}`
                                                            : `Mark ${milestone.name} reached`
                                                    }
                                                    onClick={() =>
                                                        router.post(
                                                            toggle.url({
                                                                project:
                                                                    project.id,
                                                                milestone:
                                                                    milestone.id,
                                                            }),
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    {milestone.completedAt ? (
                                                        <RotateCcw className="size-4" />
                                                    ) : (
                                                        <Check className="size-4" />
                                                    )}
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label={`Remove ${milestone.name}`}
                                                    onClick={() =>
                                                        router.delete(
                                                            removeMilestone.url(
                                                                {
                                                                    project:
                                                                        project.id,
                                                                    milestone:
                                                                        milestone.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                    {milestone.tasks > 0 && (
                                        <Progress
                                            value={Math.round(
                                                (milestone.done /
                                                    milestone.tasks) *
                                                    100,
                                            )}
                                        />
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {manage && (
                        <PanelBody className="border-t">
                            <Form
                                {...addMilestone.form(project.id)}
                                resetOnSuccess
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-[2fr_1fr_auto] sm:items-end"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="milestone-name"
                                            label="New milestone"
                                            error={errors.name}
                                        >
                                            <Input
                                                id="milestone-name"
                                                name="name"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="milestone-due"
                                            label="Due"
                                            error={errors.due_on}
                                        >
                                            <Input
                                                id="milestone-due"
                                                name="due_on"
                                                type="date"
                                                required
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
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
        </ProjectFrame>
    );
}
