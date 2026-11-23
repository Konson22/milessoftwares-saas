import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
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
import {
    Detail,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import type { Approval, Documents, Option } from '@/lib/procurement';
import { formatDate, money, quantity } from '@/lib/procurement';
import { show as order, store as storeOrder } from '@/routes/orders';
import { amend, cancel, decide, edit, submit } from '@/routes/requisitions';
import { show as event, store as storeSourcing } from '@/routes/sourcing';

type Props = {
    requisition: {
        id: string;
        number: string | null;
        title: string;
        justification: string;
        status: string;
        statusReason: string | null;
        revision: number;
        requester: string;
        department: string | null;
        neededBy: string | null;
        deliveryLocation: string | null;
        currency: string;
        total: string;
        totalBase: string | null;
        baseCurrency: string;
        budgetReference: string | null;
        projectReference: string | null;
        fundingReference: string | null;
        planItem: string | null;
        lines: {
            id: string;
            kind: string;
            description: string;
            specification: string | null;
            quantity: string;
            unit: string | null;
            unitPrice: string;
            amount: string;
        }[];
    };
    progress: {
        sourcing: {
            id: string;
            number: string;
            status: string;
            link: boolean;
        }[];
        orders: {
            id: string;
            number: string | null;
            status: string;
            supplier: string;
            deliveryDate: string | null;
            link: boolean;
        }[];
    };
    approval: Approval;
    documents: Documents;
    can: {
        edit: boolean;
        submit: boolean;
        cancel: boolean;
        amend: boolean;
        source: boolean;
        order: boolean;
    };
    methods: Option[];
    suppliers: Option[];
};

export default function ShowRequisition({
    requisition: r,
    progress,
    approval,
    documents,
    can,
    methods,
    suppliers,
}: Props) {
    return (
        <>
            <Head title={r.number ?? r.title} />
            <PageHeader
                title={`${r.number ?? 'Draft'} · ${r.title}`}
                badges={<Status value={r.status} />}
                actions={
                    <>
                        {can.edit && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={edit(r.id)}>
                                    <Pencil /> Edit
                                </Link>
                            </Button>
                        )}
                        {can.submit && (
                            <ActionButton
                                url={submit(r.id).url}
                                label="Submit for approval"
                            />
                        )}
                        {can.amend && (
                            <ActionButton
                                url={amend(r.id).url}
                                label="Amend"
                                variant="outline"
                                reason="Why does it change?"
                            />
                        )}
                        {can.cancel && (
                            <ActionButton
                                url={cancel(r.id).url}
                                label="Cancel requisition"
                                variant="destructive"
                                reason="Why is it cancelled?"
                            />
                        )}
                    </>
                }
            />
            <div className="grid gap-6">
                {r.statusReason &&
                    ['returned', 'rejected', 'cancelled'].includes(
                        r.status,
                    ) && (
                        <div className="rounded-md border border-amber-500 bg-background px-3 py-2 text-sm">
                            {r.statusReason}
                        </div>
                    )}

                <Panel>
                    <PanelBody>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Requested by">{r.requester}</Detail>
                            <Detail label="Department">{r.department}</Detail>
                            <Detail label="Needed by">
                                {formatDate(r.neededBy)}
                            </Detail>
                            <Detail label="Deliver to">
                                {r.deliveryLocation}
                            </Detail>
                            <Detail label="Estimate">
                                {money(r.total, r.currency)}
                                {r.totalBase && (
                                    <span className="block text-xs text-muted-foreground">
                                        ≈ {money(r.totalBase, r.baseCurrency)}
                                    </span>
                                )}
                            </Detail>
                            <Detail label="Plan item">{r.planItem}</Detail>
                            <Detail label="Budget · Project · Funding">
                                {[
                                    r.budgetReference,
                                    r.projectReference,
                                    r.fundingReference,
                                ]
                                    .map((v) => v ?? '—')
                                    .join(' · ')}
                            </Detail>
                            <Detail label="Revision">{r.revision}</Detail>
                        </dl>
                        <p className="mt-4 text-sm whitespace-pre-line">
                            {r.justification}
                        </p>
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Lines" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                                <TableHead className="text-right">
                                    Unit price
                                </TableHead>
                                <TableHead className="text-right">
                                    Amount
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {r.lines.map((line) => (
                                <TableRow key={line.id}>
                                    <TableCell className="whitespace-normal">
                                        <span className="font-medium">
                                            {line.description}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {line.kind === 'service'
                                                ? 'Service'
                                                : 'Goods'}
                                            {line.specification &&
                                                ` · ${line.specification}`}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {quantity(line.quantity)} {line.unit}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(line.unitPrice, r.currency)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {money(line.amount, r.currency)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <ApprovalPanel
                    approval={approval}
                    decideUrl={decide(r.id).url}
                />

                {(progress.sourcing.length > 0 ||
                    progress.orders.length > 0) && (
                    <Panel>
                        <PanelHeader title="Buying progress" />
                        <PanelBody>
                            <ul className="divide-y">
                                {progress.sourcing.map((s) => (
                                    <li
                                        key={s.id}
                                        className="flex items-center justify-between gap-3 py-2"
                                    >
                                        {s.link ? (
                                            <Link
                                                href={event(s.id)}
                                                className="hover:underline"
                                            >
                                                Sourcing {s.number}
                                            </Link>
                                        ) : (
                                            <span>Sourcing {s.number}</span>
                                        )}
                                        <Status value={s.status} />
                                    </li>
                                ))}
                                {progress.orders.map((o) => (
                                    <li
                                        key={o.id}
                                        className="flex items-center justify-between gap-3 py-2"
                                    >
                                        <span>
                                            {o.link ? (
                                                <Link
                                                    href={order(o.id)}
                                                    className="hover:underline"
                                                >
                                                    Order {o.number ?? 'draft'}
                                                </Link>
                                            ) : (
                                                <>Order {o.number ?? 'draft'}</>
                                            )}{' '}
                                            <span className="text-sm text-muted-foreground">
                                                {o.supplier}
                                                {o.deliveryDate &&
                                                    ` · due ${formatDate(o.deliveryDate)}`}
                                            </span>
                                        </span>
                                        <Status value={o.status} />
                                    </li>
                                ))}
                            </ul>
                        </PanelBody>
                    </Panel>
                )}

                {(can.source || can.order) && (
                    <div className="grid gap-6 lg:grid-cols-2">
                        {can.source && (
                            <StartSourcing
                                requisitionId={r.id}
                                methods={methods}
                            />
                        )}
                        {can.order && (
                            <DirectOrder
                                requisitionId={r.id}
                                suppliers={suppliers}
                            />
                        )}
                    </div>
                )}

                <DocumentsPanel documents={documents} />
            </div>
        </>
    );
}

function StartSourcing({
    requisitionId,
    methods,
}: {
    requisitionId: string;
    methods: Option[];
}) {
    const form = useForm({
        requisition_id: requisitionId,
        method: methods[0]?.value ?? 'rfq',
        title: '',
        quotes_due_at: '',
        method_reason: '',
    });

    return (
        <Panel>
            <PanelHeader
                title="Source it"
                description="Ask suppliers to quote, propose or tender."
            />
            <PanelBody>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeSourcing().url);
                    }}
                >
                    <SelectField
                        label="Method"
                        value={form.data.method}
                        onChange={(v) => form.setData('method', v)}
                        options={methods}
                        error={form.errors.method}
                    />
                    <TextField
                        label="Quotations due"
                        type="datetime-local"
                        value={form.data.quotes_due_at}
                        onChange={(v) => form.setData('quotes_due_at', v)}
                        error={form.errors.quotes_due_at}
                    />
                    {form.data.method === 'single_source' && (
                        <TextArea
                            label="Why only one supplier?"
                            value={form.data.method_reason}
                            onChange={(v) => form.setData('method_reason', v)}
                            error={form.errors.method_reason}
                            rows={2}
                        />
                    )}
                    {form.errors.requisition_id && (
                        <p className="text-xs text-destructive">
                            {form.errors.requisition_id}
                        </p>
                    )}
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Start sourcing
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

function DirectOrder({
    requisitionId,
    suppliers,
}: {
    requisitionId: string;
    suppliers: Option[];
}) {
    const form = useForm({
        requisition_id: requisitionId,
        supplier_id: '',
    });
    const errors = form.errors as Record<string, string>;

    return (
        <Panel>
            <PanelHeader
                title="Buy directly"
                description="A purchase order to one supplier at the requisition's prices, within the direct purchase limit."
            />
            <PanelBody>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeOrder().url);
                    }}
                >
                    <SelectField
                        label="Supplier"
                        value={form.data.supplier_id}
                        onChange={(v) => form.setData('supplier_id', v)}
                        options={suppliers}
                        empty="Choose a supplier"
                        error={errors.supplier_id}
                    />
                    {errors.method && (
                        <p className="text-xs text-destructive">
                            {errors.method}
                        </p>
                    )}
                    <div>
                        <Button
                            type="submit"
                            variant="outline"
                            disabled={
                                form.processing || form.data.supplier_id === ''
                            }
                        >
                            Draft purchase order
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}
