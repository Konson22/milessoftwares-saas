import { Form, Head, Link } from '@inertiajs/react';
import { CalendarCheck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelHeader, PanelToolbar } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { cn } from '@miles/ui/lib/utils';
import { Field, Select } from '@/components/field';
import { UnlinkedNotice } from '@/components/unlinked-notice';
import { useSchool } from '@/lib/school';
import { formatDate } from '@/lib/status';
import { index, store } from '@/routes/attendance';
import type { Option } from '@/types';

type Props = {
    filters: { class: string; subject: string; date: string };
    classes: Option[];
    subjects: Option[];
    register: {
        className: string;
        subjectName: string | null;
        takenBy: string | null;
        students: {
            id: string;
            number: string;
            name: string;
            status: string | null;
        }[];
    } | null;
    recent: {
        id: string;
        date: string;
        classId: string;
        className: string;
        subjectId: string | null;
        subjectName: string | null;
        takenBy: string;
        students: number;
        absent: number;
    }[];
    today: string;
    canRecord: boolean;
    linked: boolean;
};

const MARKS = [
    { value: 'present', label: 'Present', short: 'P' },
    { value: 'late', label: 'Late', short: 'L' },
    { value: 'absent', label: 'Absent', short: 'A' },
    { value: 'excused', label: 'Excused', short: 'E' },
];

/**
 * Registers: choose a class and day (and the subject, outside primary
 * schools), mark each student, and the registers taken lately.
 */
export default function Attendance({
    filters,
    classes,
    subjects,
    register,
    recent,
    today,
    canRecord,
    linked,
}: Props) {
    const school = useSchool();
    const word = school.subject.toLowerCase();

    return (
        <>
            <Head title="Attendance" />
            <PageHeader
                title="Attendance"
                description={
                    school.attendanceBySubject
                        ? `A register for each ${word} taught, per day.`
                        : 'One register a day for each class.'
                }
            />

            {!linked && <UnlinkedNotice what="take registers for" />}

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        className="flex flex-wrap items-end gap-2"
                    >
                        <Field id="class" label="Class">
                            <Select
                                id="class"
                                name="class"
                                defaultValue={filters.class}
                                placeholder="Choose a class"
                                options={classes}
                                className="w-auto"
                            />
                        </Field>
                        {school.attendanceBySubject && subjects.length > 0 && (
                            <Field id="subject" label={school.subject}>
                                <Select
                                    id="subject"
                                    name="subject"
                                    defaultValue={filters.subject}
                                    placeholder={`Choose a ${word}`}
                                    options={subjects}
                                    className="w-auto"
                                />
                            </Field>
                        )}
                        <Field id="date" label="Day">
                            <Input
                                id="date"
                                name="date"
                                type="date"
                                max={today}
                                defaultValue={filters.date}
                                className="w-auto"
                            />
                        </Field>
                        <Button type="submit" variant="outline">
                            Open register
                        </Button>
                    </Form>
                </PanelToolbar>

                {register === null ? (
                    <EmptyState icon={CalendarCheck} title="Choose a register">
                        {filters.class !== '' && school.attendanceBySubject
                            ? `Now choose the ${word}.`
                            : 'Choose a class and a day.'}
                    </EmptyState>
                ) : (
                    <Form {...store.form()} options={{ preserveScroll: true }}>
                        {({ errors, processing }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="school_class_id"
                                    value={filters.class}
                                />
                                {filters.subject !== '' && (
                                    <input
                                        type="hidden"
                                        name="subject_id"
                                        value={filters.subject}
                                    />
                                )}
                                <input
                                    type="hidden"
                                    name="taken_on"
                                    value={filters.date}
                                />
                                <PanelHeader
                                    title={`${register.className}${register.subjectName ? `, ${register.subjectName}` : ''} · ${formatDate(filters.date)}`}
                                    description={
                                        register.takenBy
                                            ? `Taken by ${register.takenBy}: saving again replaces it.`
                                            : 'Not taken yet: everyone starts present.'
                                    }
                                />
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>
                                                {school.studentNumber}
                                            </TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Mark</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {register.students.length === 0 && (
                                            <TableEmpty colSpan={3}>
                                                No students in this class.
                                            </TableEmpty>
                                        )}
                                        {register.students.map((student) => (
                                            <TableRow key={student.id}>
                                                <TableCell className="font-mono text-xs">
                                                    {student.number}
                                                </TableCell>
                                                <TableCell className="font-medium">
                                                    {student.name}
                                                </TableCell>
                                                <TableCell>
                                                    <fieldset className="flex gap-1">
                                                        <legend className="sr-only">
                                                            {student.name}
                                                        </legend>
                                                        {MARKS.map((mark) => (
                                                            <label
                                                                key={mark.value}
                                                                className="cursor-pointer"
                                                            >
                                                                <input
                                                                    type="radio"
                                                                    name={`statuses[${student.id}]`}
                                                                    value={
                                                                        mark.value
                                                                    }
                                                                    defaultChecked={
                                                                        (student.status ??
                                                                            'present') ===
                                                                        mark.value
                                                                    }
                                                                    disabled={
                                                                        !canRecord
                                                                    }
                                                                    className="peer sr-only"
                                                                />
                                                                <span
                                                                    title={
                                                                        mark.label
                                                                    }
                                                                    className={cn(
                                                                        'grid size-8 place-items-center border text-xs font-semibold text-muted-foreground peer-focus-visible:ring-2 peer-focus-visible:ring-ring',
                                                                        'peer-checked:border-transparent',
                                                                        mark.value ===
                                                                            'present' &&
                                                                            'peer-checked:bg-emerald-600 peer-checked:text-white',
                                                                        mark.value ===
                                                                            'late' &&
                                                                            'peer-checked:bg-amber-500 peer-checked:text-white',
                                                                        mark.value ===
                                                                            'absent' &&
                                                                            'peer-checked:bg-red-600 peer-checked:text-white',
                                                                        mark.value ===
                                                                            'excused' &&
                                                                            'peer-checked:bg-sky-600 peer-checked:text-white',
                                                                    )}
                                                                >
                                                                    {mark.short}
                                                                </span>
                                                            </label>
                                                        ))}
                                                    </fieldset>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                {canRecord && register.students.length > 0 && (
                                    <div className="flex flex-wrap items-center gap-3 border-t px-3 py-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Save register
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            P present · L late · A absent · E
                                            excused
                                        </span>
                                        {(errors.statuses ||
                                            errors.subject_id ||
                                            errors.taken_on) && (
                                            <span className="text-sm text-destructive">
                                                {errors.statuses ??
                                                    errors.subject_id ??
                                                    errors.taken_on}
                                            </span>
                                        )}
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </Panel>

            <Panel className="mt-4">
                <PanelHeader title="Taken lately" />
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Day</TableHead>
                            <TableHead>Class</TableHead>
                            {school.attendanceBySubject && (
                                <TableHead>{school.subject}</TableHead>
                            )}
                            <TableHead>Taken by</TableHead>
                            <TableHead className="text-right">Absent</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {recent.length === 0 && (
                            <TableEmpty colSpan={5}>
                                No registers yet.
                            </TableEmpty>
                        )}
                        {recent.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>
                                    <Link
                                        href={index({
                                            query: {
                                                class: row.classId,
                                                subject: row.subjectId ?? '',
                                                date: row.date,
                                            },
                                        })}
                                        className="hover:underline"
                                    >
                                        {formatDate(row.date)}
                                    </Link>
                                </TableCell>
                                <TableCell>{row.className}</TableCell>
                                {school.attendanceBySubject && (
                                    <TableCell>{row.subjectName}</TableCell>
                                )}
                                <TableCell className="text-muted-foreground">
                                    {row.takenBy}
                                </TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {row.absent} of {row.students}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
