import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    Field,
    SelectField,
    TextAreaField,
    TextField,
    errorFor,
    selectClass,
} from '@/components/fields';
import type { Option } from '@/lib/mel';
import { index, show, store, update } from '@/routes/programmes';

type Props = {
    programme: {
        id: string;
        code: string;
        name: string;
        status: string;
        startsOn: string;
        endsOn: string;
        archived: boolean;
        objective: string | null;
        description: string | null;
        currency: string;
        budget: string | null;
        fundingSummary: string | null;
        managerId: string | null;
        locationIds: string[];
    } | null;
    members: Option[];
    locations: Option[];
    statuses: Option[];
    defaultCurrency: string;
};

export default function ProgrammeForm({
    programme,
    members,
    locations,
    statuses,
    defaultCurrency,
}: Props) {
    const title = programme ? `Edit ${programme.code}` : 'New programme';
    const action = programme ? update.form(programme.id) : store.form();

    return (
        <>
            <Head title={title} />
            <PageHeader title={title} />

            <Form {...action} className="grid max-w-3xl gap-3">
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader title="The programme" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    name="code"
                                    label="Code"
                                    hint="Short, used in reports: WASH-2026"
                                    defaultValue={programme?.code}
                                    error={errors.code}
                                    required
                                />
                                <TextField
                                    name="name"
                                    label="Name"
                                    defaultValue={programme?.name}
                                    error={errors.name}
                                    required
                                />
                                <TextField
                                    name="starts_on"
                                    label="Starts"
                                    type="date"
                                    defaultValue={programme?.startsOn}
                                    error={errors.starts_on}
                                    required
                                />
                                <TextField
                                    name="ends_on"
                                    label="Ends"
                                    type="date"
                                    defaultValue={programme?.endsOn}
                                    error={errors.ends_on}
                                    required
                                />
                                {programme && (
                                    <SelectField
                                        name="status"
                                        label="Status"
                                        options={statuses}
                                        defaultValue={programme.status}
                                        error={errors.status}
                                    />
                                )}
                                <SelectField
                                    name="manager_id"
                                    label="Programme manager"
                                    options={members}
                                    empty="Nobody yet"
                                    hint="People appear here once they have opened M&E."
                                    defaultValue={programme?.managerId}
                                    error={errors.manager_id}
                                />
                                <TextAreaField
                                    name="objective"
                                    label="Objective"
                                    className="sm:col-span-2"
                                    defaultValue={programme?.objective}
                                    error={errors.objective}
                                />
                                <TextAreaField
                                    name="description"
                                    label="Description"
                                    className="sm:col-span-2"
                                    rows={4}
                                    defaultValue={programme?.description}
                                    error={errors.description}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Funding and places" />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    name="currency"
                                    label="Currency"
                                    defaultValue={
                                        programme?.currency ?? defaultCurrency
                                    }
                                    error={errors.currency}
                                    required
                                />
                                <TextField
                                    name="budget"
                                    label="Budget"
                                    inputMode="decimal"
                                    defaultValue={programme?.budget}
                                    error={errors.budget}
                                />
                                <TextAreaField
                                    name="funding_summary"
                                    label="Funding"
                                    hint="Donors, grants and agreements, in a few lines."
                                    className="sm:col-span-2"
                                    defaultValue={programme?.fundingSummary}
                                    error={errors.funding_summary}
                                />
                                <Field
                                    id="location_ids"
                                    label="Locations"
                                    hint={
                                        locations.length === 0
                                            ? 'Add locations in Settings to choose them here.'
                                            : 'Hold Ctrl (or ⌘) to choose several.'
                                    }
                                    error={errorFor(errors, 'location_ids')}
                                    className="sm:col-span-2"
                                >
                                    <select
                                        id="location_ids"
                                        name="location_ids[]"
                                        multiple
                                        defaultValue={
                                            programme?.locationIds ?? []
                                        }
                                        className={`${selectClass} h-32 py-1`}
                                    >
                                        {locations.map((location) => (
                                            <option
                                                key={location.value}
                                                value={location.value}
                                            >
                                                {location.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </PanelBody>
                        </Panel>

                        <div className="flex gap-2">
                            <Button type="submit" disabled={processing}>
                                {programme ? 'Save' : 'Create programme'}
                            </Button>
                            <Button variant="outline" asChild>
                                <Link
                                    href={
                                        programme ? show(programme.id) : index()
                                    }
                                >
                                    Cancel
                                </Link>
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
