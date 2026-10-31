import { Form, router } from '@inertiajs/react';
import { Clock, Trash2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
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
import { Field, invalid, Select } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { formatMoney } from '@/lib/money';
import { formatDate, formatMinutes } from '@/lib/status';
import { destroy, store } from '@/routes/projects/time';
import type { Option, ProjectHeader } from '@/types';

type Props = {
    project: ProjectHeader;
    entries: {
        id: string;
        person: string;
        task: { id: string; number: string; title: string } | null;
        workedOn: string;
        minutes: number;
        note: string | null;
        costMinor: number | null;
        canRemove: boolean;
    }[];
    byPerson: { name: string; minutes: number; costMinor: number | null }[];
    tasks: Option[];
    people: Option[];
    meId: string | null;
    today: string;
};

/**
 * Time on the project: who put in how much, each entry, and logging more.
 */
export default function Time({
    project,
    entries,
    byPerson,
    tasks,
    people,
    meId,
    today,
}: Props) {
    const money = (minor: number) => formatMoney(minor, project.currency);
    const finance = byPerson.some((row) => row.costMinor !== null);
    const canLog = project.can.logTime && project.open && people.length > 0;

    return (
        <ProjectFrame project={project} title="Time">
            <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
                <div className="grid content-start gap-4">
                    {canLog && (
                        <Panel>
                            <PanelHeader title="Log time" />
                            <PanelBody>
                                <Form
                                    {...store.form(project.id)}
                                    resetOnSuccess={['hours', 'note']}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3 sm:grid-cols-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="time-person"
                                                label="Who"
                                                error={errors.person_id}
                                            >
                                                <Select
                                                    id="time-person"
                                                    name="person_id"
                                                    required
                                                    defaultValue={meId ?? ''}
                                                    options={people}
                                                />
                                            </Field>
                                            <Field
                                                id="time-day"
                                                label="Day"
                                                error={errors.worked_on}
                                            >
                                                <Input
                                                    id="time-day"
                                                    name="worked_on"
                                                    type="date"
                                                    required
                                                    max={today}
                                                    defaultValue={today}
                                                />
                                            </Field>
                                            <Field
                                                id="time-task"
                                                label="Task"
                                                error={errors.task_id}
                                                className="sm:col-span-2"
                                            >
                                                <Select
                                                    id="time-task"
                                                    name="task_id"
                                                    defaultValue=""
                                                    placeholder="The project in general"
                                                    options={tasks}
                                                />
                                            </Field>
                                            <Field
                                                id="time-hours"
                                                label="Hours"
                                                error={errors.hours}
                                                hint="1.5 or 1:30"
                                            >
                                                <Input
                                                    id="time-hours"
                                                    name="hours"
                                                    required
                                                    inputMode="decimal"
                                                    {...invalid(
                                                        'time-hours',
                                                        errors.hours,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="time-note"
                                                label="Note"
                                                error={errors.note}
                                            >
                                                <Input
                                                    id="time-note"
                                                    name="note"
                                                />
                                            </Field>
                                            <div className="sm:col-span-2">
                                                <Button
                                                    type="submit"
                                                    disabled={processing}
                                                >
                                                    Log time
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    )}

                    <Panel>
                        <PanelHeader title="By person" />
                        {byPerson.length === 0 ? (
                            <p className="px-3 py-3 text-sm text-muted-foreground">
                                No time logged yet.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {byPerson.map((row) => (
                                    <li
                                        key={row.name}
                                        className="flex items-center gap-2 px-3 py-2 text-sm"
                                    >
                                        <span className="flex-1">
                                            {row.name}
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMinutes(row.minutes)}
                                        </span>
                                        {finance && (
                                            <span className="w-28 text-right text-muted-foreground tabular-nums">
                                                {money(row.costMinor ?? 0)}
                                            </span>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>
                </div>

                <Panel>
                    <PanelHeader title="Entries" description="Latest first." />
                    {entries.length === 0 ? (
                        <EmptyState icon={Clock} title="No time logged yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Day</TableHead>
                                    <TableHead>Who</TableHead>
                                    <TableHead>On</TableHead>
                                    <TableHead className="text-right">
                                        Hours
                                    </TableHead>
                                    {finance && (
                                        <TableHead className="text-right">
                                            Cost
                                        </TableHead>
                                    )}
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.map((entry) => (
                                    <TableRow key={entry.id}>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {formatDate(entry.workedOn)}
                                        </TableCell>
                                        <TableCell>{entry.person}</TableCell>
                                        <TableCell className="max-w-64">
                                            <span className="block truncate">
                                                {entry.task
                                                    ? `${entry.task.number} · ${entry.task.title}`
                                                    : 'General'}
                                            </span>
                                            {entry.note && (
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {entry.note}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatMinutes(entry.minutes)}
                                        </TableCell>
                                        {finance && (
                                            <TableCell className="text-right tabular-nums">
                                                {money(entry.costMinor ?? 0)}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-right">
                                            {entry.canRemove && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label="Remove entry"
                                                    onClick={() =>
                                                        router.delete(
                                                            destroy.url({
                                                                project:
                                                                    project.id,
                                                                timeEntry:
                                                                    entry.id,
                                                            }),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>
            </div>
        </ProjectFrame>
    );
}
