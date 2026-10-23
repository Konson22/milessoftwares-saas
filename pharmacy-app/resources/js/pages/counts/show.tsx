import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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
import { cancel, complete, index, update } from '@/routes/counts';

type Line = {
    id: string;
    itemId: string;
    item: string;
    unit: string;
    batchNumber: string;
    expiresOn: string | null;
    counted: number | null;
    countedBy: string | null;
    expected: number | null;
    difference: number | null;
};

type Props = {
    count: {
        id: string;
        number: string;
        category: string | null;
        note: string | null;
        status: 'open' | 'completed' | 'cancelled';
        startedAt: string;
        startedBy: string;
        closedAt: string | null;
        closedBy: string | null;
    };
    lines: Line[];
    canManage: boolean;
};

const tones = {
    open: 'info',
    completed: 'success',
    cancelled: 'neutral',
} as const;

/**
 * One stock-take. While open it is counted blind, line by line; only the
 * lines changed here are saved, so several people can count at once.
 * Completed, it shows what was expected and the corrections posted.
 */
export default function Count({ count, lines, canManage }: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [search, setSearch] = useState('');
    const [values, setValues] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const open = count.status === 'open';

    const shown = useMemo(() => {
        const term = search.trim().toLowerCase();

        return term === ''
            ? lines
            : lines.filter(
                  (line) =>
                      line.item.toLowerCase().includes(term) ||
                      line.batchNumber.toLowerCase().includes(term),
              );
    }, [lines, search]);

    const changed = Object.entries(values).filter(([id, value]) => {
        const line = lines.find((candidate) => candidate.id === id);

        return line !== undefined && value !== (line.counted?.toString() ?? '');
    });
    const countedLines = lines.filter((line) => line.counted !== null).length;

    function save() {
        setSaving(true);
        router.put(
            update.url(count.id),
            {
                counts: Object.fromEntries(
                    changed.map(([id, value]) => [
                        id,
                        value === '' ? null : Number(value),
                    ]),
                ),
            },
            {
                preserveScroll: true,
                onSuccess: () => setValues({}),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <>
            <Head title={`Count ${count.number}`} />
            <PageHeader
                title={`Count ${count.number}`}
                description={`${count.category ?? 'Everything in stock'}, started ${new Date(count.startedAt).toLocaleString()} by ${count.startedBy}.`}
                actions={
                    <Button variant="outline" asChild>
                        <Link href={index()}>All counts</Link>
                    </Button>
                }
            />

            <div className="grid gap-3 lg:grid-cols-[1fr_20rem]">
                <Panel>
                    <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3">
                        <Input
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Find a medicine or batch"
                            aria-label="Find a line"
                            className="h-8 max-w-xs"
                        />
                        <span className="text-sm text-muted-foreground">
                            {countedLines} of {lines.length} lines counted
                        </span>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Medicine</TableHead>
                                <TableHead>Batch</TableHead>
                                {!open && (
                                    <TableHead className="text-right">
                                        Expected
                                    </TableHead>
                                )}
                                <TableHead className="text-right">
                                    Counted
                                </TableHead>
                                {!open && (
                                    <TableHead className="text-right">
                                        Difference
                                    </TableHead>
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {shown.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell className="font-medium">
                                        {line.item}
                                        <span className="block text-xs font-normal text-muted-foreground">
                                            {line.unit}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {line.batchNumber}
                                        {line.expiresOn && (
                                            <span className="block text-xs text-muted-foreground">
                                                Expires {line.expiresOn}
                                            </span>
                                        )}
                                    </TableCell>
                                    {!open && (
                                        <TableCell className="text-right tabular-nums">
                                            {line.expected}
                                        </TableCell>
                                    )}
                                    <TableCell className="text-right tabular-nums">
                                        {canManage ? (
                                            <Input
                                                type="number"
                                                min={0}
                                                inputMode="numeric"
                                                value={
                                                    values[line.id] ??
                                                    line.counted?.toString() ??
                                                    ''
                                                }
                                                onChange={(event) =>
                                                    setValues((current) => ({
                                                        ...current,
                                                        [line.id]:
                                                            event.target.value,
                                                    }))
                                                }
                                                aria-label={`Counted of ${line.item}, batch ${line.batchNumber}`}
                                                className="ml-auto h-8 w-24 text-right"
                                            />
                                        ) : (
                                            (line.counted ?? '—')
                                        )}
                                        {line.countedBy && (
                                            <span className="block text-xs text-muted-foreground">
                                                {line.countedBy}
                                            </span>
                                        )}
                                        {errors[`counts.${line.id}`] && (
                                            <span className="block text-xs text-destructive">
                                                {errors[`counts.${line.id}`]}
                                            </span>
                                        )}
                                    </TableCell>
                                    {!open && (
                                        <TableCell className="text-right tabular-nums">
                                            {line.difference === null
                                                ? '—'
                                                : line.difference > 0
                                                  ? `+${line.difference}`
                                                  : line.difference}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <div className="grid content-start gap-3">
                    <Panel>
                        <PanelHeader title="Status" />
                        <PanelBody className="grid gap-2 text-sm">
                            <div>
                                <StatusBadge tone={tones[count.status]}>
                                    {count.status}
                                </StatusBadge>
                            </div>
                            {count.note && <p>{count.note}</p>}
                            {count.closedAt && (
                                <p className="text-muted-foreground">
                                    {count.status === 'completed'
                                        ? 'Completed'
                                        : 'Cancelled'}{' '}
                                    {new Date(count.closedAt).toLocaleString()}{' '}
                                    by {count.closedBy}.
                                </p>
                            )}
                            {open && (
                                <p className="text-muted-foreground">
                                    Counting is blind: what the system expects
                                    shows once the count is completed. Sales
                                    carry on meanwhile.
                                </p>
                            )}
                            {errors.count && (
                                <p className="text-destructive">
                                    {errors.count}
                                </p>
                            )}
                        </PanelBody>
                    </Panel>

                    {canManage && (
                        <Panel>
                            <PanelHeader title="Finish" />
                            <PanelBody className="grid gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={saving || changed.length === 0}
                                    onClick={save}
                                >
                                    Save {changed.length || ''} counts
                                </Button>
                                <Form
                                    {...complete.form(count.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            className="w-full"
                                            disabled={
                                                processing ||
                                                changed.length > 0 ||
                                                countedLines === 0
                                            }
                                        >
                                            Complete and correct stock
                                        </Button>
                                    )}
                                </Form>
                                {changed.length > 0 && (
                                    <p className="text-xs text-muted-foreground">
                                        Save the counts before completing.
                                    </p>
                                )}
                                <Form
                                    {...cancel.form(count.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            className="w-full text-destructive"
                                            disabled={processing}
                                        >
                                            Cancel the count
                                        </Button>
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
