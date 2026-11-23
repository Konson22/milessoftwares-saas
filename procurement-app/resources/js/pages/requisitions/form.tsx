import { Head, Link, useForm } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { SelectField, TextArea, TextField } from '@/components/fields';
import type { EditableLine } from '@/components/lines-editor';
import { blankLine, LinesEditor } from '@/components/lines-editor';
import type { Option } from '@/lib/procurement';
import { index, show, store, update } from '@/routes/requisitions';

type Props = {
    requisition: {
        id: string;
        number: string | null;
        title: string;
        justification: string;
        department_id: string;
        plan_item_id: string;
        needed_by: string;
        delivery_location: string;
        currency: string;
        budget_reference: string;
        project_reference: string;
        funding_reference: string;
        status: string;
        statusReason: string | null;
        lines: EditableLine[];
    } | null;
    departments: Option[];
    planItems: Option[];
    requirePlan: boolean;
    currencies: string[];
};

export default function RequisitionForm({
    requisition,
    departments,
    planItems,
    requirePlan,
    currencies,
}: Props) {
    const form = useForm({
        title: requisition?.title ?? '',
        justification: requisition?.justification ?? '',
        department_id: requisition?.department_id ?? '',
        plan_item_id: requisition?.plan_item_id ?? '',
        needed_by: requisition?.needed_by ?? '',
        delivery_location: requisition?.delivery_location ?? '',
        currency: requisition?.currency ?? currencies[0] ?? '',
        budget_reference: requisition?.budget_reference ?? '',
        project_reference: requisition?.project_reference ?? '',
        funding_reference: requisition?.funding_reference ?? '',
        lines: requisition?.lines.length ? requisition.lines : [blankLine()],
        submit: false,
    });
    const errors = form.errors as Record<string, string>;

    const save = (submit: boolean) => {
        form.transform((data) => ({ ...data, submit }));
        if (requisition) {
            form.put(update(requisition.id).url);
        } else {
            form.post(store().url);
        }
    };

    return (
        <>
            <Head
                title={requisition ? 'Edit requisition' : 'New requisition'}
            />
            <PageHeader
                title={
                    requisition
                        ? `Edit ${requisition.number ?? 'draft'}`
                        : 'New requisition'
                }
            />
            <form
                className="grid gap-6"
                onSubmit={(e) => {
                    e.preventDefault();
                    save(true);
                }}
            >
                {requisition?.status === 'returned' &&
                    requisition.statusReason && (
                        <div className="rounded-md border border-amber-500 bg-background px-3 py-2 text-sm">
                            Returned for changes: {requisition.statusReason}
                        </div>
                    )}
                <Panel>
                    <PanelHeader title="What is needed, and why" />
                    <PanelBody className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label="Title"
                            value={form.data.title}
                            onChange={(v) => form.setData('title', v)}
                            error={errors.title}
                            className="sm:col-span-2"
                        />
                        <TextArea
                            label="Justification"
                            value={form.data.justification}
                            onChange={(v) => form.setData('justification', v)}
                            error={errors.justification}
                            className="sm:col-span-2"
                        />
                        <SelectField
                            label="Department"
                            value={form.data.department_id}
                            onChange={(v) => form.setData('department_id', v)}
                            options={departments}
                            empty="No department"
                            error={errors.department_id}
                        />
                        <SelectField
                            label={
                                requirePlan
                                    ? 'Plan item (required)'
                                    : 'Plan item'
                            }
                            value={form.data.plan_item_id}
                            onChange={(v) => form.setData('plan_item_id', v)}
                            options={planItems}
                            empty="Not in a plan"
                            error={errors.plan_item_id}
                        />
                        <TextField
                            label="Needed by"
                            type="date"
                            value={form.data.needed_by}
                            onChange={(v) => form.setData('needed_by', v)}
                            error={errors.needed_by}
                        />
                        <TextField
                            label="Deliver to"
                            value={form.data.delivery_location}
                            onChange={(v) =>
                                form.setData('delivery_location', v)
                            }
                            error={errors.delivery_location}
                        />
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader title="Lines" />
                    <PanelBody className="grid gap-4">
                        <SelectField
                            label="Currency of the estimate"
                            value={form.data.currency}
                            onChange={(v) => form.setData('currency', v)}
                            options={currencies.map((c) => ({
                                value: c,
                                label: c,
                            }))}
                            error={errors.currency}
                            className="w-48"
                        />
                        <LinesEditor
                            lines={form.data.lines}
                            onChange={(lines) => form.setData('lines', lines)}
                            errors={errors}
                            currency={form.data.currency}
                        />
                    </PanelBody>
                </Panel>

                <Panel>
                    <PanelHeader
                        title="References"
                        description="Kept as written. They link to nothing unless another application is connected."
                    />
                    <PanelBody className="grid gap-4 sm:grid-cols-3">
                        <TextField
                            label="Budget"
                            value={form.data.budget_reference}
                            onChange={(v) =>
                                form.setData('budget_reference', v)
                            }
                            error={errors.budget_reference}
                        />
                        <TextField
                            label="Project"
                            value={form.data.project_reference}
                            onChange={(v) =>
                                form.setData('project_reference', v)
                            }
                            error={errors.project_reference}
                        />
                        <TextField
                            label="Funding source"
                            value={form.data.funding_reference}
                            onChange={(v) =>
                                form.setData('funding_reference', v)
                            }
                            error={errors.funding_reference}
                        />
                    </PanelBody>
                </Panel>

                <div className="flex flex-wrap gap-2">
                    <Button type="submit" disabled={form.processing}>
                        Submit for approval
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => save(false)}
                    >
                        Save draft
                    </Button>
                    <Button type="button" variant="outline" asChild>
                        <Link
                            href={requisition ? show(requisition.id) : index()}
                        >
                            Cancel
                        </Link>
                    </Button>
                </div>
            </form>
        </>
    );
}
