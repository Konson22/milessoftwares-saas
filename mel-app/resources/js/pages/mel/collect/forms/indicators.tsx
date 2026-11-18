import { Head, Link, router, usePage } from '@inertiajs/react';
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
import { useState } from 'react';
import { Field, selectClass, textareaClass } from '@/mel/components/fields';
import {
    CALCULATIONS,
    OPERATORS,
    RUN_STATUS,
    describeMapping,
    figureText,
    isChoice,
    isNumber,
    joinLeaves,
    leavesOf,
    needsValue,
    operatorsFor,
} from '@/mel/lib/indicator-mappings';
import type {
    Calculation,
    Condition,
    Figure,
    Leaf,
    Question,
    RunStatus,
} from '@/mel/lib/indicator-mappings';
import { SUBMISSION, formatDateTime } from '@/mel/lib/mel';
import type { SubmissionStatus } from '@/mel/lib/mel';
import { show as calculationPage } from '@/routes/mel/collect/calculations';
import { indicators as indicatorsPage, show } from '@/routes/mel/collect/forms';
import { retire, send, store, update } from '@/routes/mel/collect/mappings';
import { show as resultPage } from '@/routes/mel/submissions';

type IndicatorOption = {
    id: string;
    label: string;
    measure: string;
    frequency: string;
    status: string;
    calculations: Calculation[];
};

type Run = Figure & {
    id: string;
    period: { id: string; label: string };
    unit: { id: string; label: string };
    used: number;
    status: RunStatus;
    result: {
        id: string;
        status: SubmissionStatus;
        fromThisRun: boolean;
    } | null;
    flag: string | null;
    newer: number;
    createdAt: string;
    author: string | null;
};

type Mapping = {
    id: string;
    status: 'active' | 'retired';
    indicator: { id: string; label: string; measure: string | null };
    calculation: Calculation;
    question: string | null;
    condition: Condition | null;
    numeratorCondition: Condition | null;
    distinctBy: string | null;
    note: string | null;
    author: string;
    createdAt: string;
    runs: Run[];
};

type Preview = Figure & {
    mappingId: string;
    periodId: string;
    unitId: string;
    considered: number;
    accepted: number;
    used: number;
    excluded: { reason: string; label: string; count: number }[];
};

type Props = {
    form: {
        id: string;
        name: string;
        status: string;
        version: number | null;
        programme: { id: string; label: string } | null;
    };
    indicators: IndicatorOption[];
    questions: Question[];
    periods: {
        id: string;
        label: string;
        frequency: string;
        open: boolean;
    }[];
    units: { id: string; label: string }[];
    mappings: Mapping[];
    preview: Preview | null;
    can: { manage: boolean; send: boolean };
};

type Errors = Record<string, string | undefined>;

export default function FormIndicators({
    form,
    indicators,
    questions,
    periods,
    units,
    mappings,
    preview,
    can,
}: Props) {
    const [adding, setAdding] = useState(false);
    const active = mappings.filter((mapping) => mapping.status === 'active');
    const retired = mappings.filter((mapping) => mapping.status === 'retired');
    const mapped = new Set(active.map((mapping) => mapping.indicator.id));

    return (
        <>
            <Head title={`${form.name}: indicators`} />
            <PageHeader
                title={form.name}
                description="Which programme indicators this form's accepted submissions feed, and how. A calculation is sent to M&E as a draft result; it is verified there like any other result before it counts."
                actions={
                    <Button variant="outline" asChild>
                        <Link href={show(form.id)}>The form</Link>
                    </Button>
                }
            />

            <ol className="mb-4 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                {[
                    'Accepted submissions',
                    'Calculation',
                    'Draft result in M&E',
                    'Verification',
                    'Verified result',
                ].map((step, index) => (
                    <li key={step} className="flex items-center gap-2">
                        {index > 0 && <span aria-hidden>→</span>}
                        <span className="rounded-full border px-2 py-0.5">
                            {step}
                        </span>
                    </li>
                ))}
            </ol>

            {form.programme === null ? (
                <Panel>
                    <PanelBody className="text-sm text-muted-foreground">
                        This form is not part of a programme, so it cannot feed
                        indicators. Choose a programme in the form's details
                        first.
                    </PanelBody>
                </Panel>
            ) : form.version === null ? (
                <Panel>
                    <PanelBody className="text-sm text-muted-foreground">
                        Publish the form first: indicators are calculated from
                        its published questions.
                    </PanelBody>
                </Panel>
            ) : (
                <div className="grid gap-4">
                    {can.manage &&
                        (adding ? (
                            <Panel>
                                <PanelHeader
                                    title="Feed an indicator"
                                    description={`Indicators of ${form.programme.label}.`}
                                />
                                <PanelBody>
                                    <MappingEditor
                                        indicators={indicators.filter(
                                            (indicator) =>
                                                !mapped.has(indicator.id),
                                        )}
                                        questions={questions}
                                        onCancel={() => setAdding(false)}
                                        onSubmit={(data, done) =>
                                            router.post(
                                                store.url(form.id),
                                                data,
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () =>
                                                        setAdding(false),
                                                    onFinish: done,
                                                },
                                            )
                                        }
                                    />
                                </PanelBody>
                            </Panel>
                        ) : (
                            <div>
                                <Button onClick={() => setAdding(true)}>
                                    Feed an indicator
                                </Button>
                            </div>
                        ))}

                    {active.length === 0 && !adding && (
                        <Panel>
                            <PanelBody className="text-sm text-muted-foreground">
                                No indicator is fed by this form yet.
                                {can.manage
                                    ? ' Choose "Feed an indicator" to connect one.'
                                    : ' An M&E manager can connect one.'}
                            </PanelBody>
                        </Panel>
                    )}

                    {active.map((mapping) => (
                        <MappingPanel
                            key={mapping.id}
                            form={form}
                            mapping={mapping}
                            indicators={indicators}
                            questions={questions}
                            periods={periods.filter(
                                (period) =>
                                    period.frequency ===
                                    indicators.find(
                                        (indicator) =>
                                            indicator.id ===
                                            mapping.indicator.id,
                                    )?.frequency,
                            )}
                            units={units}
                            preview={
                                preview?.mappingId === mapping.id
                                    ? preview
                                    : null
                            }
                            can={can}
                        />
                    ))}

                    {retired.length > 0 && (
                        <Panel>
                            <PanelHeader
                                title="Earlier mappings"
                                description="Retired or replaced. Results already sent with them keep the rules they were calculated with."
                            />
                            <PanelBody className="grid gap-2 text-sm">
                                {retired.map((mapping) => (
                                    <div key={mapping.id}>
                                        <span className="font-medium">
                                            {mapping.indicator.label}
                                        </span>
                                        : {describeMapping(mapping, questions)}{' '}
                                        <span className="text-muted-foreground">
                                            ({mapping.runs.length} calculation
                                            {mapping.runs.length === 1
                                                ? ''
                                                : 's'}
                                            )
                                        </span>
                                    </div>
                                ))}
                            </PanelBody>
                        </Panel>
                    )}
                </div>
            )}
        </>
    );
}

function MappingPanel({
    form,
    mapping,
    indicators,
    questions,
    periods,
    units,
    preview,
    can,
}: {
    form: Props['form'];
    mapping: Mapping;
    indicators: IndicatorOption[];
    questions: Question[];
    periods: Props['periods'];
    units: Props['units'];
    preview: Preview | null;
    can: Props['can'];
}) {
    const errors = usePage().props.errors as Errors;
    const [editing, setEditing] = useState(false);
    const [periodId, setPeriodId] = useState(
        preview?.periodId ??
            periods.find((period) => period.open)?.id ??
            periods[0]?.id ??
            '',
    );
    const [unitId, setUnitId] = useState(preview?.unitId ?? units[0]?.id ?? '');
    const [sending, setSending] = useState(false);
    const current =
        preview !== null &&
        preview.periodId === periodId &&
        preview.unitId === unitId
            ? preview
            : null;

    const calculate = () =>
        router.get(
            indicatorsPage.url(form.id, {
                query: { mapping: mapping.id, period: periodId, unit: unitId },
            }),
            {},
            { preserveScroll: true, preserveState: true, only: ['preview'] },
        );

    return (
        <Panel>
            <PanelHeader
                title={mapping.indicator.label}
                description={describeMapping(mapping, questions)}
                actions={
                    can.manage &&
                    !editing && (
                        <div className="flex gap-1">
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setEditing(true)}
                            >
                                Change
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(
                                        retire.url(mapping.id),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Retire
                            </Button>
                        </div>
                    )
                }
            />
            <PanelBody className="grid gap-4">
                {mapping.note && (
                    <p className="text-sm text-muted-foreground">
                        {mapping.note}
                    </p>
                )}

                {editing && (
                    <div className="rounded-md border p-3">
                        {mapping.runs.length > 0 && (
                            <p className="mb-3 text-sm text-muted-foreground">
                                This mapping was used for results already sent.
                                Saving a change keeps it for those results and
                                starts a new version for the next calculations.
                            </p>
                        )}
                        <MappingEditor
                            indicators={indicators.filter(
                                (indicator) =>
                                    indicator.id === mapping.indicator.id,
                            )}
                            questions={questions}
                            initial={mapping}
                            onCancel={() => setEditing(false)}
                            onSubmit={(data, done) =>
                                router.put(update.url(mapping.id), data, {
                                    preserveScroll: true,
                                    onSuccess: () => setEditing(false),
                                    onFinish: done,
                                })
                            }
                        />
                    </div>
                )}

                <div className="grid gap-3 rounded-md bg-muted/40 p-3">
                    <p className="text-sm font-medium">Calculate</p>
                    {periods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            The programme has no reporting period of this
                            indicator's frequency.
                        </p>
                    ) : (
                        <div className="flex flex-wrap items-end gap-2">
                            <Field
                                id={`period-${mapping.id}`}
                                label="Reporting period"
                                className="min-w-40"
                            >
                                <select
                                    id={`period-${mapping.id}`}
                                    className={selectClass}
                                    value={periodId}
                                    onChange={(event) =>
                                        setPeriodId(event.target.value)
                                    }
                                >
                                    {periods.map((period) => (
                                        <option
                                            key={period.id}
                                            value={period.id}
                                        >
                                            {period.label}
                                            {period.open ? '' : ' (closed)'}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Field
                                id={`unit-${mapping.id}`}
                                label="Unit"
                                className="min-w-48"
                            >
                                <select
                                    id={`unit-${mapping.id}`}
                                    className={selectClass}
                                    value={unitId}
                                    onChange={(event) =>
                                        setUnitId(event.target.value)
                                    }
                                >
                                    {units.map((unit) => (
                                        <option key={unit.id} value={unit.id}>
                                            {unit.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>
                            <Button
                                variant="outline"
                                onClick={calculate}
                                disabled={!periodId || !unitId}
                            >
                                Preview
                            </Button>
                        </div>
                    )}

                    {current && (
                        <div className="grid gap-2 text-sm">
                            <p>
                                <span className="text-2xl font-semibold tabular-nums">
                                    {figureText(current, mapping.calculation)}
                                </span>{' '}
                                <span className="text-muted-foreground">
                                    from {current.used} of {current.accepted}{' '}
                                    accepted submission
                                    {current.accepted === 1 ? '' : 's'} (
                                    {current.considered} received for this
                                    period and unit)
                                </span>
                            </p>
                            {current.excluded.length > 0 && (
                                <ul className="grid gap-0.5 text-muted-foreground">
                                    {current.excluded.map((item) => (
                                        <li key={item.reason}>
                                            Left out: {item.count} ·{' '}
                                            {item.label}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {errors.run && (
                                <p className="text-destructive">{errors.run}</p>
                            )}
                            {can.send && (
                                <div>
                                    <Button
                                        disabled={sending || current.used === 0}
                                        onClick={() => {
                                            setSending(true);
                                            router.post(
                                                send.url(mapping.id),
                                                {
                                                    period_id: periodId,
                                                    unit_id: unitId,
                                                },
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () =>
                                                        setSending(false),
                                                },
                                            );
                                        }}
                                    >
                                        Send to M&E as a draft result
                                    </Button>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        It is worked out again when you send it,
                                        and replaces this form's earlier draft
                                        for the same period and unit. It never
                                        replaces a result entered by hand or one
                                        waiting for verification.
                                    </p>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {mapping.runs.length > 0 && (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Period</TableHead>
                                <TableHead>Unit</TableHead>
                                <TableHead className="text-right">
                                    Value
                                </TableHead>
                                <TableHead className="text-right">
                                    Submissions
                                </TableHead>
                                <TableHead>Calculation</TableHead>
                                <TableHead>Result in M&E</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {mapping.runs.map((run) => (
                                <TableRow key={run.id}>
                                    <TableCell>{run.period.label}</TableCell>
                                    <TableCell>{run.unit.label}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        <Link
                                            href={calculationPage(run.id)}
                                            className="underline-offset-4 hover:underline"
                                        >
                                            {figureText(
                                                run,
                                                mapping.calculation,
                                            )}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {run.used}
                                    </TableCell>
                                    <TableCell>
                                        <div className="grid gap-1">
                                            <span className="flex items-center gap-2">
                                                <StatusBadge
                                                    tone={
                                                        RUN_STATUS[run.status]
                                                            .tone
                                                    }
                                                >
                                                    {
                                                        RUN_STATUS[run.status]
                                                            .label
                                                    }
                                                </StatusBadge>
                                                <span className="text-xs text-muted-foreground">
                                                    {formatDateTime(
                                                        run.createdAt,
                                                    )}
                                                </span>
                                            </span>
                                            {run.flag && (
                                                <span className="text-xs text-amber-900 dark:text-amber-100">
                                                    {run.flag}
                                                </span>
                                            )}
                                            {run.newer > 0 && (
                                                <span className="text-xs text-muted-foreground">
                                                    {run.newer} more accepted
                                                    since. Recalculate to
                                                    include them.
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        {run.result === null ? (
                                            '–'
                                        ) : (
                                            <Link
                                                href={resultPage(run.result.id)}
                                                className="flex items-center gap-2"
                                            >
                                                <StatusBadge
                                                    tone={
                                                        SUBMISSION[
                                                            run.result.status
                                                        ].tone
                                                    }
                                                >
                                                    {
                                                        SUBMISSION[
                                                            run.result.status
                                                        ].label
                                                    }
                                                </StatusBadge>
                                                {!run.result.fromThisRun && (
                                                    <span className="text-xs text-muted-foreground">
                                                        now from a later
                                                        calculation
                                                    </span>
                                                )}
                                            </Link>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                )}
            </PanelBody>
        </Panel>
    );
}

type MappingData = {
    indicator_id: string;
    calculation: Calculation | '';
    question: string;
    distinct_by: string;
    condition: Condition | null;
    numerator_condition: Condition | null;
    note: string;
};

function MappingEditor({
    indicators,
    questions,
    initial,
    onCancel,
    onSubmit,
}: {
    indicators: IndicatorOption[];
    questions: Question[];
    initial?: Mapping;
    onCancel: () => void;
    onSubmit: (data: MappingData, done: () => void) => void;
}) {
    const errors = usePage().props.errors as Errors;
    const [indicatorId, setIndicatorId] = useState(
        initial?.indicator.id ?? indicators[0]?.id ?? '',
    );
    const indicator = indicators.find((item) => item.id === indicatorId);
    const allowed = indicator?.calculations ?? [];
    const [calculation, setCalculation] = useState<Calculation | ''>(
        initial?.calculation ?? allowed[0] ?? '',
    );
    const [question, setQuestion] = useState(initial?.question ?? '');
    const [distinctBy, setDistinctBy] = useState(initial?.distinctBy ?? '');
    const [condition, setCondition] = useState<Leaf[] | null>(
        leavesOf(initial?.condition ?? null),
    );
    const [numerator, setNumerator] = useState<Leaf[] | null>(
        leavesOf(initial?.numeratorCondition ?? null),
    );
    const [note, setNote] = useState(initial?.note ?? '');
    const [busy, setBusy] = useState(false);

    const numbers = questions.filter((item) => isNumber(item.type));
    const identifiers = questions.filter((item) =>
        ['text', 'integer', 'select_one'].includes(item.type),
    );
    const usable = calculation !== '' && allowed.includes(calculation);

    if (indicators.length === 0) {
        return (
            <div className="grid gap-3 text-sm">
                <p className="text-muted-foreground">
                    Every indicator of the programme is already fed by this
                    form, or the programme has no indicator yet.
                </p>
                <div>
                    <Button variant="outline" onClick={onCancel}>
                        Close
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <form
            className="grid gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                setBusy(true);
                onSubmit(
                    {
                        indicator_id: indicatorId,
                        calculation,
                        question:
                            calculation === 'sum' || calculation === 'average'
                                ? question
                                : '',
                        distinct_by: distinctBy,
                        // A condition the editor cannot show is kept as it was.
                        condition:
                            condition === null
                                ? (initial?.condition ?? null)
                                : joinLeaves(condition),
                        numerator_condition:
                            calculation !== 'percentage'
                                ? null
                                : numerator === null
                                  ? (initial?.numeratorCondition ?? null)
                                  : joinLeaves(numerator),
                        note,
                    },
                    () => setBusy(false),
                );
            }}
        >
            <div className="grid gap-3 md:grid-cols-2">
                <Field
                    id="mapping-indicator"
                    label="Indicator"
                    error={errors.indicator_id}
                >
                    <select
                        id="mapping-indicator"
                        className={selectClass}
                        value={indicatorId}
                        disabled={initial !== undefined}
                        onChange={(event) => {
                            const next = indicators.find(
                                (item) => item.id === event.target.value,
                            );
                            setIndicatorId(event.target.value);
                            setCalculation(next?.calculations[0] ?? '');
                        }}
                    >
                        {indicators.map((item) => (
                            <option key={item.id} value={item.id}>
                                {item.label}
                                {item.status === 'draft' ? ' (draft)' : ''}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    id="mapping-calculation"
                    label="Calculation"
                    error={errors.calculation}
                    hint={
                        usable
                            ? CALCULATIONS[calculation].explain
                            : indicator
                              ? `No calculation fits a ${indicator.measure} indicator yet.`
                              : undefined
                    }
                >
                    <select
                        id="mapping-calculation"
                        className={selectClass}
                        value={calculation}
                        onChange={(event) =>
                            setCalculation(event.target.value as Calculation)
                        }
                    >
                        {allowed.map((item) => (
                            <option key={item} value={item}>
                                {CALCULATIONS[item].label}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>

            {(calculation === 'sum' || calculation === 'average') && (
                <Field
                    id="mapping-question"
                    label="Number question"
                    error={errors.question}
                >
                    <select
                        id="mapping-question"
                        className={selectClass}
                        value={question}
                        onChange={(event) => setQuestion(event.target.value)}
                    >
                        <option value="">Choose a question</option>
                        {numbers.map((item) => (
                            <option key={item.name} value={item.name}>
                                {item.label}
                            </option>
                        ))}
                    </select>
                </Field>
            )}

            <ConditionEditor
                title="Which accepted submissions count"
                empty="All accepted submissions count."
                leaves={condition}
                questions={questions}
                error={errors.condition}
                onChange={setCondition}
            />

            {calculation === 'percentage' && (
                <ConditionEditor
                    title="Which of them are in the percentage"
                    empty="Add the condition that puts a submission in the percentage."
                    leaves={numerator}
                    questions={questions}
                    error={errors.numerator_condition}
                    onChange={setNumerator}
                />
            )}

            <Field
                id="mapping-distinct"
                label="Count repeat visits once (optional)"
                error={errors.distinct_by}
                hint="Choose the question that identifies the household or person, such as a household ID. When the same one is visited again, only the latest visit counts. Without it, every submission counts."
            >
                <select
                    id="mapping-distinct"
                    className={selectClass}
                    value={distinctBy}
                    onChange={(event) => setDistinctBy(event.target.value)}
                >
                    <option value="">Every submission counts</option>
                    {identifiers.map((item) => (
                        <option key={item.name} value={item.name}>
                            {item.label}
                        </option>
                    ))}
                </select>
            </Field>

            <Field id="mapping-note" label="Note (optional)">
                <textarea
                    id="mapping-note"
                    className={textareaClass}
                    rows={2}
                    value={note}
                    onChange={(event) => setNote(event.target.value)}
                    placeholder="Why it is calculated this way, for the people verifying it"
                />
            </Field>

            {usable && (
                <p className="rounded-md bg-muted/40 p-3 text-sm">
                    {describeMapping(
                        {
                            calculation,
                            question: question || null,
                            condition: joinLeaves(condition ?? []),
                            numeratorCondition: joinLeaves(numerator ?? []),
                            distinctBy: distinctBy || null,
                        },
                        questions,
                    )}
                </p>
            )}

            {errors.form && (
                <p className="text-sm text-destructive">{errors.form}</p>
            )}

            <div className="flex gap-2">
                <Button type="submit" disabled={busy || !usable}>
                    Save
                </Button>
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function ConditionEditor({
    title,
    empty,
    leaves,
    questions,
    error,
    onChange,
}: {
    title: string;
    empty: string;
    leaves: Leaf[] | null;
    questions: Question[];
    error?: string;
    onChange: (leaves: Leaf[] | null) => void;
}) {
    const first = questions[0];
    const add = () =>
        first &&
        onChange([
            ...(leaves ?? []),
            {
                q: first.name,
                op: operatorsFor(first.type)[0],
                value: first.choices[0]?.value ?? '',
            },
        ]);

    return (
        <div className="grid gap-2">
            <p className="text-sm font-medium">{title}</p>
            {leaves === null ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    This condition was set up in a way this page cannot show,
                    and is kept as it is.
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onChange([])}
                    >
                        Start again
                    </Button>
                </div>
            ) : (
                <>
                    {leaves.length === 0 && (
                        <p className="text-sm text-muted-foreground">{empty}</p>
                    )}
                    {leaves.map((leaf, index) => (
                        <ConditionRow
                            key={index}
                            leaf={leaf}
                            joined={index > 0}
                            questions={questions}
                            onChange={(next) =>
                                onChange(
                                    leaves.map((item, at) =>
                                        at === index ? next : item,
                                    ),
                                )
                            }
                            onRemove={() =>
                                onChange(leaves.filter((_, at) => at !== index))
                            }
                        />
                    ))}
                    <div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={add}
                        >
                            {leaves.length === 0
                                ? 'Add a condition'
                                : 'And another condition'}
                        </Button>
                    </div>
                </>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

function ConditionRow({
    leaf,
    joined,
    questions,
    onChange,
    onRemove,
}: {
    leaf: Leaf;
    joined: boolean;
    questions: Question[];
    onChange: (leaf: Leaf) => void;
    onRemove: () => void;
}) {
    const question = questions.find((item) => item.name === leaf.q);
    const type = question?.type ?? 'text';
    const operators = operatorsFor(type);

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="w-8 text-sm text-muted-foreground">
                {joined ? 'and' : 'if'}
            </span>
            <select
                aria-label="Question"
                className={`${selectClass} w-auto max-w-64`}
                value={leaf.q}
                onChange={(event) => {
                    const next = questions.find(
                        (item) => item.name === event.target.value,
                    );
                    onChange({
                        q: event.target.value,
                        op: operatorsFor(next?.type ?? 'text')[0],
                        value: next?.choices[0]?.value ?? '',
                    });
                }}
            >
                {questions.map((item) => (
                    <option key={item.name} value={item.name}>
                        {item.label}
                    </option>
                ))}
            </select>
            <select
                aria-label="Comparison"
                className={`${selectClass} w-auto`}
                value={leaf.op}
                onChange={(event) =>
                    onChange({ ...leaf, op: event.target.value as Leaf['op'] })
                }
            >
                {operators.map((op) => (
                    <option key={op} value={op}>
                        {OPERATORS[op]}
                    </option>
                ))}
            </select>
            {needsValue(leaf.op) &&
                (isChoice(type) ? (
                    <select
                        aria-label="Answer"
                        className={`${selectClass} w-auto max-w-56`}
                        value={String(leaf.value ?? '')}
                        onChange={(event) =>
                            onChange({ ...leaf, value: event.target.value })
                        }
                    >
                        {question?.choices.map((choice) => (
                            <option key={choice.value} value={choice.value}>
                                {choice.label}
                            </option>
                        ))}
                    </select>
                ) : (
                    <Input
                        aria-label="Answer"
                        className="w-40"
                        type={
                            isNumber(type)
                                ? 'number'
                                : type === 'date'
                                  ? 'date'
                                  : 'text'
                        }
                        value={String(leaf.value ?? '')}
                        onChange={(event) =>
                            onChange({
                                ...leaf,
                                value:
                                    isNumber(type) && event.target.value !== ''
                                        ? Number(event.target.value)
                                        : event.target.value,
                            })
                        }
                    />
                ))}
            <Button type="button" size="sm" variant="ghost" onClick={onRemove}>
                Remove
            </Button>
        </div>
    );
}
