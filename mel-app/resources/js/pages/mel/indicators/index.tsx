import { Form, Head, Link } from '@inertiajs/react';
import { Gauge } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
import { SearchField } from '@miles/ui/components/search-field';
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
import { selectClass } from '@/mel/components/fields';
import { FREQUENCIES, INDICATOR_STATUS, MEASURES, TYPES } from '@/mel/lib/mel';
import type { Measure, Option } from '@/mel/lib/mel';
import { create, index, show } from '@/routes/mel/indicators';

type Props = {
    filters: {
        programme: string | null;
        status: string | null;
        search: string;
    };
    programmes: Option[];
    indicators: {
        id: string;
        programmeId: string;
        programme: string;
        code: string;
        name: string;
        result: string;
        type: string | null;
        measure: Measure | null;
        frequency: string;
        status: 'draft' | 'active' | 'retired';
        responsible: string | null;
    }[];
    canCreate: boolean;
};

export default function Indicators({
    filters,
    programmes,
    indicators,
    canCreate,
}: Props) {
    const add =
        canCreate && filters.programme ? (
            <Button asChild>
                <Link href={create(filters.programme)}>New indicator</Link>
            </Button>
        ) : undefined;

    return (
        <>
            <Head title="Indicators" />
            <PageHeader title="Indicators" actions={add} />

            <Panel>
                <PanelToolbar>
                    <Form
                        {...index.form()}
                        options={{ preserveState: true }}
                        className="flex flex-wrap items-center gap-2"
                    >
                        <SearchField
                            name="search"
                            defaultValue={filters.search}
                            placeholder="Code or name"
                            aria-label="Search indicators"
                            className="max-w-xs"
                        />
                        <select
                            name="programme"
                            defaultValue={filters.programme ?? ''}
                            aria-label="Programme"
                            className={`${selectClass} w-auto`}
                        >
                            <option value="">All programmes</option>
                            {programmes.map((programme) => (
                                <option
                                    key={programme.value}
                                    value={programme.value}
                                >
                                    {programme.label}
                                </option>
                            ))}
                        </select>
                        <select
                            name="status"
                            defaultValue={filters.status ?? ''}
                            aria-label="Status"
                            className={`${selectClass} w-auto`}
                        >
                            <option value="">Any status</option>
                            <option value="active">Active</option>
                            <option value="draft">Draft</option>
                            <option value="retired">Retired</option>
                        </select>
                        <Button type="submit" variant="outline">
                            Filter
                        </Button>
                    </Form>
                    {canCreate && !filters.programme && (
                        <p className="text-xs text-muted-foreground">
                            Choose a programme to add an indicator to it.
                        </p>
                    )}
                </PanelToolbar>

                {indicators.length === 0 ? (
                    <EmptyState icon={Gauge} title="No indicators" action={add}>
                        Indicators measure the results in a programme&apos;s
                        framework. Add them from the framework or here.
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Indicator</TableHead>
                                <TableHead>Programme</TableHead>
                                <TableHead>Result</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Measure</TableHead>
                                <TableHead>Reported</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {indicators.map((indicator) => (
                                <TableRow key={indicator.id}>
                                    <TableCell>
                                        <Link
                                            href={show({
                                                programme:
                                                    indicator.programmeId,
                                                indicator: indicator.id,
                                            })}
                                            className="hover:underline"
                                        >
                                            <span className="font-medium">
                                                {indicator.code}
                                            </span>{' '}
                                            {indicator.name}
                                        </Link>
                                        {indicator.responsible && (
                                            <div className="text-xs text-muted-foreground">
                                                {indicator.responsible}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>{indicator.programme}</TableCell>
                                    <TableCell>{indicator.result}</TableCell>
                                    <TableCell>
                                        {indicator.type
                                            ? TYPES[indicator.type]
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {indicator.measure
                                            ? MEASURES[indicator.measure]
                                            : '—'}
                                    </TableCell>
                                    <TableCell>
                                        {FREQUENCIES[indicator.frequency]}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            tone={
                                                INDICATOR_STATUS[
                                                    indicator.status
                                                ].tone
                                            }
                                        >
                                            {
                                                INDICATOR_STATUS[
                                                    indicator.status
                                                ].label
                                            }
                                        </StatusBadge>
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
