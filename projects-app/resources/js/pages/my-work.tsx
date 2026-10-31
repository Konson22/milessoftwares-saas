import { Form, Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ListChecks, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { formatDay, formatMinutes } from '@/lib/status';
import { myWork } from '@/routes';
import { destroy, store } from '@/routes/projects/time';
import type { Option } from '@/types';
import { MyTaskRow } from '@/components/my-task';
import type { MyTask } from '@/components/my-task';

type Entry = {
    id: string;
    projectId: string;
    project: string;
    task: string | null;
    workedOn: string;
    minutes: number;
    note: string | null;
};

type Props = {
    tasks: MyTask[];
    week: { start: string; previous: string; next: string; days: string[] };
    entries: Entry[];
    projects: Option[];
    meId: string | null;
    canLog: boolean;
    today: string;
};

/**
 * The member's open tasks across projects, and their timesheet for a week.
 */
export default function MyWork({
    tasks,
    week,
    entries,
    projects,
    meId,
    canLog,
    today,
}: Props) {
    const [projectId, setProjectId] = useState(projects[0]?.value ?? '');
    const sheet = [...new Set(entries.map((entry) => entry.project))];
    const total = entries.reduce((sum, entry) => sum + entry.minutes, 0);
    const cell = (project: string, day: string) =>
        entries
            .filter(
                (entry) => entry.project === project && entry.workedOn === day,
            )
            .reduce((sum, entry) => sum + entry.minutes, 0);

    return (
        <>
            <Head title="My work" />
            <PageHeader
                title="My work"
                description="Your open tasks, and the time you've logged."
            />

            <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
                <Panel>
                    <PanelHeader
                        title="Open tasks"
                        description="Soonest due first."
                    />
                    {tasks.length === 0 ? (
                        <EmptyState
                            icon={ListChecks}
                            title="Nothing assigned to you"
                        />
                    ) : (
                        <ul className="divide-y">
                            {tasks.map((item) => (
                                <MyTaskRow key={item.id} item={item} />
                            ))}
                        </ul>
                    )}
                </Panel>

                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader
                            title={`Week of ${formatDay(week.start)}`}
                            description={`${formatMinutes(total)} logged`}
                            actions={
                                <>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={myWork({
                                                query: { week: week.previous },
                                            })}
                                            aria-label="Previous week"
                                        >
                                            <ChevronLeft className="size-4" />
                                        </Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={myWork()}>This week</Link>
                                    </Button>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={myWork({
                                                query: { week: week.next },
                                            })}
                                            aria-label="Next week"
                                        >
                                            <ChevronRight className="size-4" />
                                        </Link>
                                    </Button>
                                </>
                            }
                        />
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b text-xs text-muted-foreground">
                                        <th className="px-3 py-2 text-left font-medium">
                                            Project
                                        </th>
                                        {week.days.map((day) => (
                                            <th
                                                key={day}
                                                className={
                                                    day === today
                                                        ? 'px-2 py-2 text-right font-semibold text-foreground'
                                                        : 'px-2 py-2 text-right font-medium'
                                                }
                                            >
                                                {new Date(
                                                    `${day}T00:00:00`,
                                                ).toLocaleDateString([], {
                                                    weekday: 'short',
                                                })}
                                            </th>
                                        ))}
                                        <th className="px-3 py-2 text-right font-medium">
                                            Total
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {sheet.length === 0 && (
                                        <tr>
                                            <td
                                                colSpan={9}
                                                className="px-3 py-6 text-center text-muted-foreground"
                                            >
                                                No time logged this week.
                                            </td>
                                        </tr>
                                    )}
                                    {sheet.map((project) => (
                                        <tr key={project} className="border-b">
                                            <td className="px-3 py-2 font-medium">
                                                {project}
                                            </td>
                                            {week.days.map((day) => {
                                                const minutes = cell(
                                                    project,
                                                    day,
                                                );

                                                return (
                                                    <td
                                                        key={day}
                                                        className="px-2 py-2 text-right tabular-nums"
                                                    >
                                                        {minutes > 0
                                                            ? formatMinutes(
                                                                  minutes,
                                                              )
                                                            : ''}
                                                    </td>
                                                );
                                            })}
                                            <td className="px-3 py-2 text-right font-medium tabular-nums">
                                                {formatMinutes(
                                                    week.days.reduce(
                                                        (sum, day) =>
                                                            sum +
                                                            cell(project, day),
                                                        0,
                                                    ),
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {entries.length > 0 && (
                            <ul className="divide-y border-t">
                                {entries.map((entry) => (
                                    <li
                                        key={entry.id}
                                        className="flex items-center gap-2 px-3 py-1.5 text-sm"
                                    >
                                        <span className="w-16 text-muted-foreground">
                                            {formatDay(entry.workedOn)}
                                        </span>
                                        <span className="font-medium">
                                            {entry.project}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                            {[entry.task, entry.note]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                        <span className="tabular-nums">
                                            {formatMinutes(entry.minutes)}
                                        </span>
                                        {canLog && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                aria-label="Remove entry"
                                                onClick={() =>
                                                    router.delete(
                                                        destroy.url({
                                                            project:
                                                                entry.projectId,
                                                            timeEntry: entry.id,
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
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    {canLog && meId && projectId !== '' && (
                        <Panel>
                            <PanelHeader title="Log time" />
                            <PanelBody>
                                <Form
                                    {...store.form(projectId)}
                                    resetOnSuccess={['hours', 'note']}
                                    options={{ preserveScroll: true }}
                                    className="grid gap-3 sm:grid-cols-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="person_id"
                                                value={meId}
                                            />
                                            <Field
                                                id="log-project"
                                                label="Project"
                                            >
                                                <Select
                                                    id="log-project"
                                                    value={projectId}
                                                    onChange={(event) =>
                                                        setProjectId(
                                                            event.target.value,
                                                        )
                                                    }
                                                    options={projects}
                                                />
                                            </Field>
                                            <Field
                                                id="log-day"
                                                label="Day"
                                                error={errors.worked_on}
                                            >
                                                <Input
                                                    id="log-day"
                                                    name="worked_on"
                                                    type="date"
                                                    required
                                                    defaultValue={today}
                                                />
                                            </Field>
                                            <Field
                                                id="log-hours"
                                                label="Hours"
                                                error={errors.hours}
                                                hint="1.5 or 1:30"
                                            >
                                                <Input
                                                    id="log-hours"
                                                    name="hours"
                                                    required
                                                    inputMode="decimal"
                                                    {...invalid(
                                                        'log-hours',
                                                        errors.hours,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="log-note"
                                                label="Note"
                                                error={errors.note}
                                            >
                                                <Input
                                                    id="log-note"
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
                </div>
            </div>
        </>
    );
}
