import { Form } from '@inertiajs/react';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, Select, invalid } from '@/components/field';
import { formatMoney } from '@/lib/money';
import { destroy, store } from '@/routes/admin/approval-policies';

export type ApprovalBand = {
    id: string;
    currency: string;
    minAmountMinor: number;
    approvalsRequired: number;
};

/**
 * Administration → Settings: how many people approve spending, by amount.
 */
export function ApprovalBands({
    bands,
    currency,
    maxApprovers,
}: {
    bands: ApprovalBand[];
    currency: string;
    maxApprovers: number;
}) {
    return (
        <Panel className="mt-4">
            <PanelHeader
                title="Spending approvals"
                description="Spending needs one approver unless a band says more. Each approver is a different person, never the requester. Requests already made keep the number they were made with."
            />
            <PanelBody className="grid gap-4">
                {bands.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No bands: every request needs one approver.
                    </p>
                ) : (
                    <ul className="grid gap-2 text-sm">
                        {bands.map((band) => (
                            <li
                                key={band.id}
                                className="flex flex-wrap items-center justify-between gap-2 border-b pb-2 last:border-b-0"
                            >
                                <span>
                                    From{' '}
                                    {formatMoney(
                                        band.minAmountMinor,
                                        band.currency,
                                    )}
                                    :{' '}
                                    {band.approvalsRequired === 1
                                        ? '1 approver'
                                        : `${band.approvalsRequired} approvers`}
                                </span>
                                <Form
                                    {...destroy.form(band.id)}
                                    options={{ preserveScroll: true }}
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="ghost"
                                            size="sm"
                                            disabled={processing}
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </Form>
                            </li>
                        ))}
                    </ul>
                )}

                <Form
                    {...store.form()}
                    resetOnSuccess={['min_amount']}
                    options={{ preserveScroll: true }}
                    className="grid gap-4 sm:grid-cols-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <Field
                                id="band_currency"
                                label="Currency"
                                error={errors.currency}
                            >
                                <Input
                                    id="band_currency"
                                    name="currency"
                                    required
                                    maxLength={3}
                                    className="uppercase"
                                    defaultValue={currency}
                                    {...invalid(
                                        'band_currency',
                                        errors.currency,
                                    )}
                                />
                            </Field>
                            <Field
                                id="band_min_amount"
                                label="From amount"
                                error={errors.min_amount}
                            >
                                <Input
                                    id="band_min_amount"
                                    name="min_amount"
                                    inputMode="decimal"
                                    required
                                    placeholder="5000"
                                    {...invalid(
                                        'band_min_amount',
                                        errors.min_amount,
                                    )}
                                />
                            </Field>
                            <Field
                                id="band_approvals"
                                label="Approvers"
                                error={errors.approvals_required}
                            >
                                <Select
                                    id="band_approvals"
                                    name="approvals_required"
                                    defaultValue="2"
                                    options={Array.from(
                                        { length: maxApprovers },
                                        (_, index) => ({
                                            value: String(index + 1),
                                            label: String(index + 1),
                                        }),
                                    )}
                                />
                            </Field>
                            <div className="flex items-end">
                                <Button type="submit" disabled={processing}>
                                    Add band
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </PanelBody>
        </Panel>
    );
}
