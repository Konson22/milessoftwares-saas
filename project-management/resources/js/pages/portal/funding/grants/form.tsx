import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { create as createFunder } from '@/routes/funders';
import { index, show, store, update } from '@/routes/grants';
import type { Option } from '@/types';

type Grant = {
    id: string;
    funderId: string;
    reference: string;
    title: string;
    description: string | null;
    status: string;
    pipeline: boolean;
    currency: string;
    amount: string;
    restricted: boolean;
    startOn: string | null;
    endOn: string | null;
};

/**
 * Recording a grant (an opportunity or an application), or changing its
 * details. Once awarded, its amount and end date change by amendment, on
 * the grant's page.
 */
export default function GrantForm({
    grant,
    funders,
    funderId = null,
    currency,
}: {
    grant: Grant | null;
    funders: Option[];
    /** Chosen when coming from a connected donor. */
    funderId?: string | null;
    currency: string;
}) {
    const action = grant ? update.form({ grant: grant.id }) : store.form();
    const title = grant ? grant.reference : 'Record a grant';
    const pipeline = grant?.pipeline ?? true;

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                description={grant?.title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link
                            href={grant ? show({ grant: grant.id }) : index()}
                        >
                            ← {grant ? 'Grant' : 'Grants'}
                        </Link>
                    </Button>
                }
            />

            {funders.length === 0 ? (
                <Panel className="max-w-2xl">
                    <PanelBody className="text-sm">
                        Add the funder first.{' '}
                        <Link href={createFunder()} className="underline">
                            Add a funder
                        </Link>
                    </PanelBody>
                </Panel>
            ) : (
                <Panel className="max-w-3xl">
                    <PanelBody>
                        <Form {...action} className="grid gap-4 sm:grid-cols-3">
                            {({ errors, processing }) => (
                                <>
                                    <Field
                                        id="funder_id"
                                        label="Funder"
                                        error={errors.funder_id}
                                        className="sm:col-span-2"
                                    >
                                        <Select
                                            id="funder_id"
                                            name="funder_id"
                                            defaultValue={
                                                grant?.funderId ??
                                                funderId ??
                                                undefined
                                            }
                                            options={funders}
                                        />
                                    </Field>
                                    <Field
                                        id="reference"
                                        label="Reference"
                                        error={errors.reference}
                                        hint="The funder's or yours, e.g. ECHO/2026/041."
                                    >
                                        <Input
                                            id="reference"
                                            name="reference"
                                            required
                                            className="uppercase"
                                            defaultValue={grant?.reference}
                                            {...invalid(
                                                'reference',
                                                errors.reference,
                                            )}
                                        />
                                    </Field>
                                    <Field
                                        id="title"
                                        label="Title"
                                        error={errors.title}
                                        className="sm:col-span-3"
                                    >
                                        <Input
                                            id="title"
                                            name="title"
                                            required
                                            defaultValue={grant?.title}
                                            {...invalid('title', errors.title)}
                                        />
                                    </Field>
                                    <Field
                                        id="description"
                                        label="Purpose and conditions"
                                        error={errors.description}
                                        className="sm:col-span-3"
                                    >
                                        <textarea
                                            id="description"
                                            name="description"
                                            rows={4}
                                            defaultValue={
                                                grant?.description ?? ''
                                            }
                                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                                        />
                                    </Field>

                                    {pipeline ? (
                                        <>
                                            <Field
                                                id="currency"
                                                label="Currency"
                                                error={errors.currency}
                                            >
                                                <Input
                                                    id="currency"
                                                    name="currency"
                                                    required
                                                    maxLength={3}
                                                    className="uppercase"
                                                    defaultValue={
                                                        grant?.currency ??
                                                        currency
                                                    }
                                                    {...invalid(
                                                        'currency',
                                                        errors.currency,
                                                    )}
                                                />
                                            </Field>
                                            <Field
                                                id="amount"
                                                label={
                                                    grant
                                                        ? 'Amount requested'
                                                        : 'Amount (requested)'
                                                }
                                                error={errors.amount}
                                                className="sm:col-span-2"
                                            >
                                                <Input
                                                    id="amount"
                                                    name="amount"
                                                    inputMode="decimal"
                                                    required
                                                    defaultValue={grant?.amount}
                                                    {...invalid(
                                                        'amount',
                                                        errors.amount,
                                                    )}
                                                />
                                            </Field>
                                        </>
                                    ) : (
                                        <p className="text-sm text-muted-foreground sm:col-span-3">
                                            Awarded: its amount (
                                            {grant?.currency} {grant?.amount})
                                            and end date change by an amendment,
                                            on the grant's page.
                                        </p>
                                    )}

                                    <Field
                                        id="start_on"
                                        label="Starts"
                                        error={errors.start_on}
                                    >
                                        <Input
                                            id="start_on"
                                            name="start_on"
                                            type="date"
                                            defaultValue={grant?.startOn ?? ''}
                                        />
                                    </Field>
                                    {pipeline && (
                                        <Field
                                            id="end_on"
                                            label="Ends"
                                            error={errors.end_on}
                                        >
                                            <Input
                                                id="end_on"
                                                name="end_on"
                                                type="date"
                                                defaultValue={
                                                    grant?.endOn ?? ''
                                                }
                                                {...invalid(
                                                    'end_on',
                                                    errors.end_on,
                                                )}
                                            />
                                        </Field>
                                    )}
                                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                                        <input
                                            type="checkbox"
                                            name="restricted"
                                            value="1"
                                            defaultChecked={
                                                grant?.restricted ?? true
                                            }
                                        />
                                        Restricted funding
                                    </label>

                                    {!grant && (
                                        <Field
                                            id="status"
                                            label="Stage"
                                            error={errors.status}
                                            className="sm:col-span-3"
                                        >
                                            <Select
                                                id="status"
                                                name="status"
                                                defaultValue="opportunity"
                                                options={[
                                                    {
                                                        value: 'opportunity',
                                                        label: 'Opportunity (not applied yet)',
                                                    },
                                                    {
                                                        value: 'applied',
                                                        label: 'Applied (application sent today)',
                                                    },
                                                ]}
                                            />
                                        </Field>
                                    )}

                                    <div className="sm:col-span-3">
                                        <Button
                                            type="submit"
                                            disabled={processing}
                                        >
                                            {grant
                                                ? 'Save'
                                                : 'Record the grant'}
                                        </Button>
                                    </div>
                                </>
                            )}
                        </Form>
                    </PanelBody>
                </Panel>
            )}
        </>
    );
}
