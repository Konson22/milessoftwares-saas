import { Head } from '@inertiajs/react';
import { Select } from '@/components/field';
import { PeriodFilter } from '@/components/period-filter';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { index } from '@/routes/audit';

type Values = Record<string, unknown> | null;

type Props = {
    from: string;
    to: string;
    area: string | null;
    areas: { value: string; label: string }[];
    events: {
        id: string;
        at: string;
        by: string | null;
        action: string;
        subject: string | null;
        before: Values;
        after: Values;
    }[];
};

function describe(values: Values): string {
    if (values === null) {
        return '';
    }

    return Object.entries(values)
        .map(
            ([key, value]) =>
                `${key.replaceAll('_', ' ')}: ${value === null ? '—' : typeof value === 'object' ? JSON.stringify(value) : String(value as string | number | boolean)}`,
        )
        .join(' · ');
}

/**
 * Who approved, paid, reversed, locked or changed what. Read only.
 */
export default function Audit({ from, to, area, areas, events }: Props) {
    return (
        <>
            <Head title="Audit trail" />
            <PageHeader
                title="Audit trail"
                description="Control events in the books, with who did them and what changed. Nobody can change or delete them. Role changes are recorded on the platform."
            />
            <Panel>
                <PanelToolbar>
                    <PeriodFilter form={index.form()} from={from} to={to}>
                        <label className="grid gap-1 text-xs font-medium text-muted-foreground">
                            Area
                            <Select
                                name="area"
                                defaultValue={area ?? ''}
                                className="w-48"
                            >
                                <option value="">Everything</option>
                                {areas.map((option) => (
                                    <option
                                        key={option.value}
                                        value={option.value}
                                    >
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        </label>
                    </PeriodFilter>
                </PanelToolbar>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>When</TableHead>
                            <TableHead>By</TableHead>
                            <TableHead>What</TableHead>
                            <TableHead>Record</TableHead>
                            <TableHead>Before</TableHead>
                            <TableHead>After</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {events.length === 0 && (
                            <TableEmpty colSpan={6}>
                                Nothing recorded in this period.
                            </TableEmpty>
                        )}
                        {events.map((event) => (
                            <TableRow key={event.id}>
                                <TableCell className="whitespace-nowrap tabular-nums">
                                    {new Date(event.at).toLocaleString()}
                                </TableCell>
                                <TableCell>{event.by ?? 'System'}</TableCell>
                                <TableCell className="font-mono text-xs">
                                    {event.action}
                                </TableCell>
                                <TableCell>{event.subject}</TableCell>
                                <TableCell className="max-w-72 text-xs text-muted-foreground">
                                    {describe(event.before)}
                                </TableCell>
                                <TableCell className="max-w-72 text-xs">
                                    {describe(event.after)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </Panel>
        </>
    );
}
