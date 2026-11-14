import { Form, Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    CheckboxField,
    Field,
    TextAreaField,
    errorFor,
    selectClass,
} from '@/components/fields';
import {
    BREAKDOWN_RULES,
    SUBMISSION,
    formatBytes,
    formatDate,
    formatFigure,
    formatTarget,
    isFraction,
    trimZeros,
} from '@/lib/mel';
import type { IndicatorDefinition, SubmissionStatus, Target } from '@/lib/mel';
import { index, save } from '@/routes/data';
import { destroy as removeFile, download } from '@/routes/evidence';
import {
    discard,
    evidence as attach,
    show as submissionPage,
} from '@/routes/submissions';

type Category = { id: string; name: string };

type Breakdown = {
    id: string;
    label: string;
    rule: string;
    categories: Category[];
    secondCategories: Category[] | null;
};

type Figure = {
    value: string | null;
    numerator: string | null;
    denominator: string | null;
};

type Props = {
    programme: { id: string; code: string; name: string };
    period: { id: string; label: string; dueOn: string; open: boolean };
    unit: { id: string; code: string; name: string };
    indicator: { id: string; code: string; status: string };
    version: Omit<IndicatorDefinition, 'breakdowns'> & {
        breakdowns: Breakdown[];
    };
    target: Target | null;
    baseline: { value: string; measuredOn: string } | null;
    submission:
        | (Figure & {
              id: string;
              status: SubmissionStatus;
              revision: number;
              narrative: string | null;
              deviationNote: string | null;
              cells: (Figure & {
                  breakdownId: string;
                  categoryId: string;
                  secondCategoryId: string | null;
              })[];
              evidence: {
                  id: string;
                  name: string;
                  size: number;
                  shareWithDonors: boolean;
              }[];
              sentBack: string | null;
          })
        | null;
    standing: {
        id: string;
        status: SubmissionStatus;
        revision: number;
        value: string | null;
    } | null;
};

export default function Entry({
    programme,
    period,
    unit,
    indicator,
    version,
    target,
    baseline,
    submission,
    standing,
}: Props) {
    const fraction = isFraction(version.measure);
    const editable =
        submission === null ||
        submission.status === 'draft' ||
        submission.status === 'rejected';
    const back = index({
        query: { programme: programme.id, period: period.id },
    });
    const cell = (
        breakdownId: string,
        categoryId: string,
        secondId: string | null,
    ) =>
        submission?.cells.find(
            (item) =>
                item.breakdownId === breakdownId &&
                item.categoryId === categoryId &&
                (item.secondCategoryId ?? null) === secondId,
        );
    // Each breakdown cell's place in the cells[] list sent to the server.
    const slots = version.breakdowns.flatMap((breakdown) =>
        (breakdown.secondCategories ?? [null]).flatMap((second) =>
            breakdown.categories.map(
                (category) =>
                    `${breakdown.id}|${category.id}|${second?.id ?? ''}`,
            ),
        ),
    );

    return (
        <>
            <Head
                title={`${indicator.code} · ${unit.code} · ${period.label}`}
            />
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
                    submission && (
                        <StatusBadge tone={SUBMISSION[submission.status].tone}>
                            {SUBMISSION[submission.status].label}
                        </StatusBadge>
                    )
                }
                actions={
                    <Button variant="outline" asChild>
                        <Link href={back}>Back to the grid</Link>
                    </Button>
                }
            />

            <div className="grid max-w-4xl gap-3">
                <Panel>
                    <PanelBody className="grid gap-3 text-sm sm:grid-cols-4">
                        <Fact label="Programme">
                            {programme.code} · {programme.name}
                        </Fact>
                        <Fact label="Unit">
                            {unit.code} · {unit.name}
                        </Fact>
                        <Fact label="Period">
                            {period.label} · due {formatDate(period.dueOn)}
                        </Fact>
                        <Fact label="Target">
                            {formatTarget(target, version)}
                            {baseline && (
                                <span className="block text-xs text-muted-foreground">
                                    Baseline{' '}
                                    {formatFigure(baseline.value, version)}
                                </span>
                            )}
                        </Fact>
                        {version.definition && (
                            <div className="text-muted-foreground sm:col-span-4">
                                {version.definition}
                            </div>
                        )}
                    </PanelBody>
                </Panel>

                {submission?.sentBack && (
                    <p className="border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">
                        <span className="font-medium">Sent back:</span>{' '}
                        {submission.sentBack}
                    </p>
                )}
                {standing && submission === null && (
                    <p className="border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900">
                        A {SUBMISSION[standing.status].label.toLowerCase()}{' '}
                        value of {formatFigure(standing.value, version)} stands
                        for this period. Saving here starts a correction: it
                        replaces that value only once it is verified.{' '}
                        <Link
                            href={submissionPage(standing.id)}
                            className="underline"
                        >
                            See it
                        </Link>
                    </p>
                )}
                {submission && submission.revision > 1 && (
                    <p className="text-sm text-muted-foreground">
                        Correction, revision {submission.revision}.
                    </p>
                )}
                {!period.open && (
                    <p className="text-sm text-muted-foreground">
                        This period is closed for entry.
                    </p>
                )}

                {!editable && submission ? (
                    <Panel>
                        <PanelBody className="text-sm">
                            This result is{' '}
                            {SUBMISSION[submission.status].label.toLowerCase()}{' '}
                            and can no longer be changed here.{' '}
                            <Link
                                href={submissionPage(submission.id)}
                                className="underline"
                            >
                                Open it
                            </Link>
                        </PanelBody>
                    </Panel>
                ) : (
                    <Form
                        {...save.form({
                            programme: programme.id,
                            period: period.id,
                            indicator: indicator.id,
                            unit: unit.id,
                        })}
                        options={{ preserveScroll: true }}
                        className="grid gap-3"
                    >
                        {({ errors, processing }) => (
                            <>
                                <Panel>
                                    <PanelHeader
                                        title="Result"
                                        description={
                                            fraction
                                                ? 'Enter both parts: the figure is worked out from them.'
                                                : undefined
                                        }
                                    />
                                    <PanelBody className="grid gap-4 sm:grid-cols-3">
                                        <FigureInputs
                                            version={version}
                                            figure={submission}
                                            name={(part) => part}
                                            errors={errors}
                                            errorKey={(part) => part}
                                            label="Total"
                                        />
                                    </PanelBody>
                                </Panel>

                                {version.breakdowns.map((breakdown) => (
                                    <Panel key={breakdown.id}>
                                        <PanelHeader
                                            title={`By ${breakdown.label}`}
                                            description={
                                                BREAKDOWN_RULES[breakdown.rule]
                                            }
                                        />
                                        <PanelBody className="grid gap-3">
                                            {(
                                                breakdown.secondCategories ?? [
                                                    null,
                                                ]
                                            ).map((second) => (
                                                <div
                                                    key={second?.id ?? 'all'}
                                                    className="grid gap-2"
                                                >
                                                    {second && (
                                                        <div className="text-xs font-medium text-muted-foreground">
                                                            {second.name}
                                                        </div>
                                                    )}
                                                    <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                                        {breakdown.categories.map(
                                                            (category) => {
                                                                const at =
                                                                    slots.indexOf(
                                                                        `${breakdown.id}|${category.id}|${second?.id ?? ''}`,
                                                                    );
                                                                const existing =
                                                                    cell(
                                                                        breakdown.id,
                                                                        category.id,
                                                                        second?.id ??
                                                                            null,
                                                                    );

                                                                return (
                                                                    <div
                                                                        key={
                                                                            category.id
                                                                        }
                                                                        className="grid gap-2"
                                                                    >
                                                                        <input
                                                                            type="hidden"
                                                                            name={`cells[${at}][breakdown_id]`}
                                                                            value={
                                                                                breakdown.id
                                                                            }
                                                                        />
                                                                        <input
                                                                            type="hidden"
                                                                            name={`cells[${at}][category_id]`}
                                                                            value={
                                                                                category.id
                                                                            }
                                                                        />
                                                                        {second && (
                                                                            <input
                                                                                type="hidden"
                                                                                name={`cells[${at}][second_category_id]`}
                                                                                value={
                                                                                    second.id
                                                                                }
                                                                            />
                                                                        )}
                                                                        <FigureInputs
                                                                            version={
                                                                                version
                                                                            }
                                                                            figure={
                                                                                existing ??
                                                                                null
                                                                            }
                                                                            name={(
                                                                                part,
                                                                            ) =>
                                                                                `cells[${at}][${part}]`
                                                                            }
                                                                            errors={
                                                                                errors
                                                                            }
                                                                            errorKey={(
                                                                                part,
                                                                            ) =>
                                                                                `cells.${at}.${part}`
                                                                            }
                                                                            label={
                                                                                second
                                                                                    ? `${category.name}, ${second.name}`
                                                                                    : category.name
                                                                            }
                                                                            compact
                                                                        />
                                                                    </div>
                                                                );
                                                            },
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </PanelBody>
                                    </Panel>
                                ))}
                                {errors.cells && (
                                    <p className="text-sm text-destructive">
                                        {errors.cells}
                                    </p>
                                )}

                                <Panel>
                                    <PanelBody className="grid gap-4">
                                        <TextAreaField
                                            name="narrative"
                                            label="Narrative"
                                            hint="What happened this period, in a few lines."
                                            defaultValue={submission?.narrative}
                                            error={errors.narrative}
                                        />
                                        <TextAreaField
                                            name="deviation_note"
                                            label="Why it differs from the target"
                                            hint="Needed to submit a result that is off track."
                                            defaultValue={
                                                submission?.deviationNote
                                            }
                                            error={errors.deviation_note}
                                        />
                                    </PanelBody>
                                </Panel>

                                {errorFor(errors, 'submission') && (
                                    <p className="text-sm text-destructive">
                                        {errorFor(errors, 'submission')}
                                    </p>
                                )}
                                {period.open && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                            name="submit"
                                            value="1"
                                        >
                                            Save and submit for review
                                        </Button>
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            name="submit"
                                            value="0"
                                        >
                                            Save as draft
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}
                    </Form>
                )}

                {submission && (
                    <Panel>
                        <PanelHeader
                            title="Evidence"
                            description="Registers, photos, reports. Fixed once the result is submitted."
                        />
                        {submission.evidence.length > 0 && (
                            <ul className="divide-y text-sm">
                                {submission.evidence.map((file) => (
                                    <li
                                        key={file.id}
                                        className="flex flex-wrap items-center gap-2 px-3 py-2"
                                    >
                                        <FileText
                                            className="size-4 text-muted-foreground"
                                            aria-hidden
                                        />
                                        <a
                                            href={download.url(file.id)}
                                            className="hover:underline"
                                        >
                                            {file.name}
                                        </a>
                                        <span className="text-xs text-muted-foreground">
                                            {formatBytes(file.size)}
                                        </span>
                                        {file.shareWithDonors && (
                                            <StatusBadge tone="info">
                                                Donors may see it
                                            </StatusBadge>
                                        )}
                                        {editable && (
                                            <Form
                                                {...removeFile.form(file.id)}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                                className="ml-auto"
                                            >
                                                <button
                                                    type="submit"
                                                    className="text-xs text-muted-foreground hover:text-destructive"
                                                >
                                                    Remove
                                                </button>
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        {editable && (
                            <Form
                                {...attach.form(submission.id)}
                                options={{ preserveScroll: true }}
                                resetOnSuccess
                                className="flex flex-wrap items-center gap-3 border-t p-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Input
                                            type="file"
                                            name="file"
                                            aria-label="Evidence file"
                                            className="w-auto"
                                        />
                                        <CheckboxField
                                            name="share_with_donors"
                                            label="Donors may see it"
                                            prefix="evidence-"
                                        />
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                        >
                                            Attach
                                        </Button>
                                        {errors.file && (
                                            <p className="w-full text-sm text-destructive">
                                                {errors.file}
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                        )}
                        {submission.evidence.length === 0 && !editable && (
                            <p className="p-3 text-sm text-muted-foreground">
                                None attached.
                            </p>
                        )}
                    </Panel>
                )}
                {submission === null && (
                    <p className="text-sm text-muted-foreground">
                        Save a draft first to attach evidence.
                    </p>
                )}

                {submission?.status === 'draft' && (
                    <Form
                        {...discard.form(submission.id)}
                        className="flex items-center gap-2"
                    >
                        {({ errors }) => (
                            <>
                                <Button type="submit" variant="ghost">
                                    Discard this draft
                                </Button>
                                {errors.submission && (
                                    <p className="text-sm text-destructive">
                                        {errors.submission}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-0.5">{children}</div>
        </div>
    );
}

function FigureInputs({
    version,
    figure,
    name,
    errors,
    errorKey,
    label,
    compact = false,
}: {
    version: Props['version'];
    figure: Figure | null;
    name: (part: 'value' | 'numerator' | 'denominator') => string;
    errors: Record<string, string | undefined>;
    errorKey: (part: string) => string;
    label: string;
    compact?: boolean;
}) {
    const initial = (value: string | null | undefined) =>
        value ? trimZeros(value) : '';
    const id = name('value').replace(/[^a-z0-9]/gi, '-');

    if (isFraction(version.measure)) {
        return (
            <>
                <Field
                    id={`${id}-n`}
                    label={
                        compact
                            ? `${label}: ${version.numeratorLabel ?? 'numerator'}`
                            : (version.numeratorLabel ?? 'Numerator')
                    }
                    error={errors[errorKey('numerator')]}
                >
                    <Input
                        id={`${id}-n`}
                        name={name('numerator')}
                        inputMode="decimal"
                        defaultValue={initial(figure?.numerator)}
                    />
                </Field>
                <Field
                    id={`${id}-d`}
                    label={
                        compact
                            ? `${label}: ${version.denominatorLabel ?? 'denominator'}`
                            : (version.denominatorLabel ?? 'Denominator')
                    }
                    error={errors[errorKey('denominator')]}
                >
                    <Input
                        id={`${id}-d`}
                        name={name('denominator')}
                        inputMode="decimal"
                        defaultValue={initial(figure?.denominator)}
                    />
                </Field>
            </>
        );
    }

    if (version.measure === 'yes_no') {
        return (
            <Field id={id} label={label} error={errors[errorKey('value')]}>
                <select
                    id={id}
                    name={name('value')}
                    defaultValue={initial(figure?.value)}
                    className={selectClass}
                >
                    <option value="">Not answered</option>
                    <option value="1">Yes</option>
                    <option value="0">No</option>
                </select>
            </Field>
        );
    }

    return (
        <Field
            id={id}
            label={
                version.unitLabel && !compact
                    ? `${label} (${version.unitLabel})`
                    : label
            }
            error={errors[errorKey('value')]}
        >
            <Input
                id={id}
                name={name('value')}
                inputMode="decimal"
                defaultValue={initial(figure?.value)}
            />
        </Field>
    );
}
