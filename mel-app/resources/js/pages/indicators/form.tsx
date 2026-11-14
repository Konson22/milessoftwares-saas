import { Form, Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Field,
    SelectField,
    TextAreaField,
    TextField,
    errorFor,
    selectClass,
} from '@/components/fields';
import {
    AGGREGATIONS,
    BREAKDOWN_RULES,
    DIRECTIONS,
    MEASURES,
    TYPES,
    isFraction,
} from '@/lib/mel';
import type { IndicatorDefinition, Measure, Option } from '@/lib/mel';
import { show as framework } from '@/routes/frameworks';
import { show, store, update } from '@/routes/indicators';

type Props = {
    programme: { id: string; code: string; name: string };
    indicator: {
        id: string;
        code: string;
        resultId: string;
        frequency: string;
        responsibleId: string | null;
        sortOrder: number;
        inUse: boolean;
        definition: IndicatorDefinition;
    } | null;
    resultId: string | null;
    results: Option[];
    members: Option[];
    dimensions: Option[];
    frequencies: Option[];
    rules: {
        measures: Measure[];
        types: string[];
        directions: string[];
        timeAggregations: Record<Measure, string[]>;
        unitAggregations: Record<Measure, string[]>;
        breakdownRules: Record<Measure, string[]>;
    };
};

type Breakdown = {
    key: number;
    dimensionId: string;
    secondDimensionId: string;
    rule: string;
};

export default function IndicatorForm({
    programme,
    indicator,
    resultId,
    results,
    members,
    dimensions,
    frequencies,
    rules,
}: Props) {
    const definition = indicator?.definition;
    const [measure, setMeasure] = useState<Measure>(
        definition?.measure ?? 'count',
    );
    const [breakdowns, setBreakdowns] = useState<Breakdown[]>(
        definition?.breakdowns.map((breakdown, index) => ({
            key: index,
            dimensionId: breakdown.dimensionId,
            secondDimensionId: breakdown.secondDimensionId ?? '',
            rule: breakdown.rule,
        })) ?? [],
    );
    const fraction = isFraction(measure);
    const timeOptions = rules.timeAggregations[measure];
    const unitOptions = rules.unitAggregations[measure];
    const ruleOptions = rules.breakdownRules[measure];
    const title = indicator
        ? `Edit ${indicator.code}`
        : `New indicator · ${programme.code}`;
    const action = indicator
        ? update.form({ programme: programme.id, indicator: indicator.id })
        : store.form(programme.id);
    const asOptions = (values: string[], labels: Record<string, string>) =>
        values.map((value) => ({ value, label: labels[value] ?? value }));

    return (
        <>
            <Head title={title} />
            <PageHeader title={title} />

            <Form {...action} className="grid max-w-4xl gap-3">
                {({ errors, processing }) => (
                    <>
                        {indicator?.inUse && (
                            <p className="border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                                Results have been reported under version{' '}
                                {definition?.version}. Changing the definition
                                makes a new version: earlier results keep the
                                definition they were reported with. The measure
                                cannot change.
                            </p>
                        )}

                        <Panel>
                            <PanelHeader title="Where it sits" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <SelectField
                                    name="result_id"
                                    label="Measures the result"
                                    options={results}
                                    defaultValue={
                                        indicator?.resultId ?? resultId
                                    }
                                    error={errors.result_id}
                                    className="sm:col-span-2"
                                />
                                <TextField
                                    name="code"
                                    label="Code"
                                    placeholder="OP1.1"
                                    defaultValue={indicator?.code}
                                    error={errors.code}
                                    required
                                />
                                <SelectField
                                    name="frequency"
                                    label="Reported"
                                    options={frequencies}
                                    defaultValue={
                                        indicator?.frequency ?? 'quarterly'
                                    }
                                    error={errors.frequency}
                                />
                                <SelectField
                                    name="responsible_id"
                                    label="Responsible"
                                    options={members}
                                    empty="Nobody"
                                    defaultValue={indicator?.responsibleId}
                                    error={errors.responsible_id}
                                />
                                <TextField
                                    name="sort_order"
                                    label="Order in lists"
                                    type="number"
                                    defaultValue={indicator?.sortOrder ?? 0}
                                    error={errors.sort_order}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Definition" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    name="name"
                                    label="Name"
                                    defaultValue={definition?.name}
                                    error={errors.name}
                                    className="sm:col-span-2"
                                    required
                                />
                                <TextAreaField
                                    name="definition"
                                    label="What it means"
                                    hint="Who and what counts, and what does not."
                                    defaultValue={definition?.definition}
                                    error={errors.definition}
                                    className="sm:col-span-2"
                                />
                                <SelectField
                                    name="type"
                                    label="Type"
                                    options={asOptions(rules.types, TYPES)}
                                    defaultValue={definition?.type ?? 'output'}
                                    error={errors.type}
                                />
                                <SelectField
                                    name="direction"
                                    label="Direction"
                                    options={asOptions(
                                        rules.directions,
                                        DIRECTIONS,
                                    )}
                                    defaultValue={
                                        definition?.direction ?? 'increase'
                                    }
                                    error={errors.direction}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Measure and calculation"
                                description="How values are entered, and how they combine across units and over time. Nothing is added up or averaged unless the measure allows it."
                            />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="measure"
                                    label="Measure"
                                    error={errors.measure}
                                >
                                    <select
                                        id="measure"
                                        name="measure"
                                        value={measure}
                                        disabled={indicator?.inUse}
                                        onChange={(event) =>
                                            setMeasure(
                                                event.target.value as Measure,
                                            )
                                        }
                                        className={selectClass}
                                    >
                                        {rules.measures.map((value) => (
                                            <option key={value} value={value}>
                                                {MEASURES[value]}
                                            </option>
                                        ))}
                                    </select>
                                    {indicator?.inUse && (
                                        <input
                                            type="hidden"
                                            name="measure"
                                            value={measure}
                                        />
                                    )}
                                </Field>
                                {measure !== 'yes_no' && (
                                    <TextField
                                        name="unit_label"
                                        label={
                                            measure === 'currency'
                                                ? 'Currency'
                                                : 'Unit'
                                        }
                                        placeholder={
                                            measure === 'count'
                                                ? 'people'
                                                : measure === 'rate'
                                                  ? 'per 1,000 live births'
                                                  : ''
                                        }
                                        defaultValue={definition?.unitLabel}
                                        error={errors.unit_label}
                                    />
                                )}
                                {measure !== 'count' && measure !== 'yes_no' ? (
                                    <TextField
                                        name="decimals"
                                        label="Decimal places shown"
                                        type="number"
                                        defaultValue={definition?.decimals ?? 1}
                                        error={errors.decimals}
                                    />
                                ) : (
                                    <input
                                        type="hidden"
                                        name="decimals"
                                        value="0"
                                    />
                                )}
                                {fraction && (
                                    <>
                                        <TextField
                                            name="numerator_label"
                                            label="Numerator"
                                            placeholder="Households using a safe source"
                                            defaultValue={
                                                definition?.numeratorLabel
                                            }
                                            error={errors.numerator_label}
                                        />
                                        <TextField
                                            name="denominator_label"
                                            label="Denominator"
                                            placeholder="Households surveyed"
                                            defaultValue={
                                                definition?.denominatorLabel
                                            }
                                            error={errors.denominator_label}
                                        />
                                    </>
                                )}
                                {measure === 'rate' && (
                                    <TextField
                                        name="rate_multiplier"
                                        label="Per"
                                        type="number"
                                        placeholder="1000"
                                        defaultValue={
                                            definition?.rateMultiplier
                                        }
                                        error={errors.rate_multiplier}
                                    />
                                )}
                                <SelectField
                                    key={`unit-${measure}`}
                                    name="unit_aggregation"
                                    label="Across units in a period"
                                    options={asOptions(
                                        unitOptions,
                                        AGGREGATIONS,
                                    )}
                                    defaultValue={
                                        definition &&
                                        unitOptions.includes(
                                            definition.unitAggregation,
                                        )
                                            ? definition.unitAggregation
                                            : unitOptions[0]
                                    }
                                    error={errors.unit_aggregation}
                                />
                                <SelectField
                                    key={`time-${measure}`}
                                    name="time_aggregation"
                                    label="Over periods, to date"
                                    options={asOptions(
                                        timeOptions,
                                        AGGREGATIONS,
                                    )}
                                    defaultValue={
                                        definition &&
                                        timeOptions.includes(
                                            definition.timeAggregation,
                                        )
                                            ? definition.timeAggregation
                                            : timeOptions[0]
                                    }
                                    error={errors.time_aggregation}
                                />
                                <TextAreaField
                                    name="calculation_method"
                                    label="Calculation method"
                                    defaultValue={definition?.calculationMethod}
                                    error={errors.calculation_method}
                                    className="sm:col-span-2"
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Data collection" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    name="data_source"
                                    label="Data source"
                                    defaultValue={definition?.dataSource}
                                    error={errors.data_source}
                                />
                                <TextField
                                    name="collection_method"
                                    label="Collection method"
                                    defaultValue={definition?.collectionMethod}
                                    error={errors.collection_method}
                                />
                                <TextAreaField
                                    name="means_of_verification"
                                    label="Means of verification"
                                    defaultValue={
                                        definition?.meansOfVerification
                                    }
                                    error={errors.means_of_verification}
                                    className="sm:col-span-2"
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Disaggregation"
                                description="Breakdowns entered with each result, by aggregate counts only (age groups, never dates of birth)."
                                actions={
                                    breakdowns.length < 6 &&
                                    dimensions.length > 0 && (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                setBreakdowns([
                                                    ...breakdowns,
                                                    {
                                                        key: Date.now(),
                                                        dimensionId:
                                                            dimensions[0].value,
                                                        secondDimensionId: '',
                                                        rule: ruleOptions[0],
                                                    },
                                                ])
                                            }
                                        >
                                            <Plus aria-hidden />
                                            Add a breakdown
                                        </Button>
                                    )
                                }
                            />
                            <PanelBody className="grid gap-3">
                                {breakdowns.length === 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        {dimensions.length === 0
                                            ? 'Add dimensions (sex, age group and the like) in Settings first.'
                                            : 'No breakdowns: results are entered as a total only.'}
                                    </p>
                                )}
                                {breakdowns.map((breakdown, index) => (
                                    <div
                                        key={breakdown.key}
                                        className="grid items-end gap-3 sm:grid-cols-[1fr_1fr_1fr_auto]"
                                    >
                                        <Field
                                            id={`breakdown-${index}`}
                                            label="By"
                                            error={errorFor(
                                                errors,
                                                `breakdowns.${index}`,
                                            )}
                                        >
                                            <select
                                                id={`breakdown-${index}`}
                                                name={`breakdowns[${index}][dimension_id]`}
                                                defaultValue={
                                                    breakdown.dimensionId
                                                }
                                                className={selectClass}
                                            >
                                                {dimensions.map((dimension) => (
                                                    <option
                                                        key={dimension.value}
                                                        value={dimension.value}
                                                    >
                                                        {dimension.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field
                                            id={`breakdown-${index}-second`}
                                            label="And by (crossed)"
                                        >
                                            <select
                                                id={`breakdown-${index}-second`}
                                                name={`breakdowns[${index}][second_dimension_id]`}
                                                defaultValue={
                                                    breakdown.secondDimensionId
                                                }
                                                className={selectClass}
                                            >
                                                <option value="">
                                                    Nothing
                                                </option>
                                                {dimensions.map((dimension) => (
                                                    <option
                                                        key={dimension.value}
                                                        value={dimension.value}
                                                    >
                                                        {dimension.label}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Field
                                            id={`breakdown-${index}-rule`}
                                            label="Against the total"
                                        >
                                            <select
                                                key={measure}
                                                id={`breakdown-${index}-rule`}
                                                name={`breakdowns[${index}][rule]`}
                                                defaultValue={
                                                    ruleOptions.includes(
                                                        breakdown.rule,
                                                    )
                                                        ? breakdown.rule
                                                        : ruleOptions[0]
                                                }
                                                className={selectClass}
                                            >
                                                {ruleOptions.map((rule) => (
                                                    <option
                                                        key={rule}
                                                        value={rule}
                                                    >
                                                        {BREAKDOWN_RULES[rule]}
                                                    </option>
                                                ))}
                                            </select>
                                        </Field>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            onClick={() =>
                                                setBreakdowns(
                                                    breakdowns.filter(
                                                        (item) =>
                                                            item.key !==
                                                            breakdown.key,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    </div>
                                ))}
                            </PanelBody>
                        </Panel>

                        {indicator && (
                            <Panel>
                                <PanelBody>
                                    <TextAreaField
                                        name="change_reason"
                                        label="Why the definition changes"
                                        hint={
                                            indicator.inUse
                                                ? 'Needed for a new version; kept in its history.'
                                                : 'Kept in its history when results exist.'
                                        }
                                        rows={2}
                                        error={errors.change_reason}
                                    />
                                </PanelBody>
                            </Panel>
                        )}

                        <div className="flex gap-2">
                            <Button type="submit" disabled={processing}>
                                {indicator ? 'Save' : 'Create as draft'}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        indicator
                                            ? show({
                                                  programme: programme.id,
                                                  indicator: indicator.id,
                                              })
                                            : framework(programme.id)
                                    }
                                >
                                    Cancel
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
