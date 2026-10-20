import { Form, Head, Link } from '@inertiajs/react';
import { Plus, Target } from 'lucide-react';
import { useState } from 'react';
import { Amount } from '@/components/amount';
import { Field, Select } from '@/components/field';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
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
import { useCan } from '@/lib/access';
import { show, store } from '@/routes/budgets';
import { index as periods } from '@/routes/periods';
import { budgetVsActual } from '@/routes/reports';

type Option = { id: string; label: string };

type Props = {
    budgets: {
        id: string;
        name: string;
        year: string;
        costCentre: string | null;
        revenueMinor: number;
        expensesMinor: number;
        updatedAt: string;
    }[];
    years: Option[];
    costCentres: Option[];
};

/**
 * Budgets: planned income and spending per account and month of a year.
 */
export default function Budgets({ budgets, years, costCentres }: Props) {
    const can = useCan();
    const manage = can('finance.budgets.manage');
    const [adding, setAdding] = useState(false);
    const [copy, setCopy] = useState('none');

    return (
        <>
            <Head title="Budgets" />
            <PageHeader
                title="Budgets"
                description="Planned income and spending per account and month, compared with what happened in Budget vs actual."
                actions={
                    manage &&
                    years.length > 0 && (
                        <Button onClick={() => setAdding((value) => !value)}>
                            <Plus className="size-4" aria-hidden />
                            New budget
                        </Button>
                    )
                }
            />

            {adding && (
                <Panel className="mb-3">
                    <PanelHeader title="New budget" />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 sm:grid-cols-4"
                        >
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="fiscal_year_id"
                                        label="Year"
                                        error={errors.fiscal_year_id}
                                    >
                                        <Select
                                            id="fiscal_year_id"
                                            name="fiscal_year_id"
                                            required
                                        >
                                            {years.map((year) => (
                                                <option
                                                    key={year.id}
                                                    value={year.id}
                                                >
                                                    {year.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <Field
                                        id="name"
                                        label="Name"
                                        error={errors.name}
                                    >
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={100}
                                            placeholder="Main"
                                        />
                                    </Field>
                                    <Field
                                        id="cost_centre_id"
                                        label="For"
                                        error={errors.cost_centre_id}
                                    >
                                        <Select
                                            id="cost_centre_id"
                                            name="cost_centre_id"
                                            defaultValue=""
                                        >
                                            <option value="">
                                                The whole organization
                                            </option>
                                            {costCentres.map((centre) => (
                                                <option
                                                    key={centre.id}
                                                    value={centre.id}
                                                >
                                                    {centre.label}
                                                </option>
                                            ))}
                                        </Select>
                                    </Field>
                                    <div />
                                    <Field
                                        id="copy"
                                        label="Start from"
                                        error={errors.copy}
                                    >
                                        <Select
                                            id="copy"
                                            name="copy"
                                            value={copy}
                                            onChange={(event) =>
                                                setCopy(event.target.value)
                                            }
                                        >
                                            <option value="none">
                                                Nothing
                                            </option>
                                            <option value="actuals">
                                                Last year&apos;s actuals
                                            </option>
                                        </Select>
                                    </Field>
                                    {copy === 'actuals' && (
                                        <Field
                                            id="uplift"
                                            label="Changed by (%)"
                                            error={errors.uplift}
                                            hint="For example 5, or -2.5"
                                        >
                                            <Input
                                                id="uplift"
                                                name="uplift"
                                                inputMode="decimal"
                                                defaultValue="0"
                                            />
                                        </Field>
                                    )}
                                    <div className="sm:col-span-4">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            Add budget
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                {budgets.length === 0 ? (
                    <EmptyState icon={Target} title="No budgets yet">
                        {years.length === 0 ? (
                            <>
                                Add a financial year first, under{' '}
                                <Link
                                    href={periods()}
                                    className="text-primary hover:underline"
                                >
                                    Periods &amp; close
                                </Link>
                                .
                            </>
                        ) : (
                            'Plan a year’s income and spending, or start from last year’s actuals.'
                        )}
                    </EmptyState>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Year</TableHead>
                                <TableHead>Budget</TableHead>
                                <TableHead>For</TableHead>
                                <TableHead className="text-right">
                                    Revenue
                                </TableHead>
                                <TableHead className="text-right">
                                    Expenses
                                </TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {budgets.map((budget) => (
                                <TableRow key={budget.id}>
                                    <TableCell>{budget.year}</TableCell>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={show(budget.id)}
                                            className="text-primary hover:underline"
                                        >
                                            {budget.name}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {budget.costCentre ??
                                            'Whole organization'}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={budget.revenueMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Amount minor={budget.expensesMinor} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            asChild
                                        >
                                            <Link
                                                href={budgetVsActual({
                                                    query: {
                                                        budget: budget.id,
                                                    },
                                                })}
                                            >
                                                Budget vs actual
                                            </Link>
                                        </Button>
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
