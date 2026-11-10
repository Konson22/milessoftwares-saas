import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { index, store, update } from '@/routes/funders';
import type { Option } from '@/types';

type Funder = {
    id: string;
    name: string;
    kind: string;
    countryCode: string | null;
    contactName: string | null;
    contactEmail: string | null;
    website: string | null;
    notes: string | null;
    active: boolean;
};

/**
 * Adding a funder, or changing one.
 */
export default function FunderForm({
    funder,
    kinds,
}: {
    funder: Funder | null;
    kinds: Option[];
}) {
    const action = funder ? update.form({ funder: funder.id }) : store.form();
    const title = funder ? funder.name : 'Add a funder';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={index()}>← Funders</Link>
                    </Button>
                }
            />

            <Panel className="max-w-2xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-2">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="name"
                                    label="Name"
                                    error={errors.name}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        defaultValue={funder?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="kind"
                                    label="Kind"
                                    error={errors.kind}
                                >
                                    <Select
                                        id="kind"
                                        name="kind"
                                        defaultValue={
                                            funder?.kind ?? 'institutional'
                                        }
                                        options={kinds}
                                    />
                                </Field>
                                <Field
                                    id="country_code"
                                    label="Country code"
                                    error={errors.country_code}
                                    hint="Two letters, e.g. BE, US. Optional."
                                >
                                    <Input
                                        id="country_code"
                                        name="country_code"
                                        maxLength={2}
                                        className="uppercase"
                                        defaultValue={funder?.countryCode ?? ''}
                                        {...invalid(
                                            'country_code',
                                            errors.country_code,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="contact_name"
                                    label="Contact"
                                    error={errors.contact_name}
                                >
                                    <Input
                                        id="contact_name"
                                        name="contact_name"
                                        defaultValue={funder?.contactName ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="contact_email"
                                    label="Contact email"
                                    error={errors.contact_email}
                                >
                                    <Input
                                        id="contact_email"
                                        name="contact_email"
                                        type="email"
                                        defaultValue={
                                            funder?.contactEmail ?? ''
                                        }
                                        {...invalid(
                                            'contact_email',
                                            errors.contact_email,
                                        )}
                                    />
                                </Field>
                                <Field
                                    id="website"
                                    label="Website"
                                    error={errors.website}
                                    className="sm:col-span-2"
                                >
                                    <Input
                                        id="website"
                                        name="website"
                                        type="url"
                                        placeholder="https://"
                                        defaultValue={funder?.website ?? ''}
                                        {...invalid('website', errors.website)}
                                    />
                                </Field>
                                <Field
                                    id="notes"
                                    label="Notes"
                                    error={errors.notes}
                                    className="sm:col-span-2"
                                >
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        rows={3}
                                        defaultValue={funder?.notes ?? ''}
                                        className="w-full border border-input bg-card px-2 py-1 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                                    />
                                </Field>
                                {funder && (
                                    <label className="flex items-center gap-2 text-sm sm:col-span-2">
                                        <input
                                            type="checkbox"
                                            name="active"
                                            value="1"
                                            defaultChecked={funder.active}
                                        />
                                        Active (offered for new grants)
                                    </label>
                                )}
                                <div className="sm:col-span-2">
                                    <Button type="submit" disabled={processing}>
                                        {funder ? 'Save' : 'Add the funder'}
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                </PanelBody>
            </Panel>
        </>
    );
}
