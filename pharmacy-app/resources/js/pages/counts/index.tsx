import { Form, Head, Link, usePage } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
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
import { show, store } from '@/routes/counts';

type Count = {
    id: string;
    number: string;
    category: string | null;
    status: 'open' | 'completed' | 'cancelled';
    startedAt: string;
    startedBy: string;
    closedAt: string | null;
    closedBy: string | null;
    lines: number;
    counted: number;
};

type Props = {
    counts: Count[];
    categories: string[];
    canManage: boolean;
};

const tones = {
    open: 'info',
    completed: 'success',
    cancelled: 'neutral',
} as const;

/**
 * Stock-takes of this branch: the open one being counted, and past ones.
 */
export default function Counts({ counts, categories, canManage }: Props) {
    const { branch } = usePage().props;
    const open = counts.some((count) => count.status === 'open');

    return (
        <>
            <Head title="Stock counts" />
            <PageHeader
                title="Stock counts"
                description={`Count what is on the shelves in ${branch?.name ?? 'this branch'}; the differences are corrected when the count is completed.`}
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_20rem]">
                <Panel>
                    {counts.length === 0 ? (
                        <EmptyState icon={ClipboardCheck} title="No counts yet">
                            Start a count to check every batch, or one category
                            at a time.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Count</TableHead>
                                    <TableHead>Started</TableHead>
                                    <TableHead className="text-right">
                                        Counted
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {counts.map((count) => (
                                    <TableRow key={count.id}>
                                        <TableCell>
                                            <Link
                                                href={show(count.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {count.number}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {count.category ??
                                                    'Everything in stock'}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {new Date(
                                                count.startedAt,
                                            ).toLocaleString()}
                                            <span className="block text-xs text-muted-foreground">
                                                {count.startedBy}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {count.counted} / {count.lines}
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                tone={tones[count.status]}
                                            >
                                                {count.status}
                                            </StatusBadge>
                                            {count.closedBy && (
                                                <span className="block text-xs text-muted-foreground">
                                                    {count.closedBy}
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                {canManage && !open && (
                    <Panel>
                        <PanelHeader title="Start a count" />
                        <PanelBody>
                            <Form {...store.form()} className="grid gap-2">
                                {({ errors, processing }) => (
                                    <>
                                        <select
                                            name="category"
                                            defaultValue=""
                                            aria-label="What to count"
                                            className="h-9 rounded-md border bg-transparent px-2 text-sm"
                                        >
                                            <option value="">
                                                Everything in stock
                                            </option>
                                            {categories.map((category) => (
                                                <option
                                                    key={category}
                                                    value={category}
                                                >
                                                    {category}
                                                </option>
                                            ))}
                                        </select>
                                        <Input
                                            name="note"
                                            placeholder="Note (optional)"
                                            aria-label="Note"
                                        />
                                        {(errors.category || errors.count) && (
                                            <p className="text-sm text-destructive">
                                                {errors.category ??
                                                    errors.count}
                                            </p>
                                        )}
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Start counting
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
