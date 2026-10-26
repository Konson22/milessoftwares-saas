import { Head, Link, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarCheck,
    ClipboardList,
    Inbox,
    ReceiptText,
    School,
    UserMinus,
    UserPlus,
    UserRound,
    Users,
    Wallet,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { useCan } from '@/lib/access';
import { useMoney } from '@/lib/use-money';
import { index as applications } from '@/routes/applications';
import { index as attendance } from '@/routes/attendance';
import { index as classes } from '@/routes/classes';
import { index as fees } from '@/routes/fees';
import { index as invoices } from '@/routes/invoices';
import { create as setUp } from '@/routes/setup';
import { index as staff } from '@/routes/staff';
import { create as enrol, index as students } from '@/routes/students';

type Props = {
    setUp: boolean;
    canSetUp: boolean;
    students?: {
        enrolled: number;
        female: number;
        classes: number;
        staff: number;
        applications: number;
    } | null;
    attendance?: { registersToday: number; absentToday: number } | null;
    fees?: {
        outstandingMinor: number;
        collectedMinor: number;
        notBilled: number;
        overdue: number;
    } | null;
};

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="mt-6">
            <h2 className="mb-2 text-sm font-semibold text-muted-foreground">
                {title}
            </h2>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {children}
            </div>
        </section>
    );
}

/**
 * The school at a glance, in the sections the member may see; before
 * setup, the way to set it up.
 */
export default function Home({
    setUp: ready,
    canSetUp,
    students: roll,
    attendance: today,
    fees: money,
}: Props) {
    const { product, workspace, school, user } = usePage().props;
    const can = useCan();
    const format = useMoney();

    if (!ready || school === null) {
        return (
            <>
                <Head title="Home" />
                <PageHeader
                    title={`${product.name} for ${workspace?.name ?? ''}`}
                    description={`Welcome, ${user?.name ?? ''}.`}
                />
                <Panel>
                    <EmptyState
                        icon={School}
                        title="The school is not set up yet"
                        action={
                            canSetUp ? (
                                <Button asChild>
                                    <Link href={setUp()}>
                                        Set up the school
                                    </Link>
                                </Button>
                            ) : undefined
                        }
                    >
                        {canSetUp
                            ? 'Choose what kind of school this is (primary school, institute, college or university) and its first academic year.'
                            : 'Ask the head or principal to set the school up.'}
                    </EmptyState>
                </Panel>
            </>
        );
    }

    const pupils = school.value === 'primary' ? 'Pupils' : 'Students';

    return (
        <>
            <Head title="Home" />
            <PageHeader
                title={workspace?.name ?? product.name}
                description={`${school.label} · ${school.term?.title ?? `no current ${school.period.toLowerCase()}`} · Welcome, ${user?.name ?? ''}.`}
                actions={
                    <>
                        {can('school.students.manage') && (
                            <Button asChild>
                                <Link href={enrol()}>
                                    <UserPlus className="size-4" aria-hidden />
                                    Enrol a {pupils.toLowerCase().slice(0, -1)}
                                </Link>
                            </Button>
                        )}
                        {can('school.attendance.record') && (
                            <Button variant="outline" asChild>
                                <Link href={attendance()}>
                                    <CalendarCheck
                                        className="size-4"
                                        aria-hidden
                                    />
                                    Take a register
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            {roll && (
                <Section title={pupils}>
                    <StatCard
                        label={`${pupils} enrolled`}
                        value={roll.enrolled}
                        hint={`${roll.female} female, ${roll.enrolled - roll.female} male`}
                        icon={Users}
                        href={students()}
                    />
                    <StatCard
                        label="Classes"
                        value={roll.classes}
                        icon={ClipboardList}
                        accent="slate"
                        href={classes()}
                    />
                    <StatCard
                        label="Staff"
                        value={roll.staff}
                        icon={UserRound}
                        accent="violet"
                        href={staff()}
                    />
                    <StatCard
                        label="Applications waiting"
                        value={roll.applications}
                        icon={Inbox}
                        accent={roll.applications > 0 ? 'amber' : 'slate'}
                        href={applications()}
                    />
                </Section>
            )}

            {today && (
                <Section title="Today">
                    <StatCard
                        label="Registers taken"
                        value={today.registersToday}
                        icon={CalendarCheck}
                        accent="emerald"
                        href={attendance()}
                    />
                    <StatCard
                        label="Absent today"
                        value={today.absentToday}
                        icon={UserMinus}
                        accent={today.absentToday > 0 ? 'red' : 'slate'}
                        href={attendance()}
                    />
                </Section>
            )}

            {money && (
                <Section title={`Fees · ${school.term?.title ?? ''}`}>
                    <StatCard
                        label={`Collected this ${school.period.toLowerCase()}`}
                        value={format(money.collectedMinor)}
                        icon={Wallet}
                        accent="emerald"
                        href={invoices({ query: { status: 'paid' } })}
                    />
                    <StatCard
                        label="Owed in fees"
                        value={format(money.outstandingMinor)}
                        icon={ReceiptText}
                        accent="violet"
                        href={invoices({ query: { status: 'issued' } })}
                    />
                    <StatCard
                        label={`Not billed this ${school.period.toLowerCase()}`}
                        value={money.notBilled}
                        icon={Users}
                        accent={money.notBilled > 0 ? 'amber' : 'slate'}
                        href={fees()}
                    />
                    <StatCard
                        label="Overdue invoices"
                        value={money.overdue}
                        icon={AlertTriangle}
                        accent={money.overdue > 0 ? 'red' : 'slate'}
                        href={invoices({ query: { status: 'issued' } })}
                    />
                </Section>
            )}
        </>
    );
}
