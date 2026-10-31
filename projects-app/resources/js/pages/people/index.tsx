import { Head, Link } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
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
import { useMoney } from '@/lib/use-money';
import { create, edit } from '@/routes/people';

type Person = {
    id: string;
    name: string;
    email: string | null;
    title: string | null;
    member: boolean;
    active: boolean;
    projects: number;
    allocation: number;
    openTasks: number;
    hourlyCostMinor: number | null;
};

type Props = { people: Person[]; canManage: boolean };

/**
 * Everyone who works on projects: how loaded they are, and their cost.
 */
export default function People({ people, canManage }: Props) {
    const money = useMoney();
    const finance = people.some((person) => person.hourlyCostMinor !== null);
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a contractor
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title="People" />
            <PageHeader
                title="People"
                description="Workspace members join the list when they first open Projects; add contractors yourself."
                actions={add}
            />

            <Panel>
                {people.length === 0 ? (
                    <EmptyState icon={Users} title="Nobody yet" action={add} />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Role</TableHead>
                                <TableHead className="text-right">
                                    Projects
                                </TableHead>
                                <TableHead className="text-right">
                                    Allocated
                                </TableHead>
                                <TableHead className="text-right">
                                    Open tasks
                                </TableHead>
                                {finance && (
                                    <TableHead className="text-right">
                                        Cost per hour
                                    </TableHead>
                                )}
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {people.map((person) => (
                                <TableRow key={person.id}>
                                    <TableCell>
                                        <span className="font-medium">
                                            {person.name}
                                        </span>
                                        {!person.member && (
                                            <StatusBadge className="ml-2">
                                                Contractor
                                            </StatusBadge>
                                        )}
                                        {!person.active && (
                                            <StatusBadge className="ml-2">
                                                Inactive
                                            </StatusBadge>
                                        )}
                                        {person.email && (
                                            <p className="text-xs text-muted-foreground">
                                                {person.email}
                                            </p>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {person.title ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {person.projects}
                                    </TableCell>
                                    <TableCell
                                        className={
                                            person.allocation > 100
                                                ? 'text-right font-medium text-destructive tabular-nums'
                                                : 'text-right tabular-nums'
                                        }
                                    >
                                        {person.allocation}%
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {person.openTasks}
                                    </TableCell>
                                    {finance && (
                                        <TableCell className="text-right tabular-nums">
                                            {person.hourlyCostMinor
                                                ? money(person.hourlyCostMinor)
                                                : '—'}
                                        </TableCell>
                                    )}
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(person.id)}>
                                                    Edit
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
