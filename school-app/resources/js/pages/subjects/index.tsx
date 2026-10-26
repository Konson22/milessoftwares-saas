import { Head, Link } from '@inertiajs/react';
import { BookOpen, Plus } from 'lucide-react';
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
import { plural, useSchool } from '@/lib/school';
import { create, edit } from '@/routes/subjects';

type Subject = {
    id: string;
    code: string;
    name: string;
    credits: number | null;
    classes: number;
    active: boolean;
};

/**
 * The subjects, modules or courses the school teaches.
 */
export default function Subjects({
    subjects,
    canManage,
}: {
    subjects: Subject[];
    canManage: boolean;
}) {
    const school = useSchool();
    const word = school.subject;
    const title = word === 'Course' ? 'Courses' : `${word}s`;
    const add = canManage ? (
        <Button asChild>
            <Link href={create()}>
                <Plus className="size-4" aria-hidden />
                Add a {word.toLowerCase()}
            </Link>
        </Button>
    ) : undefined;

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={`What the school teaches. Each class is given its ${plural(word)} on its own page.`}
                actions={add}
            />

            <Panel>
                {subjects.length === 0 ? (
                    <EmptyState
                        icon={BookOpen}
                        title={`No ${plural(word)} yet`}
                        action={add}
                    />
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Code</TableHead>
                                <TableHead>{word}</TableHead>
                                {school.usesCredits && (
                                    <TableHead className="text-right">
                                        Credit hours
                                    </TableHead>
                                )}
                                <TableHead className="text-right">
                                    Classes
                                </TableHead>
                                {canManage && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {subjects.map((subject) => (
                                <TableRow key={subject.id}>
                                    <TableCell className="font-mono text-xs">
                                        {subject.code}
                                    </TableCell>
                                    <TableCell className="font-medium">
                                        {subject.name}
                                        {!subject.active && (
                                            <StatusBadge className="ml-2">
                                                Not taught
                                            </StatusBadge>
                                        )}
                                    </TableCell>
                                    {school.usesCredits && (
                                        <TableCell className="text-right tabular-nums">
                                            {subject.credits}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-right tabular-nums">
                                        {subject.classes}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                asChild
                                            >
                                                <Link href={edit(subject.id)}>
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
