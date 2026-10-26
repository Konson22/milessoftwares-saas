import { Form, Head } from '@inertiajs/react';
import { Trash2, Wallet } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import {
    Panel,
    PanelBody,
    PanelHeader,
    PanelToolbar,
} from '@miles/ui/components/panel';
import { StatCard } from '@miles/ui/components/stat-card';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { Field, invalid, Select } from '@/components/field';
import { useSchool } from '@/lib/school';
import { useMoney } from '@/lib/use-money';
import { bill, index } from '@/routes/fees';
import { destroy as removeItem, store as addItem } from '@/routes/fees/items';
import type { Option } from '@/types';

type Props = {
    filters: { term: string | null };
    terms: Option[];
    items: {
        id: string;
        name: string;
        className: string | null;
        amountMinor: number;
    }[];
    classes: Option[];
    summary: {
        invoices: number;
        billedMinor: number;
        paidMinor: number;
        notBilled: number;
    } | null;
    today: string;
    canManage: boolean;
};

/**
 * A term's fees: what each class pays, and billing the students who are
 * not billed yet.
 */
export default function Fees({
    filters,
    terms,
    items,
    classes,
    summary,
    today,
    canManage,
}: Props) {
    const school = useSchool();
    const money = useMoney();
    const period = school.period.toLowerCase();
    const everyone = items
        .filter((item) => item.className === null)
        .reduce((sum, item) => sum + item.amountMinor, 0);

    return (
        <>
            <Head title="Fees" />
            <PageHeader
                title="Fees"
                description={`What students pay each ${period}. Billing invoices every enrolled student once.`}
            />

            <Panel>
                <PanelToolbar>
                    <Form {...index.form()} className="flex gap-2">
                        <Select
                            name="term"
                            aria-label={school.period}
                            defaultValue={filters.term ?? ''}
                            options={terms}
                            className="w-auto"
                        />
                        <Button type="submit" variant="outline">
                            Show
                        </Button>
                    </Form>
                </PanelToolbar>
            </Panel>

            {summary && (
                <div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Invoices" value={summary.invoices} />
                    <StatCard
                        label="Billed"
                        value={money(summary.billedMinor)}
                        accent="violet"
                    />
                    <StatCard
                        label="Collected"
                        value={money(summary.paidMinor)}
                        accent="emerald"
                    />
                    <StatCard
                        label="Not billed yet"
                        value={summary.notBilled}
                        accent={summary.notBilled > 0 ? 'amber' : 'slate'}
                    />
                </div>
            )}

            <div className="mt-4 grid gap-4 lg:grid-cols-[1fr_22rem]">
                <Panel>
                    <PanelHeader
                        title={`Fees of the ${period}`}
                        description={
                            everyone > 0
                                ? `${money(everyone)} for every class, plus each class's own.`
                                : undefined
                        }
                    />
                    {items.length === 0 ? (
                        <EmptyState
                            icon={Wallet}
                            title={`No fees set for this ${period}`}
                        />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fee</TableHead>
                                    <TableHead>Paid by</TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                    {canManage && <TableHead />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell className="font-medium">
                                            {item.name}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {item.className ?? 'Every class'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(item.amountMinor)}
                                        </TableCell>
                                        {canManage && (
                                            <TableCell className="text-right">
                                                <Form
                                                    {...removeItem.form(
                                                        item.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <Button
                                                        type="submit"
                                                        variant="ghost"
                                                        size="sm"
                                                        aria-label={`Remove ${item.name}`}
                                                    >
                                                        <Trash2
                                                            className="size-4"
                                                            aria-hidden
                                                        />
                                                    </Button>
                                                </Form>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                            <TableFooter>
                                <TableRow>
                                    <TableCell
                                        colSpan={2}
                                        className="text-muted-foreground"
                                    >
                                        Every class pays
                                    </TableCell>
                                    <TableCell className="text-right font-semibold tabular-nums">
                                        {money(everyone)}
                                    </TableCell>
                                    {canManage && <TableCell />}
                                </TableRow>
                            </TableFooter>
                        </Table>
                    )}
                </Panel>

                {canManage && filters.term && (
                    <div className="grid content-start gap-4">
                        <Panel>
                            <PanelHeader title="Add a fee" />
                            <PanelBody>
                                <Form
                                    {...addItem.form()}
                                    className="grid gap-3"
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess={['name', 'amount']}
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="term_id"
                                                value={filters.term ?? ''}
                                            />
                                            <Field
                                                id="fee-name"
                                                label="Fee"
                                                error={errors.name}
                                            >
                                                <Input
                                                    id="fee-name"
                                                    name="name"
                                                    placeholder="Tuition"
                                                    required
                                                    {...invalid(
                                                        'fee-name',
                                                        errors.name,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="fee-amount"
                                                label="Amount"
                                                error={errors.amount}
                                            >
                                                <Input
                                                    id="fee-amount"
                                                    name="amount"
                                                    inputMode="decimal"
                                                    required
                                                    {...invalid(
                                                        'fee-amount',
                                                        errors.amount,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="fee-class"
                                                label="Paid by"
                                                error={errors.school_class_id}
                                            >
                                                <Select
                                                    id="fee-class"
                                                    name="school_class_id"
                                                    placeholder="Every class"
                                                    options={classes}
                                                />
                                            </Field>
                                            <div>
                                                <Button
                                                    type="submit"
                                                    variant="outline"
                                                    disabled={processing}
                                                >
                                                    Add fee
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Bill students"
                                description={`Invoices each enrolled student not yet billed for this ${period}. Finance is told of each invoice.`}
                            />
                            <PanelBody>
                                <Form {...bill.form()} className="grid gap-3">
                                    {({ errors, processing }) => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="term_id"
                                                value={filters.term ?? ''}
                                            />
                                            <Field
                                                id="bill-class"
                                                label="Students of"
                                                error={
                                                    errors.school_class_id ??
                                                    errors.term_id
                                                }
                                            >
                                                <Select
                                                    id="bill-class"
                                                    name="school_class_id"
                                                    placeholder="Every class"
                                                    options={classes}
                                                />
                                            </Field>
                                            <Field
                                                id="due_on"
                                                label="Due"
                                                error={errors.due_on}
                                            >
                                                <Input
                                                    id="due_on"
                                                    name="due_on"
                                                    type="date"
                                                    min={today}
                                                />
                                            </Field>
                                            <div>
                                                <Button
                                                    type="submit"
                                                    disabled={
                                                        processing ||
                                                        items.length === 0
                                                    }
                                                >
                                                    Bill students
                                                </Button>
                                            </div>
                                        </>
                                    )}
                                </Form>
                            </PanelBody>
                        </Panel>
                    </div>
                )}
            </div>
        </>
    );
}
