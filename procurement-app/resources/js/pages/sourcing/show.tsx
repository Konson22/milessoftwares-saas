import { Head, Link, router, useForm } from '@inertiajs/react';
import { Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
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
import {
    ActionButton,
    ApprovalPanel,
    DocumentsPanel,
} from '@/components/actions';
import {
    Check,
    Detail,
    Errors,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import type { Approval, Documents, Option } from '@/lib/procurement';
import { formatDate, formatDateTime, money, quantity } from '@/lib/procurement';
import { fromAward, show as order } from '@/routes/orders';
import { show as requisition } from '@/routes/requisitions';
import {
    award as recommend,
    cancel,
    close,
    criteria as saveCriteria,
    decide,
    evaluation,
    invite,
    issue,
    uninvite,
    update,
} from '@/routes/sourcing';
import {
    decide as decideAward,
    withdraw as withdrawAward,
} from '@/routes/sourcing/awards';
import {
    evaluate,
    store as storeQuotation,
    withdraw as withdrawQuotation,
} from '@/routes/sourcing/quotations';

type Item = {
    id: string;
    kind: string;
    description: string;
    specification: string | null;
    quantity: string;
    unit: string | null;
};

type Criterion = {
    id: string;
    name: string;
    kind: 'pass_fail' | 'score';
    mandatory: boolean;
    maxScore: number | null;
};

type Quotation = {
    id: string;
    supplier: string;
    supplierId: string;
    reference: string | null;
    late: boolean;
    currency: string;
    rate: string | null;
    total: string | null;
    totalBase: string | null;
    deliveryDays: number | null;
    warranty: string | null;
    paymentTerms: string | null;
    validUntil: string | null;
    lines: {
        itemId: string;
        compliant: boolean;
        note: string | null;
        unitPrice: string | null;
        amount: string | null;
    }[];
    compliance: string;
    scores: Record<
        string,
        { passed?: boolean | null; score?: number | null; evaluations: number }
    >;
    technical: number;
    rank: number | null;
    myScores: Record<
        string,
        { passed: boolean | null; score: string | null; note: string | null }
    >;
};

type Props = {
    event: {
        id: string;
        number: string;
        title: string;
        description: string | null;
        method: string;
        methodLabel: string;
        methodReason: string | null;
        status: string;
        statusReason: string | null;
        sealed: boolean;
        pricesOpen: boolean;
        deliveryLocation: string | null;
        quotesDueAt: string | null;
        requiredBy: string | null;
        requisition: { id: string; number: string; title: string };
        items: Item[];
        invited: { id: string; name: string; status: string }[];
        criteria: Criterion[];
    };
    baseCurrency: string;
    quotations: Quotation[];
    award: {
        id: string;
        status: string;
        supplier: string;
        total: string;
        currency: string;
        justification: string;
        deviationReason: string | null;
        recommendedBy: string;
        approval: Approval;
        order: { id: string; number: string | null } | null;
    } | null;
    approval: Approval;
    documents: Documents;
    suppliers: Option[];
    currencies: string[];
    can: { manage: boolean; evaluate: boolean; order: boolean };
};

export default function ShowSourcing(props: Props) {
    const { event, quotations, award, approval, documents, can } = props;
    const draft = event.status === 'draft';
    const liveAward =
        award && ['pending_approval', 'approved'].includes(award.status);

    return (
        <>
            <Head title={`${event.number} · ${event.title}`} />
            <PageHeader
                title={`${event.number} · ${event.title}`}
                badges={
                    <>
                        <Status value={event.status} />
                        {event.sealed && (
                            <StatusBadge tone="info">Sealed</StatusBadge>
                        )}
                    </>
                }
                actions={
                    can.manage && (
                        <>
                            {draft && (
                                <ActionButton
                                    url={issue(event.id).url}
                                    label="Issue to suppliers"
                                />
                            )}
                            {event.status === 'issued' && (
                                <ActionButton
                                    url={close(event.id).url}
                                    label="Close quotations"
                                    confirm="Close this event? No more quotations can be recorded."
                                />
                            )}
                            {event.status === 'closed' && (
                                <ActionButton
                                    url={evaluation(event.id).url}
                                    label="Start evaluation"
                                />
                            )}
                            {!['awarded', 'cancelled'].includes(
                                event.status,
                            ) && (
                                <ActionButton
                                    url={cancel(event.id).url}
                                    label="Cancel"
                                    variant="destructive"
                                    reason="Why is it cancelled?"
                                />
                            )}
                        </>
                    )
                }
            />
            <div className="grid gap-6">
                {event.statusReason && (
                    <div className="rounded-md border border-amber-500 bg-background px-3 py-2 text-sm">
                        {event.statusReason}
                    </div>
                )}

                <Panel>
                    <PanelBody>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <Detail label="Method">{event.methodLabel}</Detail>
                            <Detail label="Requisition">
                                <Link
                                    href={requisition(event.requisition.id)}
                                    className="hover:underline"
                                >
                                    {event.requisition.number}
                                </Link>{' '}
                                · {event.requisition.title}
                            </Detail>
                            <Detail label="Quotations due">
                                {formatDateTime(event.quotesDueAt)}
                            </Detail>
                            <Detail label="Required by">
                                {formatDate(event.requiredBy)}
                            </Detail>
                        </dl>
                        {event.methodReason && (
                            <p className="mt-4 text-sm">
                                <span className="text-muted-foreground">
                                    Why this method:{' '}
                                </span>
                                {event.methodReason}
                            </p>
                        )}
                        {event.description && (
                            <p className="mt-2 text-sm whitespace-pre-line">
                                {event.description}
                            </p>
                        )}
                    </PanelBody>
                </Panel>

                {draft && can.manage && <EditDetails event={event} />}

                <Panel>
                    <PanelHeader title="Items" />
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Item</TableHead>
                                <TableHead className="text-right">
                                    Quantity
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {event.items.map((item, i) => (
                                <TableRow key={item.id}>
                                    <TableCell>{i + 1}</TableCell>
                                    <TableCell className="whitespace-normal">
                                        <span className="font-medium">
                                            {item.description}
                                        </span>
                                        {item.specification && (
                                            <span className="block text-xs text-muted-foreground">
                                                {item.specification}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {quantity(item.quantity)} {item.unit}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Panel>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Invitations {...props} />
                    <Criteria {...props} />
                </div>

                <ApprovalPanel
                    approval={approval}
                    decideUrl={decide(event.id).url}
                />

                {event.status === 'issued' && can.manage && (
                    <RecordQuotation {...props} />
                )}

                <Comparison {...props} />

                {can.manage &&
                    event.status === 'evaluation' &&
                    !liveAward &&
                    quotations.length > 0 && <Recommend {...props} />}

                {award && (
                    <Panel>
                        <PanelHeader
                            title={`Award to ${award.supplier}`}
                            description={`${money(award.total, award.currency)} · recommended by ${award.recommendedBy}`}
                            actions={<Status value={award.status} />}
                        />
                        <PanelBody className="grid gap-3 text-sm">
                            <p>{award.justification}</p>
                            {award.deviationReason && (
                                <p>
                                    <span className="text-muted-foreground">
                                        Why not the lowest compliant offer:{' '}
                                    </span>
                                    {award.deviationReason}
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                {can.manage &&
                                    award.status === 'pending_approval' && (
                                        <ActionButton
                                            url={
                                                withdrawAward({
                                                    event: event.id,
                                                    award: award.id,
                                                }).url
                                            }
                                            label="Withdraw recommendation"
                                            variant="outline"
                                        />
                                    )}
                                {award.order ? (
                                    <Link
                                        href={order(award.order.id)}
                                        className="hover:underline"
                                    >
                                        Purchase order{' '}
                                        {award.order.number ?? '(draft)'}
                                    </Link>
                                ) : (
                                    can.order &&
                                    award.status === 'approved' && (
                                        <ActionButton
                                            url={fromAward(award.id).url}
                                            label="Create purchase order"
                                        />
                                    )
                                )}
                            </div>
                        </PanelBody>
                    </Panel>
                )}
                {award && (
                    <ApprovalPanel
                        approval={award.approval}
                        decideUrl={
                            decideAward({ event: event.id, award: award.id })
                                .url
                        }
                    />
                )}

                <DocumentsPanel documents={documents} />
            </div>
        </>
    );
}

function EditDetails({ event }: { event: Props['event'] }) {
    const form = useForm({
        title: event.title,
        description: event.description ?? '',
        delivery_location: event.deliveryLocation ?? '',
        quotes_due_at: (event.quotesDueAt ?? '').slice(0, 16),
        required_by: event.requiredBy ?? '',
        sealed: event.sealed,
    });

    return (
        <Panel>
            <PanelHeader title="Event details" />
            <PanelBody>
                <form
                    className="grid gap-3 sm:grid-cols-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(update(event.id).url, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <TextField
                        label="Title"
                        value={form.data.title}
                        onChange={(v) => form.setData('title', v)}
                        error={form.errors.title}
                    />
                    <TextField
                        label="Quotations due"
                        type="datetime-local"
                        value={form.data.quotes_due_at}
                        onChange={(v) => form.setData('quotes_due_at', v)}
                        error={form.errors.quotes_due_at}
                    />
                    <TextField
                        label="Required by"
                        type="date"
                        value={form.data.required_by}
                        onChange={(v) => form.setData('required_by', v)}
                        error={form.errors.required_by}
                    />
                    <TextArea
                        label="Instructions to suppliers"
                        value={form.data.description}
                        onChange={(v) => form.setData('description', v)}
                        className="sm:col-span-2"
                        rows={2}
                    />
                    <TextField
                        label="Deliver to"
                        value={form.data.delivery_location}
                        onChange={(v) => form.setData('delivery_location', v)}
                    />
                    <div className="flex items-center gap-4 sm:col-span-3">
                        <Check
                            label="Sealed: prices hidden until quotations close"
                            checked={form.data.sealed}
                            onChange={(v) => form.setData('sealed', v)}
                        />
                        <Button type="submit" disabled={form.processing}>
                            Save
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

function Invitations({ event, suppliers, can }: Props) {
    const [chosen, setChosen] = useState('');
    const open = can.manage && ['draft', 'issued'].includes(event.status);
    const invitedIds = new Set(event.invited.map((s) => s.id));

    return (
        <Panel>
            <PanelHeader
                title="Invited suppliers"
                description={
                    event.method === 'tender'
                        ? 'Open tender: any supplier who bids is added.'
                        : undefined
                }
            />
            <PanelBody className="grid gap-3">
                {event.invited.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nobody invited yet.
                    </p>
                ) : (
                    <ul className="grid gap-1 text-sm">
                        {event.invited.map((s) => (
                            <li
                                key={s.id}
                                className="flex items-center justify-between gap-2"
                            >
                                <span>
                                    {s.name}{' '}
                                    {s.status !== 'active' && (
                                        <Status value={s.status} />
                                    )}
                                </span>
                                {open && (
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label={`Remove ${s.name}`}
                                        onClick={() =>
                                            router.delete(
                                                uninvite({
                                                    event: event.id,
                                                    supplier: s.id,
                                                }).url,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <X />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {open && (
                    <div className="flex gap-2">
                        <select
                            aria-label="Supplier to invite"
                            value={chosen}
                            onChange={(e) => setChosen(e.target.value)}
                            className="h-9 flex-1 rounded-md border border-input bg-background px-3 text-sm"
                        >
                            <option value="">Choose a supplier</option>
                            {suppliers
                                .filter((s) => !invitedIds.has(s.value))
                                .map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                        </select>
                        <Button
                            variant="outline"
                            disabled={chosen === ''}
                            onClick={() =>
                                router.post(
                                    invite(event.id).url,
                                    { supplier_ids: [chosen] },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setChosen(''),
                                    },
                                )
                            }
                        >
                            Invite
                        </Button>
                    </div>
                )}
            </PanelBody>
        </Panel>
    );
}

type CriterionDraft = {
    name: string;
    kind: 'pass_fail' | 'score';
    mandatory: boolean;
    max_score: string;
};

function Criteria({ event, can }: Props) {
    const editable = can.manage && event.status === 'draft';
    const form = useForm<{ criteria: CriterionDraft[] }>({
        criteria: event.criteria.map((c) => ({
            name: c.name,
            kind: c.kind,
            mandatory: c.mandatory,
            max_score: c.maxScore ? String(c.maxScore) : '',
        })),
    });
    const set = (i: number, patch: Partial<CriterionDraft>) =>
        form.setData(
            'criteria',
            form.data.criteria.map((c, j) =>
                j === i ? { ...c, ...patch } : c,
            ),
        );

    if (!editable) {
        return (
            <Panel>
                <PanelHeader
                    title="Evaluation criteria"
                    description="Fixed once the event was issued."
                />
                <PanelBody>
                    {event.criteria.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Price only.
                        </p>
                    ) : (
                        <ul className="grid gap-1 text-sm">
                            {event.criteria.map((c) => (
                                <li key={c.id}>
                                    {c.name}{' '}
                                    <span className="text-muted-foreground">
                                        {c.kind === 'pass_fail'
                                            ? c.mandatory
                                                ? '· pass/fail, mandatory'
                                                : '· pass/fail'
                                            : `· scored out of ${c.maxScore}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </PanelBody>
            </Panel>
        );
    }

    return (
        <Panel>
            <PanelHeader
                title="Evaluation criteria"
                description="Set before issue. A mandatory pass/fail criterion excludes quotations that fail it."
            />
            <PanelBody className="grid gap-3">
                {form.data.criteria.map((c, i) => (
                    <div key={i} className="flex flex-wrap items-center gap-2">
                        <Input
                            aria-label="Criterion"
                            value={c.name}
                            placeholder="Criterion"
                            onChange={(e) => set(i, { name: e.target.value })}
                            className="min-w-48 flex-1"
                        />
                        <select
                            aria-label="Kind"
                            value={c.kind}
                            onChange={(e) =>
                                set(i, {
                                    kind: e.target
                                        .value as CriterionDraft['kind'],
                                })
                            }
                            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="pass_fail">Pass / fail</option>
                            <option value="score">Score</option>
                        </select>
                        {c.kind === 'score' ? (
                            <Input
                                aria-label="Out of"
                                placeholder="Out of"
                                inputMode="numeric"
                                value={c.max_score}
                                onChange={(e) =>
                                    set(i, { max_score: e.target.value })
                                }
                                className="w-24"
                            />
                        ) : (
                            <Check
                                label="Mandatory"
                                checked={c.mandatory}
                                onChange={(v) => set(i, { mandatory: v })}
                            />
                        )}
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Remove criterion"
                            onClick={() =>
                                form.setData(
                                    'criteria',
                                    form.data.criteria.filter(
                                        (_, j) => j !== i,
                                    ),
                                )
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                ))}
                <Errors errors={form.errors as Record<string, string>} />
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            form.setData('criteria', [
                                ...form.data.criteria,
                                {
                                    name: '',
                                    kind: 'pass_fail',
                                    mandatory: true,
                                    max_score: '',
                                },
                            ])
                        }
                    >
                        <Plus /> Add criterion
                    </Button>
                    <Button
                        size="sm"
                        disabled={form.processing}
                        onClick={() => {
                            form.transform((data) => ({
                                criteria: data.criteria.map((c) => ({
                                    name: c.name,
                                    kind: c.kind,
                                    mandatory:
                                        c.kind === 'pass_fail' && c.mandatory,
                                    max_score:
                                        c.kind === 'score'
                                            ? Number(c.max_score) || null
                                            : null,
                                })),
                            }));
                            form.put(saveCriteria(event.id).url, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        Save criteria
                    </Button>
                </div>
            </PanelBody>
        </Panel>
    );
}

function RecordQuotation({ event, suppliers, currencies }: Props) {
    const form = useForm({
        supplier_id: '',
        reference: '',
        quoted_on: new Date().toISOString().slice(0, 10),
        currency: currencies[0] ?? '',
        delivery_days: '',
        warranty: '',
        payment_terms: '',
        valid_until: '',
        lines: event.items.map((item) => ({
            sourcing_item_id: item.id,
            unit_price: '',
            compliant: true,
            note: '',
        })),
    });
    const errors = form.errors as Record<string, string>;
    const options =
        event.method === 'tender'
            ? suppliers
            : suppliers.filter((s) =>
                  event.invited.some((i) => i.id === s.value),
              );

    return (
        <Panel>
            <PanelHeader
                title="Record a quotation"
                description="Prices are kept in the currency quoted, with the rate used to compare them."
            />
            <PanelBody>
                <form
                    className="grid gap-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(storeQuotation(event.id).url, {
                            preserveScroll: true,
                            onSuccess: () => form.reset(),
                        });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-4">
                        <SelectField
                            label="Supplier"
                            value={form.data.supplier_id}
                            onChange={(v) => form.setData('supplier_id', v)}
                            options={options}
                            empty="Choose"
                            error={errors.supplier_id}
                        />
                        <TextField
                            label="Their reference"
                            value={form.data.reference}
                            onChange={(v) => form.setData('reference', v)}
                        />
                        <TextField
                            label="Quoted on"
                            type="date"
                            value={form.data.quoted_on}
                            onChange={(v) => form.setData('quoted_on', v)}
                            error={errors.quoted_on}
                        />
                        <SelectField
                            label="Currency"
                            value={form.data.currency}
                            onChange={(v) => form.setData('currency', v)}
                            options={currencies.map((c) => ({
                                value: c,
                                label: c,
                            }))}
                            error={errors.currency}
                        />
                        <TextField
                            label="Delivery (days)"
                            inputMode="numeric"
                            value={form.data.delivery_days}
                            onChange={(v) => form.setData('delivery_days', v)}
                            error={errors.delivery_days}
                        />
                        <TextField
                            label="Warranty"
                            value={form.data.warranty}
                            onChange={(v) => form.setData('warranty', v)}
                        />
                        <TextField
                            label="Payment terms"
                            value={form.data.payment_terms}
                            onChange={(v) => form.setData('payment_terms', v)}
                        />
                        <TextField
                            label="Valid until"
                            type="date"
                            value={form.data.valid_until}
                            onChange={(v) => form.setData('valid_until', v)}
                        />
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Item</TableHead>
                                <TableHead>
                                    Unit price ({form.data.currency})
                                </TableHead>
                                <TableHead>Meets the specification</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {event.items.map((item, i) => (
                                <TableRow key={item.id}>
                                    <TableCell className="whitespace-normal">
                                        {item.description} ×{' '}
                                        {quantity(item.quantity)}
                                    </TableCell>
                                    <TableCell>
                                        <Input
                                            aria-label={`Unit price for ${item.description}`}
                                            inputMode="decimal"
                                            value={
                                                form.data.lines[i].unit_price
                                            }
                                            onChange={(e) =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.map(
                                                        (l, j) =>
                                                            j === i
                                                                ? {
                                                                      ...l,
                                                                      unit_price:
                                                                          e
                                                                              .target
                                                                              .value,
                                                                  }
                                                                : l,
                                                    ),
                                                )
                                            }
                                            className="w-36"
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Check
                                            label="Yes"
                                            checked={
                                                form.data.lines[i].compliant
                                            }
                                            onChange={(v) =>
                                                form.setData(
                                                    'lines',
                                                    form.data.lines.map(
                                                        (l, j) =>
                                                            j === i
                                                                ? {
                                                                      ...l,
                                                                      compliant:
                                                                          v,
                                                                  }
                                                                : l,
                                                    ),
                                                )
                                            }
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <Errors
                        errors={Object.fromEntries(
                            Object.entries(errors).filter(([k]) =>
                                k.startsWith('lines'),
                            ),
                        )}
                    />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Record quotation
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}

function Comparison({ event, quotations, baseCurrency, can }: Props) {
    const [evaluating, setEvaluating] = useState<string | null>(null);

    if (quotations.length === 0) {
        return null;
    }

    return (
        <Panel>
            <PanelHeader
                title="Quotations"
                description={
                    event.pricesOpen
                        ? `Ranked by total in ${baseCurrency} among compliant quotations.`
                        : 'Sealed: prices stay hidden until quotations close.'
                }
            />
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Rank</TableHead>
                        <TableHead>Supplier</TableHead>
                        <TableHead className="text-right">Quoted</TableHead>
                        <TableHead className="text-right">
                            In {baseCurrency}
                        </TableHead>
                        <TableHead>Compliance</TableHead>
                        <TableHead className="text-right">Technical</TableHead>
                        <TableHead>Terms</TableHead>
                        <TableHead />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {quotations.map((q) => (
                        <TableRow key={q.id}>
                            <TableCell>{q.rank ?? '—'}</TableCell>
                            <TableCell>
                                <span className="font-medium">
                                    {q.supplier}
                                </span>
                                {q.late && (
                                    <span className="ml-2 text-xs text-destructive">
                                        late
                                    </span>
                                )}
                                {q.reference && (
                                    <span className="block text-xs text-muted-foreground">
                                        Ref. {q.reference}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(q.total, q.currency)}
                                {q.rate && (
                                    <span className="block text-xs text-muted-foreground">
                                        at {q.rate} {baseCurrency}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {money(q.totalBase, baseCurrency)}
                            </TableCell>
                            <TableCell>
                                <Status value={q.compliance} />
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                                {q.technical}
                            </TableCell>
                            <TableCell className="text-xs whitespace-normal">
                                {[
                                    q.deliveryDays !== null &&
                                        `${q.deliveryDays} days`,
                                    q.warranty,
                                    q.paymentTerms,
                                    q.validUntil &&
                                        `valid to ${formatDate(q.validUntil)}`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') || '—'}
                            </TableCell>
                            <TableCell className="text-right">
                                <div className="flex justify-end gap-2">
                                    {can.evaluate &&
                                        event.status === 'evaluation' && (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEvaluating(
                                                        evaluating === q.id
                                                            ? null
                                                            : q.id,
                                                    )
                                                }
                                            >
                                                Evaluate
                                            </Button>
                                        )}
                                    {can.manage &&
                                        ['issued', 'closed'].includes(
                                            event.status,
                                        ) && (
                                            <ActionButton
                                                url={
                                                    withdrawQuotation({
                                                        event: event.id,
                                                        quotation: q.id,
                                                    }).url
                                                }
                                                label="Withdraw"
                                                variant="outline"
                                                reason="Why is it withdrawn?"
                                            />
                                        )}
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
            {evaluating && (
                <PanelBody className="border-t">
                    <Evaluate
                        key={evaluating}
                        eventId={event.id}
                        criteria={event.criteria}
                        quotation={quotations.find((q) => q.id === evaluating)!}
                        onDone={() => setEvaluating(null)}
                    />
                </PanelBody>
            )}
        </Panel>
    );
}

function Evaluate({
    eventId,
    criteria,
    quotation,
    onDone,
}: {
    eventId: string;
    criteria: Criterion[];
    quotation: Quotation;
    onDone: () => void;
}) {
    const form = useForm({
        results: Object.fromEntries(
            criteria.map((c) => {
                const mine = quotation.myScores[c.id];

                return [
                    c.id,
                    {
                        passed: mine?.passed ?? true,
                        score: mine?.score ?? '',
                        note: mine?.note ?? '',
                    },
                ];
            }),
        ) as Record<string, { passed: boolean; score: string; note: string }>,
    });
    const set = (
        id: string,
        patch: Partial<{ passed: boolean; score: string; note: string }>,
    ) =>
        form.setData('results', {
            ...form.data.results,
            [id]: { ...form.data.results[id], ...patch },
        });

    if (criteria.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                This event has no criteria: quotations are compared on price
                only.
            </p>
        );
    }

    return (
        <form
            className="grid gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    results: Object.fromEntries(
                        criteria.map((c) => [
                            c.id,
                            c.kind === 'pass_fail'
                                ? {
                                      passed: data.results[c.id].passed,
                                      note: data.results[c.id].note,
                                  }
                                : {
                                      score: data.results[c.id].score,
                                      note: data.results[c.id].note,
                                  },
                        ]),
                    ),
                }));
                form.post(
                    evaluate({ event: eventId, quotation: quotation.id }).url,
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <p className="font-medium">
                Your evaluation of {quotation.supplier}
            </p>
            {criteria.map((c) => (
                <div
                    key={c.id}
                    className="grid gap-2 sm:grid-cols-3 sm:items-center"
                >
                    <span className="text-sm">
                        {c.name}
                        {c.mandatory && (
                            <span className="text-muted-foreground">
                                {' '}
                                (mandatory)
                            </span>
                        )}
                    </span>
                    {c.kind === 'pass_fail' ? (
                        <select
                            aria-label={c.name}
                            value={form.data.results[c.id].passed ? '1' : '0'}
                            onChange={(e) =>
                                set(c.id, { passed: e.target.value === '1' })
                            }
                            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="1">Passes</option>
                            <option value="0">Fails</option>
                        </select>
                    ) : (
                        <Input
                            aria-label={c.name}
                            inputMode="decimal"
                            placeholder={`0 to ${c.maxScore}`}
                            value={form.data.results[c.id].score}
                            onChange={(e) =>
                                set(c.id, { score: e.target.value })
                            }
                        />
                    )}
                    <Input
                        aria-label={`Note on ${c.name}`}
                        placeholder="Note"
                        value={form.data.results[c.id].note}
                        onChange={(e) => set(c.id, { note: e.target.value })}
                    />
                </div>
            ))}
            <Errors errors={form.errors as Record<string, string>} />
            <div>
                <Button type="submit" disabled={form.processing}>
                    Save evaluation
                </Button>
            </div>
        </form>
    );
}

function Recommend({ event, quotations }: Props) {
    const form = useForm({
        quotation_id: '',
        justification: '',
        deviation_reason: '',
    });

    return (
        <Panel>
            <PanelHeader
                title="Recommend an award"
                description="Choosing anything but the lowest compliant quotation, or awarding with fewer quotations than required, needs a written reason."
            />
            <PanelBody>
                <form
                    className="grid gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(recommend(event.id).url, {
                            preserveScroll: true,
                        });
                    }}
                >
                    <SelectField
                        label="Quotation"
                        value={form.data.quotation_id}
                        onChange={(v) => form.setData('quotation_id', v)}
                        options={quotations.map((q) => ({
                            value: q.id,
                            label: `${q.supplier} · ${money(q.total, q.currency)}${q.rank ? ` · rank ${q.rank}` : ''}`,
                        }))}
                        empty="Choose"
                        error={form.errors.quotation_id}
                        className="max-w-md"
                    />
                    <TextArea
                        label="Justification"
                        value={form.data.justification}
                        onChange={(v) => form.setData('justification', v)}
                        error={form.errors.justification}
                        rows={2}
                    />
                    <TextArea
                        label="Reason for deviation (when needed)"
                        value={form.data.deviation_reason}
                        onChange={(v) => form.setData('deviation_reason', v)}
                        error={form.errors.deviation_reason}
                        rows={2}
                    />
                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Recommend for approval
                        </Button>
                    </div>
                </form>
            </PanelBody>
        </Panel>
    );
}
