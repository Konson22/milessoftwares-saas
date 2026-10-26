import { Head, Link } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import { Status } from '@/components/status';
import { useSchool } from '@/lib/school';
import { formatDate } from '@/lib/status';
import { useMoney } from '@/lib/use-money';
import { show as showClass } from '@/routes/classes';
import { show as showInvoice } from '@/routes/invoices';
import { show as report } from '@/routes/results';
import { edit, index } from '@/routes/students';

type Props = {
    student: {
        id: string;
        number: string;
        name: string;
        gender: string;
        dateOfBirth: string | null;
        class: { id: string; name: string } | null;
        status: string;
        admittedOn: string;
        phone: string | null;
        email: string | null;
        address: string | null;
        guardianName: string | null;
        guardianPhone: string | null;
        guardianRelationship: string | null;
    };
    attendance: {
        term: string | null;
        present: number;
        late: number;
        absent: number;
        excused: number;
    };
    invoices:
        | {
              id: string;
              number: string;
              term: string | null;
              status: string;
              totalMinor: number;
              balanceMinor: number;
          }[]
        | null;
    can: { manage: boolean; results: boolean };
};

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="text-sm">{children || '—'}</dd>
        </div>
    );
}

/**
 * A student: details, class, this term's attendance and fees.
 */
export default function StudentShow({
    student,
    attendance,
    invoices,
    can,
}: Props) {
    const school = useSchool();
    const money = useMoney();
    const owed = (invoices ?? []).reduce(
        (sum, invoice) => sum + invoice.balanceMinor,
        0,
    );

    return (
        <>
            <Head title={student.name} />
            <PageHeader
                title={student.name}
                description={`${school.studentNumber} ${student.number} · admitted ${formatDate(student.admittedOn)}`}
                badges={<Status status={student.status} />}
                actions={
                    <>
                        {can.results && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={report(student.id)}>
                                    <FileSpreadsheet
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Report card
                                </Link>
                            </Button>
                        )}
                        {can.manage && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(student.id)}>Edit</Link>
                            </Button>
                        )}
                        <Button variant="outline" size="sm" asChild>
                            <Link href={index()}>
                                ←{' '}
                                {school.value === 'primary'
                                    ? 'Pupils'
                                    : 'Students'}
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label={`Present${attendance.term ? `, ${attendance.term}` : ''}`}
                    value={attendance.present + attendance.late}
                    hint={
                        attendance.late > 0
                            ? `${attendance.late} late`
                            : undefined
                    }
                    accent="emerald"
                />
                <StatCard
                    label="Absent"
                    value={attendance.absent}
                    hint={
                        attendance.excused > 0
                            ? `${attendance.excused} excused`
                            : undefined
                    }
                    accent={attendance.absent > 0 ? 'red' : 'slate'}
                />
                {invoices && (
                    <StatCard
                        label="Fees owed"
                        value={money(owed)}
                        accent={owed > 0 ? 'amber' : 'slate'}
                    />
                )}
            </div>

            <div className="mt-4 grid gap-4 lg:grid-cols-2">
                <Panel>
                    <PanelHeader title="Details" />
                    <PanelBody>
                        <dl className="grid gap-3 sm:grid-cols-2">
                            <Detail label="Class">
                                {student.class && (
                                    <Link
                                        href={showClass(student.class.id)}
                                        className="hover:underline"
                                    >
                                        {student.class.name}
                                    </Link>
                                )}
                            </Detail>
                            <Detail label="Gender">
                                <span className="capitalize">
                                    {student.gender}
                                </span>
                            </Detail>
                            <Detail label="Date of birth">
                                {student.dateOfBirth &&
                                    formatDate(student.dateOfBirth)}
                            </Detail>
                            <Detail label="Phone">{student.phone}</Detail>
                            <Detail label="Email">{student.email}</Detail>
                            <Detail label="Address">{student.address}</Detail>
                            <Detail
                                label={
                                    school.value === 'primary'
                                        ? 'Parent or guardian'
                                        : 'Next of kin'
                                }
                            >
                                {[
                                    student.guardianName,
                                    student.guardianRelationship &&
                                        `(${student.guardianRelationship})`,
                                ]
                                    .filter(Boolean)
                                    .join(' ')}
                            </Detail>
                            <Detail label="Their phone">
                                {student.guardianPhone}
                            </Detail>
                        </dl>
                    </PanelBody>
                </Panel>

                {invoices && (
                    <Panel>
                        <PanelHeader title="Fees" />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Invoice</TableHead>
                                    <TableHead>{school.period}</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">
                                        Balance
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {invoices.length === 0 && (
                                    <TableEmpty colSpan={4}>
                                        Not billed yet.
                                    </TableEmpty>
                                )}
                                {invoices.map((invoice) => (
                                    <TableRow key={invoice.id}>
                                        <TableCell>
                                            <Link
                                                href={showInvoice(invoice.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {invoice.number}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{invoice.term}</TableCell>
                                        <TableCell>
                                            <Status status={invoice.status} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(invoice.balanceMinor)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>
                )}
            </div>
        </>
    );
}
