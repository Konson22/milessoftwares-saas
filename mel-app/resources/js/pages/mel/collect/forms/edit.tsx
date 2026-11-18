import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import {
    CheckboxField,
    Field,
    SelectField,
    TextAreaField,
    TextField,
    selectClass,
} from '@/mel/components/fields';
import type { Option } from '@/mel/lib/mel';
import { store, update } from '@/routes/mel/collect/forms';

type Props = {
    form: {
        id: string;
        name: string;
        description: string | null;
        purpose: string;
        programmeId: string | null;
        unitId: string | null;
        containsPersonalData: boolean;
        linkedMilestoneId: string | null;
    } | null;
    defaults: { programmeId: string | null };
    /** Milestones of the projects linked to the form's programme (null: no programme yet). */
    milestones?: Option[] | null;
    programmes: (Option & { units: Option[] })[];
    purposes: Option[];
    programmeRequired: boolean;
};

export default function FormDetails({
    form,
    defaults,
    milestones,
    programmes,
    purposes,
    programmeRequired,
}: Props) {
    const [programmeId, setProgrammeId] = useState(
        form?.programmeId ?? defaults.programmeId ?? '',
    );
    const units =
        programmes.find((programme) => programme.value === programmeId)
            ?.units ?? [];
    const title = form ? `Details of ${form.name}` : 'New form';
    const action = form ? update.form(form.id) : store.form();

    return (
        <>
            <Head title={title} />
            <PageHeader title={title} />

            <Form {...action} className="grid max-w-3xl gap-3">
                {({ errors, processing }) => (
                    <>
                        <Panel>
                            <PanelHeader
                                title="The form"
                                description="The questions come next, in the form builder."
                            />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    name="name"
                                    label="Name"
                                    hint="As collectors see it on their phones"
                                    defaultValue={form?.name}
                                    error={errors.name}
                                    className="sm:col-span-2"
                                    required
                                />
                                <SelectField
                                    name="purpose"
                                    label="Used for"
                                    options={purposes}
                                    defaultValue={form?.purpose ?? 'survey'}
                                    error={errors.purpose}
                                />
                                <TextAreaField
                                    name="description"
                                    label="Description"
                                    className="sm:col-span-2"
                                    defaultValue={form?.description}
                                    error={errors.description}
                                />
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader
                                title="Programme"
                                description={
                                    programmeRequired
                                        ? 'The programme this form serves. Its people can see the form and what is collected with it.'
                                        : 'Optional. A form can serve a programme, or stand on its own.'
                                }
                            />
                            <PanelBody className="grid gap-4 sm:grid-cols-2">
                                <Field
                                    id="programme_id"
                                    label="Programme"
                                    error={errors.programme_id}
                                >
                                    <select
                                        id="programme_id"
                                        name="programme_id"
                                        value={programmeId}
                                        onChange={(event) =>
                                            setProgrammeId(event.target.value)
                                        }
                                        className={selectClass}
                                    >
                                        <option value="">
                                            {programmeRequired
                                                ? 'Choose…'
                                                : 'No programme'}
                                        </option>
                                        {programmes.map((programme) => (
                                            <option
                                                key={programme.value}
                                                value={programme.value}
                                            >
                                                {programme.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                {form !== null &&
                                    programmeId === form.programmeId &&
                                    milestones &&
                                    milestones.length > 0 && (
                                        <SelectField
                                            name="linked_milestone_id"
                                            label="Project milestone"
                                            options={milestones}
                                            empty="Not tied to a milestone"
                                            hint="The work in Tatook Projects this form monitors, such as rehabilitating water points. Completing surveys never marks the milestone reached."
                                            defaultValue={
                                                form.linkedMilestoneId
                                            }
                                            error={errors.linked_milestone_id}
                                            className="sm:col-span-2"
                                        />
                                    )}
                                {units.length > 0 && (
                                    <SelectField
                                        key={programmeId}
                                        name="programme_unit_id"
                                        label="Unit"
                                        options={units}
                                        empty="Any unit of the programme"
                                        hint="Only for a form used at one site, partner or project."
                                        defaultValue={
                                            form?.programmeId === programmeId
                                                ? form?.unitId
                                                : null
                                        }
                                        error={errors.programme_unit_id}
                                    />
                                )}
                            </PanelBody>
                        </Panel>

                        <Panel>
                            <PanelHeader title="Personal data" />
                            <PanelBody>
                                <CheckboxField
                                    name="contains_personal_data"
                                    label="Answers may identify people"
                                    defaultChecked={
                                        form?.containsPersonalData ?? true
                                    }
                                    hint="Names, phone numbers, photos or exact locations of respondents. Exports of this form are marked as holding personal data."
                                />
                            </PanelBody>
                        </Panel>

                        <div>
                            <Button type="submit" disabled={processing}>
                                {form
                                    ? 'Save details'
                                    : 'Create and add questions'}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
