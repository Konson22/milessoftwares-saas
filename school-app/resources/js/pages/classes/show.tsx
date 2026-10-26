import { Form, Head, Link } from '@inertiajs/react';
import { CalendarCheck, FileSpreadsheet, Trash2, UserPlus } from 'lucide-react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, Select } from '@/components/field';
import { useCan } from '@/lib/access';
import { plural, useSchool } from '@/lib/school';
import { index as attendance } from '@/routes/attendance';
import { edit, index, promote } from '@/routes/classes';
import {
    destroy as removeSubject,
    store as addSubject,
    update as saveTeacher,
} from '@/routes/classes/subjects';
import { index as results } from '@/routes/results';
import { create as enrol, show as showStudent } from '@/routes/students';
import type { Option } from '@/types';

type Props = {
    schoolClass: {
        id: string;
        name: string;
        level: string;
        programme: string | null;
        classTeacher: string | null;
        capacity: number | null;
        active: boolean;
        finalYear: boolean;
    };
    students: {
        id: string;
        number: string;
        name: string;
        gender: string;
        guardianPhone: string | null;
    }[];
    subjects: {
        id: string;
        subjectId: string;
        code: string;
        name: string;
        credits: number | null;
        teacherId: string | null;
        teacher: string | null;
    }[];
    subjectOptions: Option[];
    staffOptions: Option[];
    promoteTo: (Option & { level: number })[];
    can: { manage: boolean; promote: boolean };
};

/**
 * A class: its students, what it is taught and by whom, and moving its
 * students on at the end of the year.
 */
export default function ClassShow({
    schoolClass,
    students,
    subjects,
    subjectOptions,
    staffOptions,
    promoteTo,
    can,
}: Props) {
    const school = useSchool();
    const permitted = useCan();
    const word = school.subject;
    const words = plural(word).replace(/^./, (c) => c.toUpperCase());

    return (
        <>
            <Head title={schoolClass.name} />
            <PageHeader
                title={schoolClass.name}
                description={[
                    schoolClass.programme,
                    schoolClass.level,
                    schoolClass.classTeacher
                        ? `Class teacher ${schoolClass.classTeacher}`
                        : null,
                    `${students.length}${schoolClass.capacity !== null ? ` of ${schoolClass.capacity}` : ''} students`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
                badges={
                    !schoolClass.active && <StatusBadge>Closed</StatusBadge>
                }
                actions={
                    <>
                        {permitted('school.attendance.record') && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={attendance({
                                        query: { class: schoolClass.id },
                                    })}
                                >
                                    <CalendarCheck
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Register
                                </Link>
                            </Button>
                        )}
                        {permitted('school.results.view') && (
                            <Button variant="outline" size="sm" asChild>
                                <Link
                                    href={results({
                                        query: { class: schoolClass.id },
                                    })}
                                >
                                    <FileSpreadsheet
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Results
                                </Link>
                            </Button>
                        )}
                        {can.manage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(schoolClass.id)}>Edit</Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>← Classes</Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_24rem]">
                <Panel>
                    <PanelHeader
                        title="Students"
                        actions={
                            can.promote &&
                            schoolClass.active && (
                                <Button size="sm" asChild>
                                    <Link
                                        href={enrol({
                                            query: { class: schoolClass.id },
                                        })}
                                    >
                                        <UserPlus
                                            className="size-4"
                                            aria-hidden
                                        />
                                        Enrol
                                    </Link>
                                </Button>
                            )
                        }
                    />
                    <Form
                        {...promote.form(schoolClass.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ errors, processing }) => (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {can.promote && (
                                                <TableHead className="w-8" />
                                            )}
                                            <TableHead>
                                                {school.studentNumber}
                                            </TableHead>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Gender</TableHead>
                                            <TableHead>Contact</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {students.length === 0 && (
                                            <TableEmpty colSpan={5}>
                                                No students in this class yet.
                                            </TableEmpty>
                                        )}
                                        {students.map((student) => (
                                            <TableRow key={student.id}>
                                                {can.promote && (
                                                    <TableCell>
                                                        <input
                                                            type="checkbox"
                                                            name="student_ids[]"
                                                            value={student.id}
                                                            defaultChecked
                                                            aria-label={`Move ${student.name}`}
                                                        />
                                                    </TableCell>
                                                )}
                                                <TableCell className="font-mono text-xs">
                                                    {student.number}
                                                </TableCell>
                                                <TableCell>
                                                    <Link
                                                        href={showStudent(
                                                            student.id,
                                                        )}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {student.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="capitalize">
                                                    {student.gender}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {student.guardianPhone}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                {can.promote && students.length > 0 && (
                                    <div className="flex flex-wrap items-end gap-2 border-t px-3 py-3">
                                        <Field
                                            id="to_class_id"
                                            label="Move the ticked students to"
                                            error={
                                                errors.to_class_id ??
                                                errors.student_ids
                                            }
                                            className="min-w-56 flex-1"
                                        >
                                            <Select
                                                id="to_class_id"
                                                name="to_class_id"
                                                defaultValue={
                                                    promoteTo[0]?.value ?? ''
                                                }
                                                placeholder={
                                                    schoolClass.finalYear
                                                        ? 'Graduate them'
                                                        : 'Graduate them (they leave the school)'
                                                }
                                                options={promoteTo}
                                            />
                                        </Field>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Move students
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                </Panel>

                <Panel>
                    <PanelHeader
                        title={words}
                        description={`Who teaches each ${word.toLowerCase()} to this class.`}
                    />
                    <ul className="divide-y">
                        {subjects.length === 0 && (
                            <li className="px-3 py-6 text-center text-sm text-muted-foreground">
                                No {plural(word)} yet.
                            </li>
                        )}
                        {subjects.map((row) => (
                            <li key={row.id} className="grid gap-2 px-3 py-2">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm">
                                        <span className="font-medium">
                                            {row.name}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            {row.code}
                                            {row.credits !== null &&
                                                ` · ${row.credits} credits`}
                                        </span>
                                    </p>
                                    {can.manage && (
                                        <Form
                                            {...removeSubject.form({
                                                class: schoolClass.id,
                                                classSubject: row.id,
                                            })}
                                            options={{ preserveScroll: true }}
                                        >
                                            <Button
                                                type="submit"
                                                variant="ghost"
                                                size="sm"
                                                aria-label={`Remove ${row.name}`}
                                            >
                                                <Trash2
                                                    className="size-4"
                                                    aria-hidden
                                                />
                                            </Button>
                                        </Form>
                                    )}
                                </div>
                                {can.manage ? (
                                    <Form
                                        {...saveTeacher.form({
                                            class: schoolClass.id,
                                            classSubject: row.id,
                                        })}
                                        className="flex gap-2"
                                        options={{ preserveScroll: true }}
                                    >
                                        <Select
                                            name="teacher_id"
                                            aria-label={`${school.staff} of ${row.name}`}
                                            defaultValue={row.teacherId ?? ''}
                                            placeholder={`No ${school.staff.toLowerCase()} yet`}
                                            options={staffOptions}
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            size="sm"
                                        >
                                            Save
                                        </Button>
                                    </Form>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        {row.teacher ??
                                            `No ${school.staff.toLowerCase()} yet`}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                    {can.manage && subjectOptions.length > 0 && (
                        <PanelBody className="border-t">
                            <Form
                                {...addSubject.form(schoolClass.id)}
                                className="grid gap-2"
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="subject_id"
                                            label={`Add a ${word.toLowerCase()}`}
                                            error={
                                                errors.subject_id ??
                                                errors.teacher_id
                                            }
                                        >
                                            <Select
                                                id="subject_id"
                                                name="subject_id"
                                                required
                                                placeholder={`Choose a ${word.toLowerCase()}`}
                                                options={subjectOptions}
                                            />
                                        </Field>
                                        <Select
                                            name="teacher_id"
                                            aria-label={school.staff}
                                            placeholder={`${school.staff} (optional)`}
                                            options={staffOptions}
                                        />
                                        <div>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                Add
                                            </Button>
                                        </div>
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
