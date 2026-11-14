import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
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
import {
    TextAreaField,
    TextField,
    errorFor,
    selectClass,
} from '@/components/fields';
import {
    AGGREGATIONS,
    BREAKDOWN_RULES,
    DIRECTIONS,
    FREQUENCIES,
    INDICATOR_STATUS,
    MEASURES,
    TYPES,
    formatDate,
    formatDateTime,
    formatFigure,
    isFraction,
    trimZeros,
} from '@/lib/mel';
import type { IndicatorDefinition, Option } from '@/lib/mel';
import { useCan } from '@/lib/access';
import { show as framework } from '@/routes/frameworks';
import { baseline, edit, status, targets } from '@/routes/indicators';
import { show as report } from '@/routes/reports';

type History = {
    id: string;
    unitId: string | null;
    unit: string | null;
    live: boolean;
    changeReason: string | null;
    author: string;
    createdAt: string;
};

type Props = {
    programme: { id: string; code: string; name: string; archived: boolean };
    indicator: {
        id: string;
        code: string;
        status: 'draft' | 'active' | 'retired';
        frequency: string;
        responsible: string | null;
        result: { code: string; statement: string; level: string };
    };
    version: IndicatorDefinition;
    versions: {
        version: number;
        name: string;
        measure: string;
        changeReason: string | null;
        author: string;
        createdAt: string;
    }[];
    units: Option[];
    periods: Option[];
    targetPeriods: (Option & { frequency: string })[];
    baselines: (History & {
        value: string;
        numerator: string | null;
        denominator: string | null;
        measuredOn: string;
        source: string | null;
    })[];
    targets: (History & {
        periodId: string;
        period: string;
        value: string | null;
        min: string | null;
        max: string | null;
    })[];
    can: { manage: boolean };
};

export default function IndicatorShow({
    programme,
    indicator,
    version,
    versions,
    units,
    targetPeriods,
    baselines,
    targets: targetHistory,
    can,
}: Props) {
    const allowed = useCan();
    const state = INDICATOR_STATUS[indicator.status];
    const [unitId, setUnitId] = useState('');
    const scopeLabel = (id: string | null) =>
        id === null
            ? 'Whole programme'
            : (units.find((unit) => unit.value === id)?.label ?? 'Unit');
    const liveBaselines = baselines.filter((item) => item.live);

    return (
        <>
            <Head title={`${indicator.code} · ${version.name}`} />
            <PageHeader
                title={
                    <>
                        <span className="text-muted-foreground">
                            {indicator.code}
                        </span>{' '}
                        {version.name}
                    </>
                }
                badges={
                    <>
                        <StatusBadge tone={state.tone}>
                            {state.label}
                        </StatusBadge>
                        <StatusBadge>v{version.version}</StatusBadge>
                    </>
                }
                actions={
                    <>
                        {allowed('mel.reports.view') && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={report('iptt', {
                                        query: { programme: programme.id },
                                    })}
                                >
                                    Performance
                                </Link>
                            </Button>
                        )}
                        {can.manage && (
                            <>
                                <Button variant="outline" asChild>
                                    <Link
                                        href={edit({
                                            programme: programme.id,
                                            indicator: indicator.id,
                                        })}
                                    >
                                        Edit
                                    </Link>
                                </Button>
                                <StatusActions
                                    programmeId={programme.id}
                                    indicatorId={indicator.id}
                                    current={indicator.status}
                                />
                            </>
                        )}
                    </>
                }
            />

            <div className="grid gap-3">
                <Panel>
                    <PanelBody className="grid gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                        <Fact label="Programme">
                            <Link
                                href={framework(programme.id)}
                                className="hover:underline"
                            >
                                {programme.code} · {programme.name}
                            </Link>
                        </Fact>
                        <Fact label={indicator.result.level}>
                            {indicator.result.code} ·{' '}
                            {indicator.result.statement}
                        </Fact>
                        <Fact label="Type">{TYPES[version.type]}</Fact>
                        <Fact label="Reported">
                            {FREQUENCIES[indicator.frequency]}
                        </Fact>
                        <Fact label="Measure">
                            {MEASURES[version.measure]}
                            {version.unitLabel && ` · ${version.unitLabel}`}
                            {version.measure === 'rate' &&
                                version.rateMultiplier &&
                                ` · per ${version.rateMultiplier.toLocaleString()}`}
                        </Fact>
                        <Fact label="Direction">
                            {DIRECTIONS[version.direction]}
                        </Fact>
                        <Fact label="Across units">
                            {AGGREGATIONS[version.unitAggregation]}
                        </Fact>
                        <Fact label="Over time">
                            {AGGREGATIONS[version.timeAggregation]}
                        </Fact>
                        {isFraction(version.measure) && (
                            <Fact label="Parts" wide>
                                {version.numeratorLabel ?? 'Numerator'} ÷{' '}
                                {version.denominatorLabel ?? 'Denominator'}
                            </Fact>
                        )}
                        <Fact label="Definition" wide>
                            {version.definition ?? '—'}
                        </Fact>
                        <Fact label="Calculation">
                            {version.calculationMethod ?? '—'}
                        </Fact>
                        <Fact label="Data source">
                            {version.dataSource ?? '—'}
                            {version.collectionMethod &&
                                ` · ${version.collectionMethod}`}
                        </Fact>
                        <Fact label="Means of verification">
                            {version.meansOfVerification ?? '—'}
                        </Fact>
                        <Fact label="Responsible">
                            {indicator.responsible ?? '—'}
                        </Fact>
                        <Fact label="Disaggregated by" wide>
                            {version.breakdowns.length === 0
                                ? 'Not disaggregated'
                                : version.breakdowns
                                      .map(
                                          (breakdown) =>
                                              `${breakdown.label} (${BREAKDOWN_RULES[breakdown.rule].toLowerCase()})`,
                                      )
                                      .join('; ')}
                        </Fact>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Baseline"
                        description="Where things stood before the programme. A change keeps the earlier value on record."
                    />
                    {liveBaselines.length === 0 ? (
                        <p className="p-3 text-sm text-muted-foreground">
                            No baseline yet.
                        </p>
                    ) : (
                        <ul className="divide-y text-sm">
                            {liveBaselines.map((item) => (
                                <li
                                    key={item.id}
                                    className="flex flex-wrap justify-between gap-2 px-3 py-2"
                                >
                                    <span>{scopeLabel(item.unitId)}</span>
                                    <span>
                                        <span className="font-medium tabular-nums">
                                            {formatFigure(item.value, version)}
                                        </span>
                                        {item.numerator !== null && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                ({item.numerator} ÷{' '}
                                                {item.denominator})
                                            </span>
                                        )}
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · measured{' '}
                                            {formatDate(item.measuredOn)}
                                            {item.source && ` · ${item.source}`}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {can.manage && (
                        <BaselineForm
                            programmeId={programme.id}
                            indicatorId={indicator.id}
                            units={units}
                            fraction={isFraction(version.measure)}
                            percentage={version.measure === 'percentage'}
                        />
                    )}
                </Panel>

                <TargetsPanel
                    programmeId={programme.id}
                    indicatorId={indicator.id}
                    frequency={indicator.frequency}
                    version={version}
                    units={units}
                    unitId={unitId}
                    onUnit={setUnitId}
                    periods={targetPeriods}
                    history={targetHistory}
                    canManage={can.manage}
                />

                <Panel>
                    <PanelHeader
                        title="History"
                        description="Every version of the definition, baseline and target, with who changed it and why."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>What</TableHead>
                                <TableHead>Change</TableHead>
                                <TableHead>Why</TableHead>
                                <TableHead>By</TableHead>
                                <TableHead>When</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {[
                                ...versions.map((item) => ({
                                    key: `v${item.version}`,
                                    what: `Definition v${item.version}`,
                                    change: item.name,
                                    why: item.changeReason,
                                    by: item.author,
                                    at: item.createdAt,
                                    live: item.version === version.version,
                                })),
                                ...baselines.map((item) => ({
                                    key: item.id,
                                    what: `Baseline · ${scopeLabel(item.unitId)}`,
                                    change: formatFigure(item.value, version),
                                    why: item.changeReason,
                                    by: item.author,
                                    at: item.createdAt,
                                    live: item.live,
                                })),
                                ...targetHistory.map((item) => ({
                                    key: item.id,
                                    what: `Target ${item.period} · ${scopeLabel(item.unitId)}`,
                                    change:
                                        item.value === null
                                            ? `${formatFigure(item.min, version)} to ${formatFigure(item.max, version)}`
                                            : formatFigure(item.value, version),
                                    why: item.changeReason,
                                    by: item.author,
                                    at: item.createdAt,
                                    live: item.live,
                                })),
                            ]
                                .sort((a, b) => b.at.localeCompare(a.at))
                                .map((row) => (
                                    <TableRow key={row.key}>
                                        <TableCell>
                                            {row.what}
                                            {!row.live && (
                                                <StatusBadge className="ml-2">
                                                    Replaced
                                                </StatusBadge>
                                            )}
                                        </TableCell>
                                        <TableCell className="tabular-nums">
                                            {row.change}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {row.why ?? '—'}
                                        </TableCell>
                                        <TableCell>{row.by}</TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {formatDateTime(row.at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                        </TableBody>
                    </Table>
                </Panel>
            </div>
        </>
    );
}

function Fact({
    label,
    wide = false,
    children,
}: {
    label: string;
    wide?: boolean;
    children: ReactNode;
}) {
    return (
        <div className={wide ? 'sm:col-span-2' : ''}>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-0.5 whitespace-pre-line">{children}</div>
        </div>
    );
}

function StatusActions({
    programmeId,
    indicatorId,
    current,
}: {
    programmeId: string;
    indicatorId: string;
    current: 'draft' | 'active' | 'retired';
}) {
    const next: { status: string; label: string }[] =
        current === 'draft'
            ? [{ status: 'active', label: 'Make active' }]
            : current === 'active'
              ? [
                    { status: 'retired', label: 'Retire' },
                    { status: 'draft', label: 'Back to draft' },
                ]
              : [{ status: 'active', label: 'Reactivate' }];

    return (
        <>
            {next.map((item) => (
                <Form
                    key={item.status}
                    {...status.form({
                        programme: programmeId,
                        indicator: indicatorId,
                    })}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="status"
                                value={item.status}
                            />
                            <Button
                                type="submit"
                                variant={
                                    item.status === 'active'
                                        ? 'default'
                                        : 'ghost'
                                }
                                disabled={processing}
                            >
                                {item.label}
                            </Button>
                            {errors.status && (
                                <p className="text-xs text-destructive">
                                    {errors.status}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            ))}
        </>
    );
}

function BaselineForm({
    programmeId,
    indicatorId,
    units,
    fraction,
    percentage,
}: {
    programmeId: string;
    indicatorId: string;
    units: Option[];
    fraction: boolean;
    percentage: boolean;
}) {
    return (
        <Form
            {...baseline.form({
                programme: programmeId,
                indicator: indicatorId,
            })}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="grid gap-3 border-t p-3 sm:grid-cols-2 lg:grid-cols-4"
        >
            {({ errors, processing }) => (
                <>
                    <div className="grid content-start gap-1.5">
                        <label
                            htmlFor="baseline-unit"
                            className="text-sm font-medium"
                        >
                            For
                        </label>
                        <select
                            id="baseline-unit"
                            name="unit_id"
                            className={selectClass}
                        >
                            <option value="">Whole programme</option>
                            {units.map((unit) => (
                                <option key={unit.value} value={unit.value}>
                                    {unit.label}
                                </option>
                            ))}
                        </select>
                        {errors.unit_id && (
                            <p className="text-sm text-destructive">
                                {errors.unit_id}
                            </p>
                        )}
                    </div>
                    <TextField
                        name="value"
                        label={percentage ? 'Value (%)' : 'Value'}
                        inputMode="decimal"
                        error={errors.value}
                        prefix="baseline-"
                    />
                    {fraction && (
                        <>
                            <TextField
                                name="numerator"
                                label="Numerator (optional)"
                                inputMode="decimal"
                                error={errors.numerator}
                                prefix="baseline-"
                            />
                            <TextField
                                name="denominator"
                                label="Denominator (optional)"
                                inputMode="decimal"
                                error={errors.denominator}
                                prefix="baseline-"
                            />
                        </>
                    )}
                    <TextField
                        name="measured_on"
                        label="Measured on"
                        type="date"
                        error={errors.measured_on}
                        prefix="baseline-"
                    />
                    <TextField
                        name="source"
                        label="Source"
                        placeholder="Baseline survey 2025"
                        error={errors.source}
                        prefix="baseline-"
                    />
                    <TextAreaField
                        name="change_reason"
                        label="Reason, when replacing a baseline"
                        rows={1}
                        error={errors.change_reason}
                        prefix="baseline-"
                        className="sm:col-span-2"
                    />
                    <div className="sm:col-span-2 lg:col-span-4">
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={processing}
                        >
                            Save baseline
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function TargetsPanel({
    programmeId,
    indicatorId,
    frequency,
    version,
    units,
    unitId,
    onUnit,
    periods,
    history,
    canManage,
}: {
    programmeId: string;
    indicatorId: string;
    frequency: string;
    version: IndicatorDefinition;
    units: Option[];
    unitId: string;
    onUnit: (unitId: string) => void;
    periods: (Option & { frequency: string })[];
    history: Props['targets'];
    canManage: boolean;
}) {
    const own = periods.filter((period) => period.frequency === frequency);
    const live = history.filter(
        (item) => item.live && (item.unitId ?? '') === unitId,
    );
    const current = (periodId: string) =>
        live.find((item) => item.periodId === periodId);
    const range = version.direction === 'maintain';

    return (
        <Panel>
            <PanelHeader
                title="Targets"
                description={
                    range
                        ? 'A range to stay within, for each period.'
                        : 'What each period should reach. Leave a period empty for no target.'
                }
                actions={
                    units.length > 0 && (
                        <select
                            aria-label="Targets for"
                            value={unitId}
                            onChange={(event) => onUnit(event.target.value)}
                            className={`${selectClass} h-8 w-auto`}
                        >
                            <option value="">Whole programme</option>
                            {units.map((unit) => (
                                <option key={unit.value} value={unit.value}>
                                    {unit.label}
                                </option>
                            ))}
                        </select>
                    )
                }
            />
            {own.length === 0 ? (
                <p className="p-3 text-sm text-muted-foreground">
                    The programme has no {FREQUENCIES[frequency].toLowerCase()}{' '}
                    periods yet. Add them on the programme page.
                </p>
            ) : (
                <Form
                    key={unitId}
                    {...targets.form({
                        programme: programmeId,
                        indicator: indicatorId,
                    })}
                    options={{ preserveScroll: true }}
                >
                    {({ errors, processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="unit_id"
                                value={unitId}
                            />
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Period</TableHead>
                                        {range ? (
                                            <>
                                                <TableHead>From</TableHead>
                                                <TableHead>To</TableHead>
                                            </>
                                        ) : (
                                            <TableHead>
                                                Target
                                                {version.measure ===
                                                    'percentage' && ' (%)'}
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {own.map((period, index) => {
                                        const target = current(period.value);

                                        return (
                                            <TableRow key={period.value}>
                                                <TableCell>
                                                    {period.label}
                                                    <input
                                                        type="hidden"
                                                        name={`rows[${index}][period_id]`}
                                                        value={period.value}
                                                    />
                                                </TableCell>
                                                {range ? (
                                                    <>
                                                        <TargetInput
                                                            name={`rows[${index}][min_value]`}
                                                            label={`${period.label} from`}
                                                            value={target?.min}
                                                            disabled={
                                                                !canManage
                                                            }
                                                            error={
                                                                errors[
                                                                    `rows.${index}.min_value`
                                                                ]
                                                            }
                                                        />
                                                        <TargetInput
                                                            name={`rows[${index}][max_value]`}
                                                            label={`${period.label} to`}
                                                            value={target?.max}
                                                            disabled={
                                                                !canManage
                                                            }
                                                            error={
                                                                errors[
                                                                    `rows.${index}.max_value`
                                                                ]
                                                            }
                                                        />
                                                    </>
                                                ) : (
                                                    <TargetInput
                                                        name={`rows[${index}][value]`}
                                                        label={`${period.label} target`}
                                                        value={target?.value}
                                                        disabled={!canManage}
                                                        error={
                                                            errorFor(
                                                                errors,
                                                                `rows.${index}`,
                                                            ) ??
                                                            (index === 0
                                                                ? errors.value
                                                                : undefined)
                                                        }
                                                    />
                                                )}
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                            {canManage && (
                                <div className="grid gap-3 border-t p-3 sm:grid-cols-[1fr_auto] sm:items-end">
                                    <TextAreaField
                                        name="change_reason"
                                        label="Reason, when changing a target"
                                        hint="Kept with the earlier target in the history."
                                        rows={1}
                                        error={errors.change_reason}
                                        prefix="targets-"
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Save targets
                                    </Button>
                                </div>
                            )}
                        </>
                    )}
                </Form>
            )}
        </Panel>
    );
}

function TargetInput({
    name,
    label,
    value,
    disabled,
    error,
}: {
    name: string;
    label: string;
    value: string | null | undefined;
    disabled: boolean;
    error?: string;
}) {
    return (
        <TableCell>
            <Input
                name={name}
                aria-label={label}
                inputMode="decimal"
                defaultValue={value ? trimZeros(value) : ''}
                disabled={disabled}
                className="h-8 max-w-40 tabular-nums"
                aria-invalid={error ? true : undefined}
            />
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </TableCell>
    );
}
