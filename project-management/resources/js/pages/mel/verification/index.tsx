import { Form, Head, Link, router } from '@inertiajs/react';
import { ClipboardCheck } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelToolbar } from '@miles/ui/components/panel';
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
import { SUBMISSION, formatDateTime, formatFigure } from '@/mel/lib/mel';
import type { Measure, Option, SubmissionStatus } from '@/mel/lib/mel';
import { show } from '@/routes/mel/submissions';
import { index, publish } from '@/routes/mel/verification';

type Stage = 'review' | 'publish' | 'rejected';

type Props = {
    stage: Stage;
    programme: string | null;
    programmes: Option[];
    counts: Record<Stage, number>;
    submissions: {
        id: string;
        programme: string;
        indicator: string;
        name: string;
        measure: Measure;
        decimals: number;
        unitLabel: string | null;
        period: string;
        unit: string;
        value: string | null;
        status: SubmissionStatus;
        revision: number;
        submittedBy: string | null;
        submittedAt: string | null;
        reviewer: string | null;
    }[];
    can: { publish: boolean };
};

const STAGES: { key: Stage; label: string }[] = [
    { key: 'review', label: 'To review' },
    { key: 'publish', label: 'To publish' },
    { key: 'rejected', label: 'Sent back' },
];

export default function Verification({
    stage,
    programme,
    programmes,
    counts,
    submissions,
    can,
}: Props) {
    const [ticked, setTicked] = useState<string[]>([]);
    const publishing = stage === 'publish' && can.publish;
    const query = (next: Partial<{ stage: Stage; programme: string }>) => ({
        ...(programme ? { programme } : {}),
        stage,
        ...next,
    });

    return (
        <>
            <Head title="Verification" />
            <PageHeader title="Verification" />

            <Panel>
                <PanelToolbar>
                    <nav className="flex gap-1 text-sm" aria-label="Stage">
                        {STAGES.map((item) => (
                            <Link
                                key={item.key}
                                href={index({
                                    query: query({ stage: item.key }),
                                })}
                                className={`px-2 py-1 ${stage === item.key ? 'bg-muted font-medium' : 'text-muted-foreground hover:text-foreground'}`}
                            >
                                {item.label}{' '}
                                <span className="tabular-nums">
                                    ({counts[item.key]})
                                </span>
                            </Link>
                        ))}
                    </nav>
                    {programmes.length > 1 && (
                        <select
                            aria-label="Programme"
                            value={programme ?? ''}
                            onChange={(event) =>
                                router.get(
                                    index.url({
                                        query: {
                                            stage,
                                            ...(event.target.value
                                                ? {
                                                      programme:
                                                          event.target.value,
                                                  }
                                                : {}),
                                        },
                                    }),
                                )
                            }
                            className={`${selectClass} ml-auto w-auto`}
                        >
                            <option value="">All programmes</option>
                            {programmes.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    )}
                </PanelToolbar>

                {submissions.length === 0 ? (
                    <EmptyState icon={ClipboardCheck} title="Nothing here">
                        {stage === 'review'
                            ? 'No results are waiting for review.'
                            : stage === 'publish'
                              ? 'No verified results are waiting to be published.'
                              : 'No results are waiting for correction.'}
                    </EmptyState>
                ) : (
                    <Form
                        {...publish.form()}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setTicked([])}
                    >
                        {({ processing }) => (
                            <>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {publishing && (
                                                <TableHead className="w-8">
                                                    <input
                                                        type="checkbox"
                                                        aria-label="Tick all"
                                                        checked={
                                                            ticked.length ===
                                                            submissions.length
                                                        }
                                                        onChange={(event) =>
                                                            setTicked(
                                                                event.target
                                                                    .checked
                                                                    ? submissions.map(
                                                                          (
                                                                              item,
                                                                          ) =>
                                                                              item.id,
                                                                      )
                                                                    : [],
                                                            )
                                                        }
                                                    />
                                                </TableHead>
                                            )}
                                            <TableHead>Indicator</TableHead>
                                            <TableHead>Unit</TableHead>
                                            <TableHead>Period</TableHead>
                                            <TableHead className="text-right">
                                                Value
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Submitted</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {submissions.map((item) => (
                                            <TableRow key={item.id}>
                                                {publishing && (
                                                    <TableCell>
                                                        <input
                                                            type="checkbox"
                                                            name="ids[]"
                                                            value={item.id}
                                                            aria-label={`Publish ${item.indicator} ${item.unit} ${item.period}`}
                                                            checked={ticked.includes(
                                                                item.id,
                                                            )}
                                                            onChange={(event) =>
                                                                setTicked(
                                                                    event.target
                                                                        .checked
                                                                        ? [
                                                                              ...ticked,
                                                                              item.id,
                                                                          ]
                                                                        : ticked.filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  item.id,
                                                                          ),
                                                                )
                                                            }
                                                        />
                                                    </TableCell>
                                                )}
                                                <TableCell>
                                                    <Link
                                                        href={show(item.id)}
                                                        className="hover:underline"
                                                    >
                                                        <span className="text-muted-foreground">
                                                            {item.programme}
                                                        </span>{' '}
                                                        <span className="font-medium">
                                                            {item.indicator}
                                                        </span>{' '}
                                                        {item.name}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {item.unit}
                                                </TableCell>
                                                <TableCell>
                                                    {item.period}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatFigure(
                                                        item.value,
                                                        item,
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <StatusBadge
                                                        tone={
                                                            SUBMISSION[
                                                                item.status
                                                            ].tone
                                                        }
                                                    >
                                                        {
                                                            SUBMISSION[
                                                                item.status
                                                            ].label
                                                        }
                                                    </StatusBadge>
                                                    {item.revision > 1 && (
                                                        <StatusBadge className="ml-1">
                                                            Correction
                                                        </StatusBadge>
                                                    )}
                                                    {item.reviewer && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {item.reviewer}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-muted-foreground">
                                                    {item.submittedBy ?? '—'}
                                                    <div className="text-xs">
                                                        {formatDateTime(
                                                            item.submittedAt,
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                {publishing && (
                                    <div className="flex items-center gap-3 border-t p-3">
                                        <Button
                                            type="submit"
                                            disabled={
                                                processing ||
                                                ticked.length === 0
                                            }
                                        >
                                            Publish {ticked.length || ''} ticked
                                        </Button>
                                        <span className="text-xs text-muted-foreground">
                                            Published results are shown to
                                            donors and in their reports.
                                        </span>
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </Panel>
        </>
    );
}
