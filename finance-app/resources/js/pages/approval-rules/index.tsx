import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { Field } from '@/components/field';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { useAmountStep } from '@/lib/format';
import { settings as journalSettings } from '@/routes/journal-approvals';
import { settings as paymentSettings } from '@/routes/payment-requests';

type Props = {
    journals: { enabled: boolean; threshold: string };
    payments: {
        approval: boolean;
        threshold: string;
        review: boolean;
        separation: boolean;
    };
};

/**
 * When journals and payments need a second person. Kept apart from the lists
 * of what is waiting, so setting the rules does not mean seeing the money.
 */
export default function ApprovalRules({ journals, payments }: Props) {
    return (
        <>
            <Head title="Approval rules" />
            <PageHeader
                title="Approval rules"
                description="What needs a second person before it reaches the books. Changes do not affect anything already waiting."
            />
            <PaymentRules setting={payments} />
            <JournalRules setting={journals} />
        </>
    );
}

function PaymentRules({ setting }: { setting: Props['payments'] }) {
    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Payments"
                description="Apply to every payment request."
            />
            <PanelBody>
                <Form {...paymentSettings.form()} className="grid gap-3">
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2 text-sm">
                                <Rule
                                    name="payment_approval"
                                    checked={setting.approval}
                                    label="Hold direct payments"
                                    hint="Supplier payments, money out, transfers, tax settlements, journals and reversals that take money out of a bank, and statement lines entered in reconciliation need approval as well."
                                />
                                <Rule
                                    name="payment_review"
                                    checked={setting.review}
                                    label="Review before approval"
                                    hint="A reviewer checks each request before it reaches an approver."
                                />
                                <Rule
                                    name="payment_separation"
                                    checked={setting.separation}
                                    label="Keep duties separate"
                                    hint="Nobody approves what they prepared or reviewed, or disburses what they prepared, reviewed or approved. Turn off only for very small teams."
                                />
                            </div>
                            <Field
                                id="payment-threshold"
                                label="Approval limit"
                                error={errors.threshold}
                                hint="Smaller payments need no approval. Blank: every payment does."
                                className="max-w-64"
                            >
                                <Input
                                    id="payment-threshold"
                                    name="threshold"
                                    inputMode="decimal"
                                    defaultValue={setting.threshold}
                                    className="text-right tabular-nums"
                                />
                            </Field>
                            <div>
                                <Button type="submit" disabled={processing}>
                                    Save payment rules
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function JournalRules({ setting }: { setting: Props['journals'] }) {
    const step = useAmountStep();
    const [enabled, setEnabled] = useState(setting.enabled);

    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Journals"
                description="For manual and recurring journals. Journals from invoices, bills, payments, banking and products post as before."
            />
            <PanelBody>
                <Form
                    {...journalSettings.form()}
                    className="flex flex-wrap items-end gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <label className="flex h-9 items-center gap-2 text-sm">
                                <input
                                    type="hidden"
                                    name="journal_approval"
                                    value="0"
                                />
                                <input
                                    type="checkbox"
                                    name="journal_approval"
                                    value="1"
                                    checked={enabled}
                                    onChange={(event) =>
                                        setEnabled(event.target.checked)
                                    }
                                />
                                Manual journals need a second person's approval
                            </label>
                            {enabled && (
                                <Field
                                    id="journal-threshold"
                                    label="From this amount up"
                                    error={errors.threshold}
                                    hint="Blank: every manual journal"
                                >
                                    <Input
                                        id="journal-threshold"
                                        name="threshold"
                                        type="number"
                                        min="0"
                                        step={step}
                                        defaultValue={setting.threshold}
                                        className="w-40"
                                    />
                                </Field>
                            )}
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                Save journal rules
                            </Button>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}

function Rule({
    name,
    checked,
    label,
    hint,
}: {
    name: string;
    checked: boolean;
    label: string;
    hint: string;
}) {
    return (
        <label className="flex items-start gap-2">
            <input type="hidden" name={name} value="0" />
            <input
                type="checkbox"
                name={name}
                value="1"
                defaultChecked={checked}
                className="mt-1"
            />
            <span>
                <span className="font-medium">{label}</span>
                <span className="block text-xs text-muted-foreground">
                    {hint}
                </span>
            </span>
        </label>
    );
}
