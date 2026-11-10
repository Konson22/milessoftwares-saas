import { Form, Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { CurrencySelect } from '@/components/currency-select';
import { Field, Select } from '@/components/field';
import { useMoney } from '@/lib/use-money';
import { store } from '@/routes/expenses';
import { store as storeCategory } from '@/routes/expenses/categories';
import {
    end,
    generate,
    pause,
    resume,
    store as storeSchedule,
} from '@/routes/expenses/schedules';

type Expense = {
    id: string;
    description: string;
    amountMinor: number;
    paidFrom: string;
    incurredOn: string;
};

export default function Expenses({
    expenses,
    categories,
    schedules = [],
    canManage,
    canCategorise,
    idempotencyKey,
    openShift,
}: {
    expenses: Expense[];
    categories: { id: string; name: string; code: string }[];
    schedules?: {
        id: string;
        description: string;
        next_due_on: string;
        active: boolean;
        status?: string;
    }[];
    methods: { id: string; name: string; kind: string }[];
    openShift: boolean;
    canManage: boolean;
    canCategorise: boolean;
    idempotencyKey: string;
}) {
    const money = useMoney();
    const { errors, currency } = usePage().props;

    return (
        <>
            <Head title="Expenses" />
            <PageHeader
                title="Expenses"
                description="An expense paid from the drawer tells Finance the cash left. Bank and petty stay local."
            />
            <Panel className="mb-4">
                <PanelBody>
                    <ul className="text-sm">
                        {expenses.map((expense) => (
                            <li key={expense.id}>
                                {expense.incurredOn} {expense.description}{' '}
                                {money(expense.amountMinor)} from{' '}
                                {expense.paidFrom}
                            </li>
                        ))}
                    </ul>
                </PanelBody>
            </Panel>
            {canManage && (
                <Panel>
                    <PanelHeader
                        title="Record"
                        description={
                            openShift
                                ? 'The drawer is open.'
                                : 'Open a shift before paying from the drawer.'
                        }
                    />
                    <PanelBody>
                        <Form
                            {...store.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <input
                                type="hidden"
                                name="idempotency_key"
                                value={idempotencyKey}
                            />
                            <Field
                                id="category_id"
                                label="Category"
                                error={errors.category_id}
                                hint={
                                    categories.length === 0
                                        ? 'A company administrator adds categories first.'
                                        : undefined
                                }
                            >
                                <Select
                                    id="category_id"
                                    name="category_id"
                                    placeholder="Choose"
                                    options={categories.map((category) => ({
                                        value: category.id,
                                        label: category.name,
                                    }))}
                                />
                            </Field>
                            <Field
                                id="currency"
                                label="Currency"
                                error={errors.currency}
                            >
                                <CurrencySelect
                                    id="currency"
                                    defaultValue={currency}
                                />
                            </Field>
                            <Field
                                id="description"
                                label="Description"
                                error={errors.description}
                            >
                                <Input
                                    id="description"
                                    name="description"
                                    required
                                />
                            </Field>
                            <Field
                                id="amount"
                                label="Amount"
                                error={errors.amount}
                            >
                                <Input
                                    id="amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="paid_from"
                                label="Paid from"
                                error={errors.paid_from}
                            >
                                <Select
                                    id="paid_from"
                                    name="paid_from"
                                    defaultValue="cash"
                                    options={[
                                        { value: 'cash', label: 'Drawer' },
                                        { value: 'bank', label: 'Bank' },
                                        { value: 'petty', label: 'Petty cash' },
                                    ]}
                                />
                            </Field>
                            <Field
                                id="payee"
                                label="Payee"
                                error={errors.payee}
                            >
                                <Input id="payee" name="payee" />
                            </Field>
                            <Button type="submit">Save expense</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {canManage && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="Recurring plan"
                        description="A plan is not an expense until a due date is generated."
                    />
                    <PanelBody>
                        {schedules.length > 0 && (
                            <ul className="mb-3 grid gap-2 text-sm">
                                {schedules.map((schedule) => (
                                    <li
                                        key={schedule.id}
                                        className="flex flex-wrap items-center gap-2"
                                    >
                                        <span>
                                            {schedule.description} · next{' '}
                                            {String(schedule.next_due_on).slice(
                                                0,
                                                10,
                                            )}
                                            {schedule.status === 'paused'
                                                ? ' · paused'
                                                : ''}
                                            {schedule.status === 'ended'
                                                ? ' · ended'
                                                : ''}
                                        </span>
                                        {schedule.status !== 'ended' &&
                                            schedule.active && (
                                                <Form
                                                    {...pause.form(schedule.id)}
                                                >
                                                    <Button
                                                        type="submit"
                                                        size="sm"
                                                        variant="outline"
                                                    >
                                                        Pause
                                                    </Button>
                                                </Form>
                                            )}
                                        {schedule.status === 'paused' && (
                                            <Form {...resume.form(schedule.id)}>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    Resume
                                                </Button>
                                            </Form>
                                        )}
                                        {schedule.status !== 'ended' && (
                                            <Form {...end.form(schedule.id)}>
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="outline"
                                                >
                                                    End
                                                </Button>
                                            </Form>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                        <Form
                            {...storeSchedule.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="schedule_category_id"
                                label="Category"
                                error={errors.category_id}
                            >
                                <Select
                                    id="schedule_category_id"
                                    name="category_id"
                                    options={categories.map((category) => ({
                                        value: category.id,
                                        label: category.name,
                                    }))}
                                />
                            </Field>
                            <Field id="frequency" label="Frequency">
                                <Select
                                    id="frequency"
                                    name="frequency"
                                    defaultValue="monthly"
                                    options={[
                                        { value: 'daily', label: 'Daily' },
                                        { value: 'weekly', label: 'Weekly' },
                                        { value: 'monthly', label: 'Monthly' },
                                        {
                                            value: 'quarterly',
                                            label: 'Quarterly',
                                        },
                                        { value: 'yearly', label: 'Yearly' },
                                    ]}
                                />
                            </Field>
                            <Field
                                id="starts_on"
                                label="Starts"
                                error={errors.starts_on}
                            >
                                <Input
                                    id="starts_on"
                                    name="starts_on"
                                    type="date"
                                    required
                                />
                            </Field>
                            <Field
                                id="ends_on"
                                label="Ends"
                                error={errors.ends_on}
                            >
                                <Input
                                    id="ends_on"
                                    name="ends_on"
                                    type="date"
                                />
                            </Field>
                            <Field
                                id="schedule_amount"
                                label="Amount"
                                error={errors.amount}
                            >
                                <Input
                                    id="schedule_amount"
                                    name="amount"
                                    inputMode="decimal"
                                    required
                                />
                            </Field>
                            <Field
                                id="schedule_description"
                                label="Description"
                                error={errors.description}
                            >
                                <Input
                                    id="schedule_description"
                                    name="description"
                                    required
                                />
                            </Field>
                            <Button type="submit">Save plan</Button>
                        </Form>
                        <Form {...generate.form()} className="mt-3">
                            <Button type="submit" variant="outline">
                                Generate due expenses
                            </Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
            {canCategorise && (
                <Panel className="mt-4">
                    <PanelHeader
                        title="Categories"
                        description="Company administrators add categories. Stations record against them."
                    />
                    <PanelBody>
                        <Form
                            {...storeCategory.form()}
                            className="grid gap-3 md:grid-cols-2"
                        >
                            <Field
                                id="category_name"
                                label="Name"
                                error={errors.name}
                            >
                                <Input
                                    id="category_name"
                                    name="name"
                                    required
                                />
                            </Field>
                            <Field
                                id="category_code"
                                label="Code"
                                error={errors.code}
                            >
                                <Input
                                    id="category_code"
                                    name="code"
                                    required
                                />
                            </Field>
                            <Field id="default_type" label="Default type">
                                <Select
                                    id="default_type"
                                    name="default_type"
                                    defaultValue="once"
                                    options={[
                                        { value: 'once', label: 'One-time' },
                                        {
                                            value: 'recurring',
                                            label: 'Recurring',
                                        },
                                    ]}
                                />
                            </Field>
                            <Field
                                id="category_description"
                                label="Description"
                            >
                                <Input
                                    id="category_description"
                                    name="description"
                                />
                            </Field>
                            <Button type="submit">Add category</Button>
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
