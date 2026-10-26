import { Form, Head } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
import { Field, invalid, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import { formatDate } from '@/lib/status';
import {
    destroy as removeAssessment,
    store as addAssessment,
} from '@/routes/settings/assessments';
import { update as changeCategory } from '@/routes/settings/category';
import { current, update as saveTerm } from '@/routes/settings/terms';
import { store as addYear } from '@/routes/settings/years';
import type { GradeBand } from '@/types';

type Assessment = { id: string; name: string; weight: number; marked: boolean };

type Term = {
    id: string;
    name: string;
    startsOn: string;
    endsOn: string;
    current: boolean;
    assessments: Assessment[];
};

type Year = {
    id: string;
    name: string;
    startsOn: string;
    endsOn: string;
    terms: Term[];
};

type Props = {
    category: string;
    categories: { value: string; label: string; description: string }[];
    canChangeCategory: boolean;
    grading: GradeBand[];
    years: Year[];
};

function TermPanel({ term }: { term: Term }) {
    const school = useSchool();
    const weights = term.assessments.reduce((sum, a) => sum + a.weight, 0);

    return (
        <div className="grid gap-3 border-t px-3 py-3 first:border-t-0">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                    <h3 className="font-medium">{term.name}</h3>
                    {term.current && (
                        <StatusBadge tone="success">Current</StatusBadge>
                    )}
                </div>
                {!term.current && (
                    <Form {...current.form(term.id)}>
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                size="sm"
                                disabled={processing}
                            >
                                Make current
                            </Button>
                        )}
                    </Form>
                )}
            </div>

            <Form
                {...saveTerm.form(term.id)}
                className="flex flex-wrap items-end gap-2"
                options={{ preserveScroll: true }}
            >
                {({ errors, processing }) => (
                    <>
                        <Field
                            id={`starts-${term.id}`}
                            label="Starts"
                            error={errors.starts_on}
                        >
                            <Input
                                id={`starts-${term.id}`}
                                name="starts_on"
                                type="date"
                                defaultValue={term.startsOn}
                            />
                        </Field>
                        <Field
                            id={`ends-${term.id}`}
                            label="Ends"
                            error={errors.ends_on}
                        >
                            <Input
                                id={`ends-${term.id}`}
                                name="ends_on"
                                type="date"
                                defaultValue={term.endsOn}
                            />
                        </Field>
                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            disabled={processing}
                        >
                            Save dates
                        </Button>
                    </>
                )}
            </Form>

            <div>
                <p className="mb-1 text-xs font-medium text-muted-foreground">
                    Assessments (weights {weights} of 100)
                </p>
                <ul className="grid gap-1 text-sm">
                    {term.assessments.map((assessment) => (
                        <li
                            key={assessment.id}
                            className="flex items-center justify-between gap-2"
                        >
                            <span>
                                {assessment.name}{' '}
                                <span className="text-muted-foreground tabular-nums">
                                    {assessment.weight}%
                                </span>
                            </span>
                            {!assessment.marked && (
                                <Form
                                    {...removeAssessment.form(assessment.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    <Button
                                        type="submit"
                                        variant="ghost"
                                        size="sm"
                                        aria-label={`Remove ${assessment.name}`}
                                    >
                                        <Trash2
                                            className="size-4"
                                            aria-hidden
                                        />
                                    </Button>
                                </Form>
                            )}
                        </li>
                    ))}
                </ul>
                {weights < 100 && (
                    <Form
                        {...addAssessment.form(term.id)}
                        className="mt-2 flex flex-wrap items-end gap-2"
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                    >
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id={`assessment-${term.id}`}
                                    label="Add an assessment"
                                    error={errors.name}
                                >
                                    <Input
                                        id={`assessment-${term.id}`}
                                        name="name"
                                        placeholder={
                                            school.value === 'primary'
                                                ? 'e.g. Test 1'
                                                : 'e.g. Practical'
                                        }
                                        required
                                    />
                                </Field>
                                <Field
                                    id={`weight-${term.id}`}
                                    label="Weight %"
                                    error={errors.weight}
                                >
                                    <Input
                                        id={`weight-${term.id}`}
                                        name="weight"
                                        type="number"
                                        min={1}
                                        max={100 - weights}
                                        defaultValue={100 - weights}
                                        className="w-24"
                                        required
                                    />
                                </Field>
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    disabled={processing}
                                >
                                    Add
                                </Button>
                            </>
                        )}
                    </Form>
                )}
            </div>
        </div>
    );
}

/**
 * The school calendar and how it assesses: academic years, their terms or
 * semesters, the current one, their assessments and the grading scale.
 */
export default function Settings({
    category,
    categories,
    canChangeCategory,
    grading,
    years,
}: Props) {
    const school = useSchool();
    const periods = school.value === 'primary' ? 'terms' : 'semesters';

    return (
        <>
            <Head title="Calendar & settings" />
            <PageHeader
                title="Calendar & settings"
                description={`${school.label}: academic years, their ${periods} and assessments.`}
            />

            <div className="grid gap-4 lg:grid-cols-[1fr_22rem]">
                <div className="grid content-start gap-4">
                    {years.map((year) => (
                        <Panel key={year.id}>
                            <PanelHeader
                                title={`Academic year ${year.name}`}
                                description={`${formatDate(year.startsOn)} to ${formatDate(year.endsOn)}`}
                            />
                            {year.terms.map((term) => (
                                <TermPanel key={term.id} term={term} />
                            ))}
                        </Panel>
                    ))}
                </div>

                <div className="grid content-start gap-4">
                    <Panel>
                        <PanelHeader
                            title="Add an academic year"
                            description={`Split evenly into ${periods}, each with the usual assessments.`}
                        />
                        <PanelBody>
                            <Form
                                {...addYear.form()}
                                className="grid gap-3"
                                resetOnSuccess
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="year-name"
                                            label="Name"
                                            error={errors.name}
                                        >
                                            <Input
                                                id="year-name"
                                                name="name"
                                                placeholder="2027"
                                                required
                                                {...invalid(
                                                    'year-name',
                                                    errors.name,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="year-starts"
                                            label="Starts"
                                            error={errors.starts_on}
                                        >
                                            <Input
                                                id="year-starts"
                                                name="starts_on"
                                                type="date"
                                                required
                                            />
                                        </Field>
                                        <Field
                                            id="year-ends"
                                            label="Ends"
                                            error={errors.ends_on}
                                        >
                                            <Input
                                                id="year-ends"
                                                name="ends_on"
                                                type="date"
                                                required
                                            />
                                        </Field>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Add year
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Grading"
                            description="A mark out of 100 at or above the minimum earns the grade."
                        />
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Grade</TableHead>
                                    <TableHead className="text-right">
                                        From
                                    </TableHead>
                                    {school.usesCredits && (
                                        <TableHead className="text-right">
                                            Points
                                        </TableHead>
                                    )}
                                    <TableHead>Remark</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {grading.map((band) => (
                                    <TableRow key={band.grade}>
                                        <TableCell className="font-medium">
                                            {band.grade}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {band.min}
                                        </TableCell>
                                        {school.usesCredits && (
                                            <TableCell className="text-right tabular-nums">
                                                {band.points?.toFixed(1)}
                                            </TableCell>
                                        )}
                                        <TableCell className="text-muted-foreground">
                                            {band.remark}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Kind of school"
                            description={
                                canChangeCategory
                                    ? 'Can change until the first student is enrolled.'
                                    : 'Fixed now that the school has students.'
                            }
                        />
                        <PanelBody>
                            {canChangeCategory ? (
                                <Form
                                    {...changeCategory.form()}
                                    className="flex items-end gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Field
                                                id="category"
                                                label="Category"
                                                error={errors.category}
                                                className="flex-1"
                                            >
                                                <Select
                                                    id="category"
                                                    name="category"
                                                    defaultValue={category}
                                                    options={categories}
                                                />
                                            </Field>
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Change
                                            </Button>
                                        </>
                                    )}
                                </Form>
                            ) : (
                                <p className="text-sm">{school.label}</p>
                            )}
                        </PanelBody>
                    </Panel>
                </div>
            </div>
        </>
    );
}
