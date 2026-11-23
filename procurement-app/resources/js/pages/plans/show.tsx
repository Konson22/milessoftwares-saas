import { Head, router, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
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
import {
    ActionButton,
    ApprovalPanel,
    DocumentsPanel,
} from '@/components/actions';
import { Detail, SelectField, Status, TextField } from '@/components/fields';
import type { Approval, Documents, Option } from '@/lib/procurement';
import { formatDate, METHODS, money, quantity } from '@/lib/procurement';
import { close, decide, revise, submit } from '@/routes/plans';
import { destroy as destroyItem, save as saveItem } from '@/routes/plans/items';

type Props = {
    plan: {
        id: string;
        number: string;
        title: string;
        fiscalYear: string;
        department: string | null;
        status: string;
        revision: number;
        notes: string | null;
        total: string;
        items: {
            id: string;
            description: string;
            category: string | null;
            method: string;
            quantity: string;
            unit: string | null;
            estimated: string;
            used: string;
            expectedOn: string | null;
            fundingReference: string | null;
            projectReference: string | null;
            responsible: string | null;
        }[];
    };
    currency: string;
    approval: Approval;
    documents: Documents;
    methods: Option[];
    members: Option[];
    canManage: boolean;
};

export default function ShowPlan({
    plan,
    currency,
    approval,
    documents,
    methods,
    members,
    canManage,
}: Props) {
    const editable = canManage && ['draft', 'returned'].includes(plan.status);

    return (
        <>
            <Head title={plan.title} />
            <PageHeader
                title={`${plan.number} · ${plan.title}`}
                badges={<Status value={plan.status} />}
                actions={
                    canManage && (
                        <>
                            {editable && (
                                <ActionButton
                                    url={submit(plan.id).url}
                                    label="Submit for approval"
                                />
                            )}
                            {plan.status === 'approved' && (
                                <>
                                    <ActionButton
                                        url={revise(plan.id).url}
                                        label="Revise"
                                        variant="outline"
                                        reason="Why is the plan revised?"
                                    />
                                    <ActionButton
                                        url={close(plan.id).url}
                                        label="Close plan"
                                        variant="outline"
                                        confirm="Close this plan? Its items can no longer be requested."
                                    />
                                </>
                            )}
                        </>
                    )
                }
            />
            <div className="grid gap-6">
                <Panel>
                    <PanelBody>
                        <dl className="grid gap-4 sm:grid-cols-4">
                            <Detail label="Year">{plan.fiscalYear}</Detail>
                            <Detail label="Department">
                                {plan.department ?? 'Whole organization'}
                            </Detail>
                            <Detail label="Estimate">
                                {money(plan.total, currency)}
                            </Detail>
                            <Detail label="Revision">{plan.revision}</Detail>
                        </dl>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="Planned purchases"
                        description="Requested is what approved requisitions have taken against each item."
                    />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead>Method</TableHead>
                                <TableHead>Expected</TableHead>
                                <TableHead className="text-right">
                                    Estimate
                                </TableHead>
                                <TableHead className="text-right">
                                    Requested
                                </TableHead>
                                {editable && <TableHead />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {plan.items.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="text-muted-foreground"
                                    >
                                        No planned purchases yet.
                                    </TableCell>
                                </TableRow>
                            )}
                            {plan.items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="whitespace-normal">
                                        <span className="font-medium">
                                            {item.description}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {quantity(item.quantity)}{' '}
                                            {item.unit}
                                            {item.category &&
                                                ` · ${item.category}`}
                                            {item.fundingReference &&
                                                ` · ${item.fundingReference}`}
                                            {item.responsible &&
                                                ` · ${item.responsible}`}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        {METHODS[item.method] ?? item.method}
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(item.expectedOn)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(item.estimated, currency)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(item.used, currency)}
                                    </TableCell>
                                    {editable && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                aria-label="Remove"
                                                onClick={() =>
                                                    router.delete(
                                                        destroyItem({
                                                            plan: plan.id,
                                                            item: item.id,
                                                        }).url,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    {editable && (
                        <PanelBody className="border-t">
                            <AddItem
                                planId={plan.id}
                                methods={methods}
                                members={members}
                                currency={currency}
                            />
                        </PanelBody>
                    )}
                </Panel>

                <ApprovalPanel
                    approval={approval}
                    decideUrl={decide(plan.id).url}
                />
                <DocumentsPanel documents={documents} />
            </div>
        </>
    );
}

function AddItem({
    planId,
    methods,
    members,
    currency,
}: {
    planId: string;
    methods: Option[];
    members: Option[];
    currency: string;
}) {
    const form = useForm({
        description: '',
        category: '',
        method: 'rfq',
        quantity: '1',
        unit: '',
        estimated_amount: '',
        expected_on: '',
        funding_reference: '',
        project_reference: '',
        responsible_user_id: '',
    });

    return (
        <form
            className="grid gap-3 sm:grid-cols-4 sm:items-end"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(saveItem({ plan: planId }).url, {
                    preserveScroll: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <TextField
                label="Planned purchase"
                value={form.data.description}
                onChange={(v) => form.setData('description', v)}
                error={form.errors.description}
                className="sm:col-span-2"
            />
            <SelectField
                label="Method"
                value={form.data.method}
                onChange={(v) => form.setData('method', v)}
                options={methods}
                error={form.errors.method}
            />
            <TextField
                label={`Estimate (${currency})`}
                inputMode="decimal"
                value={form.data.estimated_amount}
                onChange={(v) => form.setData('estimated_amount', v)}
                error={form.errors.estimated_amount}
            />
            <TextField
                label="Quantity"
                inputMode="decimal"
                value={form.data.quantity}
                onChange={(v) => form.setData('quantity', v)}
                error={form.errors.quantity}
            />
            <TextField
                label="Expected"
                type="date"
                value={form.data.expected_on}
                onChange={(v) => form.setData('expected_on', v)}
                error={form.errors.expected_on}
            />
            <TextField
                label="Funding source"
                value={form.data.funding_reference}
                onChange={(v) => form.setData('funding_reference', v)}
                error={form.errors.funding_reference}
            />
            <SelectField
                label="Responsible"
                value={form.data.responsible_user_id}
                onChange={(v) => form.setData('responsible_user_id', v)}
                options={members}
                empty="Nobody yet"
                error={form.errors.responsible_user_id}
            />
            <div>
                <Button type="submit" disabled={form.processing}>
                    Add to plan
                </Button>
            </div>
        </form>
    );
}
