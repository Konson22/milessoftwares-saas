import { Form, router } from '@inertiajs/react';
import { Receipt, Trash2 } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
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
import { cn } from '@miles/ui/lib/utils';
import { Field, invalid, Select } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { formatMoney } from '@/lib/money';
import { formatDate } from '@/lib/status';
import {
    destroy as removeLine,
    store as addLine,
} from '@/routes/projects/budget/lines';
import {
    destroy as removeExpense,
    store as addExpense,
} from '@/routes/projects/expenses';
import type { Option, ProjectHeader } from '@/types';

type Props = {
    project: ProjectHeader;
    budgetMinor: number;
    actualMinor: number;
    byCategory: {
        category: string;
        label: string;
        budgetMinor: number;
        actualMinor: number;
    }[];
    lines: {
        id: string;
        category: string;
        description: string | null;
        amountMinor: number;
    }[];
    expenses: {
        id: string;
        category: string;
        description: string;
        supplier: string | null;
        amountMinor: number;
        spentOn: string;
        recordedBy: string;
    }[];
    categories: Option[];
    expenseCategories: Option[];
    today: string;
};

/**
 * Budget against actual by category. Labour is time logged at each
 * person's cost; everything else is expenses.
 */
export default function Budget({
    project,
    budgetMinor,
    actualMinor,
    byCategory,
    lines,
    expenses,
    categories,
    expenseCategories,
    today,
}: Props) {
    const money = (minor: number) => formatMoney(minor, project.currency);
    const manage = project.can.manage;
    const remaining = budgetMinor - actualMinor;
    const remove = (url: string) =>
        router.delete(url, { preserveScroll: true });

    return (
        <ProjectFrame project={project} title="Budget">
            <div className="grid gap-3 sm:grid-cols-3">
                <StatCard label="Budget" value={money(budgetMinor)} />
                <StatCard
                    label="Spent"
                    value={money(actualMinor)}
                    hint={
                        budgetMinor > 0
                            ? `${Math.round((actualMinor / budgetMinor) * 100)}% of budget`
                            : undefined
                    }
                    accent="violet"
                />
                <StatCard
                    label={remaining < 0 ? 'Over budget' : 'Remaining'}
                    value={money(Math.abs(remaining))}
                    accent={remaining < 0 ? 'red' : 'emerald'}
                />
            </div>

            <Panel className="mt-4">
                <PanelHeader title="By category" />
                {byCategory.length === 0 ? (
                    <p className="px-3 py-3 text-sm text-muted-foreground">
                        No budget or spending yet.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {byCategory.map((row) => {
                            const share =
                                row.budgetMinor > 0
                                    ? (row.actualMinor / row.budgetMinor) * 100
                                    : row.actualMinor > 0
                                      ? 100
                                      : 0;

                            return (
                                <li
                                    key={row.category}
                                    className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[10rem_1fr_14rem] sm:items-center sm:gap-3"
                                >
                                    <span className="font-medium">
                                        {row.label}
                                    </span>
                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className={cn(
                                                'h-full rounded-full',
                                                row.actualMinor >
                                                    row.budgetMinor
                                                    ? 'bg-destructive'
                                                    : 'bg-primary',
                                            )}
                                            style={{
                                                width: `${Math.min(100, share)}%`,
                                            }}
                                        />
                                    </div>
                                    <span
                                        className={cn(
                                            'text-right tabular-nums',
                                            row.actualMinor > row.budgetMinor &&
                                                'text-destructive',
                                        )}
                                    >
                                        {money(row.actualMinor)}
                                        <span className="text-muted-foreground">
                                            {' / '}
                                            {money(row.budgetMinor)}
                                        </span>
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </Panel>

            <div className="mt-4 grid gap-4 lg:grid-cols-[2fr_3fr]">
                <Panel>
                    <PanelHeader
                        title="Budget lines"
                        description="With lines, the budget is their sum."
                    />
                    {lines.length > 0 && (
                        <ul className="divide-y">
                            {lines.map((line) => (
                                <li
                                    key={line.id}
                                    className="flex items-center gap-2 px-3 py-2 text-sm"
                                >
                                    <span className="flex-1">
                                        {line.category}
                                        {line.description && (
                                            <span className="text-muted-foreground">
                                                {' '}
                                                · {line.description}
                                            </span>
                                        )}
                                    </span>
                                    <span className="tabular-nums">
                                        {money(line.amountMinor)}
                                    </span>
                                    {manage && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            aria-label="Remove line"
                                            onClick={() =>
                                                remove(
                                                    removeLine.url({
                                                        project: project.id,
                                                        budgetLine: line.id,
                                                    }),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    {manage && (
                        <PanelBody
                            className={cn(lines.length > 0 && 'border-t')}
                        >
                            <Form
                                {...addLine.form(project.id)}
                                resetOnSuccess={['description', 'amount']}
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-2"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="line-category"
                                            label="Category"
                                            error={errors.category}
                                        >
                                            <Select
                                                id="line-category"
                                                name="category"
                                                options={categories}
                                            />
                                        </Field>
                                        <Field
                                            id="line-amount"
                                            label="Amount"
                                            error={errors.amount}
                                        >
                                            <Input
                                                id="line-amount"
                                                name="amount"
                                                required
                                                inputMode="decimal"
                                                {...invalid(
                                                    'line-amount',
                                                    errors.amount,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="line-description"
                                            label="Description"
                                            error={errors.description}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="line-description"
                                                name="description"
                                            />
                                        </Field>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Add line
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>

                <Panel>
                    <PanelHeader title="Expenses" />
                    {expenses.length === 0 ? (
                        <EmptyState icon={Receipt} title="No expenses yet" />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead>What</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    {manage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {expenses.map((expense) => (
                                    <TableRow key={expense.id}>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {formatDate(expense.spentOn)}
                                        </TableCell>
                                        <TableCell>
                                            {expense.description}
                                            <p className="text-xs text-muted-foreground">
                                                {[
                                                    expense.category,
                                                    expense.supplier,
                                                    `by ${expense.recordedBy}`,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(expense.amountMinor)}
                                        </TableCell>
                                        {manage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    aria-label="Remove expense"
                                                    onClick={() =>
                                                        remove(
                                                            removeExpense.url({
                                                                project:
                                                                    project.id,
                                                                expense:
                                                                    expense.id,
                                                            }),
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    {manage && (
                        <PanelBody className="border-t">
                            <Form
                                {...addExpense.form(project.id)}
                                resetOnSuccess={[
                                    'description',
                                    'supplier',
                                    'amount',
                                ]}
                                options={{ preserveScroll: true }}
                                className="grid gap-3 sm:grid-cols-3"
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <Field
                                            id="expense-description"
                                            label="What"
                                            error={errors.description}
                                            className="sm:col-span-2"
                                        >
                                            <Input
                                                id="expense-description"
                                                name="description"
                                                required
                                                {...invalid(
                                                    'expense-description',
                                                    errors.description,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="expense-category"
                                            label="Category"
                                            error={errors.category}
                                        >
                                            <Select
                                                id="expense-category"
                                                name="category"
                                                options={expenseCategories}
                                            />
                                        </Field>
                                        <Field
                                            id="expense-supplier"
                                            label="Supplier"
                                            error={errors.supplier}
                                        >
                                            <Input
                                                id="expense-supplier"
                                                name="supplier"
                                            />
                                        </Field>
                                        <Field
                                            id="expense-amount"
                                            label="Amount"
                                            error={errors.amount}
                                        >
                                            <Input
                                                id="expense-amount"
                                                name="amount"
                                                required
                                                inputMode="decimal"
                                                {...invalid(
                                                    'expense-amount',
                                                    errors.amount,
                                                )}
                                            />
                                        </Field>
                                        <Field
                                            id="expense-date"
                                            label="Date"
                                            error={errors.spent_on}
                                        >
                                            <Input
                                                id="expense-date"
                                                name="spent_on"
                                                type="date"
                                                required
                                                max={today}
                                                defaultValue={today}
                                            />
                                        </Field>
                                        <div>
                                            <Button
                                                type="submit"
                                                disabled={processing}
                                            >
                                                Record expense
                                            </Button>
                                        </div>
                                    </>
                                )}
                            </Form>
                        </PanelBody>
                    )}
                </Panel>
            </div>
        </ProjectFrame>
    );
}
