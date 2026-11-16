import { Head, Link } from '@inertiajs/react';
import { ListTree } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { show } from '@/routes/mel/frameworks';

type Props = {
    programmes: {
        id: string;
        code: string;
        name: string;
        levels: string[];
        results: number;
        indicators: number;
    }[];
};

export default function Frameworks({ programmes }: Props) {
    return (
        <>
            <Head title="Results frameworks" />
            <PageHeader title="Results frameworks" />

            <Panel>
                {programmes.length === 0 ? (
                    <EmptyState icon={ListTree} title="No programmes yet">
                        Each programme has its own results framework.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Programme</TableHead>
                                <TableHead>Levels</TableHead>
                                <TableHead className="text-right">
                                    Results
                                </TableHead>
                                <TableHead className="text-right">
                                    Indicators
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {programmes.map((programme) => (
                                <TableRow key={programme.id}>
                                    <TableCell>
                                        <Link
                                            href={show(programme.id)}
                                            className="font-medium hover:underline"
                                        >
                                            <span className="text-muted-foreground">
                                                {programme.code}
                                            </span>{' '}
                                            {programme.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {programme.levels.join(' › ')}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.results}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {programme.indicators}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </Panel>
        </>
    );
}
