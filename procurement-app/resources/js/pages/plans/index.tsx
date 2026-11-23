import { Head, Link, useForm } from '@inertiajs/react';
import { CalendarRange } from 'lucide-react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@miles/ui/components/ui/table';
import { SelectField, Status, TextField } from '@/components/fields';
import type { Option } from '@/lib/procurement';
import { money } from '@/lib/procurement';
import { show, store } from '@/routes/plans';

type Props = {
    plans: {
        id: string;
        number: string;
        title: string;
        fiscalYear: string;
        department: string | null;
        status: string;
        revision: number;
        items: number;
        total: string;
    }[];
    currency: string;
    departments: Option[];
    canManage: boolean;
};

export default function Plans({
    plans,
    currency,
    departments,
    canManage,
}: Props) {
    const form = useForm({
        title: '',
        fiscal_year: String(new Date().getFullYear() + 1),
        department_id: '',
    });

    return (
        <>
            <Head title="Procurement plans" />
            <PageHeader title="Procurement plans" />
            <div className="grid gap-6">
                <Panel>
                    {plans.length === 0 ? (
                        <EmptyState icon={CalendarRange} title="No plans yet">
                            A plan lists the year's expected purchases. Once
                            approved, requisitions can be tied to its items.
                        </EmptyState>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Number</TableHead>
                                    <TableHead>Title</TableHead>
                                    <TableHead>Year</TableHead>
                                    <TableHead>Department</TableHead>
                                    <TableHead className="text-right">
                                        Items
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Estimate
                                    </TableHead>
                                    <TableHead>Status</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {plans.map((plan) => (
                                    <TableRow key={plan.id}>
                                        <TableCell>{plan.number}</TableCell>
                                        <TableCell>
                                            <Link
                                                href={show(plan.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {plan.title}
                                            </Link>
                                            {plan.revision > 1 && (
                                                <span className="ml-2 text-xs text-muted-foreground">
                                                    rev. {plan.revision}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell>{plan.fiscalYear}</TableCell>
                                        <TableCell>
                                            {plan.department ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            {plan.items}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {money(plan.total, currency)}
                                        </TableCell>
                                        <TableCell>
                                            <Status value={plan.status} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </Panel>

                {canManage && (
                    <Panel>
                        <PanelHeader title="New plan" />
                        <PanelBody>
                            <form
                                className="grid gap-3 sm:grid-cols-4 sm:items-end"
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    form.post(store().url);
                                }}
                            >
                                <TextField
                                    label="Title"
                                    value={form.data.title}
                                    onChange={(v) => form.setData('title', v)}
                                    error={form.errors.title}
                                    className="sm:col-span-2"
                                />
                                <TextField
                                    label="Year"
                                    value={form.data.fiscal_year}
                                    onChange={(v) =>
                                        form.setData('fiscal_year', v)
                                    }
                                    error={form.errors.fiscal_year}
                                />
                                <SelectField
                                    label="Department"
                                    value={form.data.department_id}
                                    onChange={(v) =>
                                        form.setData('department_id', v)
                                    }
                                    options={departments}
                                    empty="Whole organization"
                                    error={form.errors.department_id}
                                />
                                <div>
                                    <Button
                                        type="submit"
                                        disabled={form.processing}
                                    >
                                        Create plan
                                    </Button>
                                </div>
                            </form>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}
