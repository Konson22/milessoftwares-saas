import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { CurrencyRates } from '@miles/ui/components/currency-rates';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Check,
    Errors,
    SelectField,
    Status,
    TextArea,
    TextField,
} from '@/components/fields';
import type { Option } from '@/lib/procurement';
import { money } from '@/lib/procurement';
import { update } from '@/routes/settings';
import { store as storeCurrency } from '@/routes/settings/currencies';
import { save as saveRule } from '@/routes/settings/rules';

type Step = { type: 'permission' | 'member'; value: string; label?: string };

type Rule = {
    id: string;
    document_type: string;
    name: string;
    min_amount: string;
    max_amount: string;
    department_id: string;
    department: string | null;
    method: string;
    steps: Step[];
    active: boolean;
};

type Props = {
    settings: {
        allow_self_approval: boolean;
        require_plan: boolean;
        min_quotations: number;
        invoice_tolerance_percent: number;
        delivery_address: string;
        order_terms: string;
        finance_bills_enabled: boolean;
        finance_expense_account: string;
        method_limits: Record<string, string>;
    };
    currency: string;
    rules: Rule[];
    documentTypes: Option[];
    stepPermissions: Option[];
    members: Option[];
    departments: Option[];
    methods: Option[];
};

export default function Settings(props: Props) {
    const { settings, currency, rules, documentTypes, methods } = props;
    const [editing, setEditing] = useState<Rule | 'new' | null>(null);
    const form = useForm({
        ...settings,
        min_quotations: String(settings.min_quotations),
        invoice_tolerance_percent: String(settings.invoice_tolerance_percent),
    });
    const errors = form.errors as Record<string, string>;
    const typeLabel = (value: string) =>
        documentTypes.find((t) => t.value === value)?.label ?? value;

    return (
        <>
            <Head title="Settings" />
            <PageHeader title="Procurement settings" />
            <div className="grid gap-6">
                <form
                    className="grid gap-6"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.put(update().url, { preserveScroll: true });
                    }}
                >
                    <Panel>
                        <PanelHeader title="Controls" />
                        <PanelBody className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-3 sm:col-span-2">
                                <Check
                                    label="Allow people to approve, receive or decide what they prepared themselves (small teams only)"
                                    checked={form.data.allow_self_approval}
                                    onChange={(v) =>
                                        form.setData('allow_self_approval', v)
                                    }
                                />
                                <Check
                                    label="Requisitions must name an item of an approved procurement plan"
                                    checked={form.data.require_plan}
                                    onChange={(v) =>
                                        form.setData('require_plan', v)
                                    }
                                />
                            </div>
                            <TextField
                                label="Quotations expected per RFQ"
                                inputMode="numeric"
                                value={form.data.min_quotations}
                                onChange={(v) =>
                                    form.setData('min_quotations', v)
                                }
                                error={errors.min_quotations}
                                hint="Fewer than this needs a written reason at award."
                            />
                            <TextField
                                label="Invoice tolerance (%)"
                                inputMode="numeric"
                                value={form.data.invoice_tolerance_percent}
                                onChange={(v) =>
                                    form.setData('invoice_tolerance_percent', v)
                                }
                                error={errors.invoice_tolerance_percent}
                                hint="How far an invoice may exceed accepted goods and still match."
                            />
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Method limits"
                            description={`The most a single purchase may be worth with each method, in ${currency}. Empty means no limit.`}
                        />
                        <PanelBody className="grid gap-4 sm:grid-cols-3">
                            {methods.map((m) => (
                                <TextField
                                    key={m.value}
                                    label={m.label}
                                    inputMode="decimal"
                                    value={
                                        form.data.method_limits[m.value] ?? ''
                                    }
                                    onChange={(v) =>
                                        form.setData('method_limits', {
                                            ...form.data.method_limits,
                                            [m.value]: v,
                                        })
                                    }
                                    error={errors[`method_limits.${m.value}`]}
                                />
                            ))}
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader title="Purchase order defaults" />
                        <PanelBody className="grid gap-4 sm:grid-cols-2">
                            <TextArea
                                label="Delivery address"
                                value={form.data.delivery_address}
                                onChange={(v) =>
                                    form.setData('delivery_address', v)
                                }
                                error={errors.delivery_address}
                            />
                            <TextArea
                                label="Terms printed on orders"
                                value={form.data.order_terms}
                                onChange={(v) => form.setData('order_terms', v)}
                                error={errors.order_terms}
                            />
                        </PanelBody>
                    </Panel>

                    <Panel>
                        <PanelHeader
                            title="Finance"
                            description="Optional. When on, invoices that match their receipts are sent to Tatook Finance as bills, and Finance takes over their payment. Nothing is sent if the organization does not use Finance."
                        />
                        <PanelBody className="grid gap-4 sm:grid-cols-2 sm:items-end">
                            <Check
                                label="Send matched invoices to Finance"
                                checked={form.data.finance_bills_enabled}
                                onChange={(v) =>
                                    form.setData('finance_bills_enabled', v)
                                }
                            />
                            <TextField
                                label="Finance expense account code"
                                value={form.data.finance_expense_account}
                                onChange={(v) =>
                                    form.setData('finance_expense_account', v)
                                }
                                error={errors.finance_expense_account}
                            />
                        </PanelBody>
                    </Panel>

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save settings
                        </Button>
                    </div>
                </form>

                <Panel>
                    <PanelHeader
                        title="Approval rules"
                        description="Without a rule, each document needs one approval from people holding its approve permission (sourcing needs none). Changes apply to new submissions."
                        actions={
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setEditing('new')}
                            >
                                <Plus /> New rule
                            </Button>
                        }
                    />
                    <PanelBody className="grid gap-3">
                        {rules.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No rules: the defaults apply.
                            </p>
                        )}
                        {rules.map((rule) => (
                            <div
                                key={rule.id}
                                className="flex flex-wrap items-start justify-between gap-3 rounded-md border p-3"
                            >
                                <div className="grid gap-1 text-sm">
                                    <p className="font-medium">
                                        {rule.name}{' '}
                                        <span className="font-normal text-muted-foreground">
                                            · {typeLabel(rule.document_type)}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        {money(rule.min_amount, currency)} to{' '}
                                        {rule.max_amount
                                            ? money(rule.max_amount, currency)
                                            : 'any amount'}
                                        {rule.department &&
                                            ` · ${rule.department}`}
                                        {rule.method && ` · ${rule.method}`}
                                    </p>
                                    <ol className="list-decimal pl-5">
                                        {rule.steps.map((s, i) => (
                                            <li key={i}>{s.label}</li>
                                        ))}
                                    </ol>
                                </div>
                                <div className="flex items-center gap-2">
                                    {!rule.active && (
                                        <Status value="inactive" />
                                    )}
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() => setEditing(rule)}
                                    >
                                        Edit
                                    </Button>
                                </div>
                            </div>
                        ))}
                        {editing && (
                            <RuleForm
                                key={editing === 'new' ? 'new' : editing.id}
                                rule={editing === 'new' ? null : editing}
                                onDone={() => setEditing(null)}
                                {...props}
                            />
                        )}
                    </PanelBody>
                </Panel>

                <CurrencyRates action={storeCurrency().url} />
            </div>
        </>
    );
}

function RuleForm({
    rule,
    onDone,
    currency,
    documentTypes,
    stepPermissions,
    members,
    departments,
    methods,
}: Props & { rule: Rule | null; onDone: () => void }) {
    const form = useForm({
        document_type: rule?.document_type ?? 'requisition',
        name: rule?.name ?? '',
        min_amount: rule?.min_amount ?? '',
        max_amount: rule?.max_amount ?? '',
        department_id: rule?.department_id ?? '',
        method: rule?.method ?? '',
        active: rule?.active ?? true,
        steps: (
            rule?.steps ?? [
                {
                    type: 'permission',
                    value: 'procurement.requisitions.approve',
                },
            ]
        ).map((s) => ({ type: s.type, value: s.value })) as Step[],
    });
    const errors = form.errors as Record<string, string>;
    const setStep = (i: number, patch: Partial<Step>) =>
        form.setData(
            'steps',
            form.data.steps.map((s, j) => (j === i ? { ...s, ...patch } : s)),
        );

    return (
        <form
            className="grid gap-3 rounded-md border p-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(saveRule(rule ? { rule: rule.id } : {}).url, {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <div className="grid gap-3 sm:grid-cols-3">
                <TextField
                    label="Name"
                    value={form.data.name}
                    onChange={(v) => form.setData('name', v)}
                    error={errors.name}
                />
                <SelectField
                    label="Applies to"
                    value={form.data.document_type}
                    onChange={(v) => form.setData('document_type', v)}
                    options={documentTypes}
                />
                <SelectField
                    label="Department"
                    value={form.data.department_id}
                    onChange={(v) => form.setData('department_id', v)}
                    options={departments}
                    empty="Any department"
                />
                <TextField
                    label={`From (${currency})`}
                    inputMode="decimal"
                    value={form.data.min_amount}
                    onChange={(v) => form.setData('min_amount', v)}
                    error={errors.min_amount}
                />
                <TextField
                    label={`Up to (${currency})`}
                    inputMode="decimal"
                    value={form.data.max_amount}
                    onChange={(v) => form.setData('max_amount', v)}
                    error={errors.max_amount}
                    hint="Empty: no upper limit"
                />
                <SelectField
                    label="Method"
                    value={form.data.method}
                    onChange={(v) => form.setData('method', v)}
                    options={methods}
                    empty="Any method"
                />
            </div>
            <div className="grid gap-2">
                <p className="text-sm font-medium">Steps, in order</p>
                {form.data.steps.map((step, i) => (
                    <div key={i} className="flex flex-wrap items-center gap-2">
                        <span className="w-6 text-sm">{i + 1}.</span>
                        <select
                            aria-label="Who"
                            value={step.type}
                            onChange={(e) =>
                                setStep(i, {
                                    type: e.target.value as Step['type'],
                                    value:
                                        e.target.value === 'permission'
                                            ? (stepPermissions[0]?.value ?? '')
                                            : '',
                                })
                            }
                            className="h-9 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            <option value="permission">Anyone who can</option>
                            <option value="member">A named person</option>
                        </select>
                        <select
                            aria-label="Approver"
                            value={step.value}
                            onChange={(e) =>
                                setStep(i, { value: e.target.value })
                            }
                            className="h-9 min-w-56 flex-1 rounded-md border border-input bg-background px-2 text-sm"
                        >
                            {step.type === 'member' && (
                                <option value="">Choose a person</option>
                            )}
                            {(step.type === 'permission'
                                ? stepPermissions
                                : members
                            ).map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label="Remove step"
                            disabled={form.data.steps.length === 1}
                            onClick={() =>
                                form.setData(
                                    'steps',
                                    form.data.steps.filter((_, j) => j !== i),
                                )
                            }
                        >
                            <Trash2 />
                        </Button>
                        {errors[`steps.${i}.value`] && (
                            <span className="text-xs text-destructive">
                                {errors[`steps.${i}.value`]}
                            </span>
                        )}
                    </div>
                ))}
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={form.data.steps.length >= 6}
                        onClick={() =>
                            form.setData('steps', [
                                ...form.data.steps,
                                {
                                    type: 'permission',
                                    value: stepPermissions[0]?.value ?? '',
                                },
                            ])
                        }
                    >
                        <Plus /> Add step
                    </Button>
                </div>
            </div>
            <Check
                label="Active"
                checked={form.data.active}
                onChange={(v) => form.setData('active', v)}
            />
            <Errors errors={errors.steps ? { steps: errors.steps } : {}} />
            <div className="flex gap-2">
                <Button type="submit" disabled={form.processing}>
                    Save rule
                </Button>
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
            </div>
        </form>
    );
}
