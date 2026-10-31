import { Form, Head, Link } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { index, show, store, update } from '@/routes/projects';
import type { Option } from '@/types';

type Project = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    client: string | null;
    managerId: string | null;
    startOn: string | null;
    dueOn: string | null;
    budget: string;
    hasBudgetLines: boolean;
};

/**
 * Setting up a project, or changing its details.
 */
export default function ProjectForm({
    project,
    people,
}: {
    project: Project | null;
    people: Option[];
}) {
    const action = project ? update.form(project.id) : store.form();
    const title = project ? `${project.code} ${project.name}` : 'New project';

    return (
        <>
            <Head title={title} />
            <PageHeader
                title={title}
                actions={
                    <Button variant="outline" size="sm" asChild>
                        <Link href={project ? show(project.id) : index()}>
                            ← {project ? project.code : 'Projects'}
                        </Link>
                    </Button>
                }
            />

            <Panel className="max-w-3xl">
                <PanelBody>
                    <Form {...action} className="grid gap-4 sm:grid-cols-3">
                        {({ errors, processing }) => (
                            <>
                                <Field
                                    id="code"
                                    label="Code"
                                    error={errors.code}
                                    hint="Starts every task number: WEB-12."
                                >
                                    <Input
                                        id="code"
                                        name="code"
                                        required
                                        maxLength={10}
                                        className="uppercase"
                                        defaultValue={project?.code}
                                        {...invalid('code', errors.code)}
                                    />
                                </Field>
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
                                        defaultValue={project?.name}
                                        {...invalid('name', errors.name)}
                                    />
                                </Field>
                                <Field
                                    id="description"
                                    label="Goal and scope"
                                    error={errors.description}
                                    className="sm:col-span-3"
                                >
                                    <textarea
                                        id="description"
                                        name="description"
                                        rows={4}
                                        defaultValue={
                                            project?.description ?? ''
                                        }
                                        className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                                    />
                                </Field>
                                <Field
                                    id="client"
                                    label="Client or sponsor"
                                    error={errors.client}
                                >
                                    <Input
                                        id="client"
                                        name="client"
                                        defaultValue={project?.client ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="manager_id"
                                    label="Project manager"
                                    error={errors.manager_id}
                                    className="sm:col-span-2"
                                >
                                    <Select
                                        id="manager_id"
                                        name="manager_id"
                                        defaultValue={project?.managerId ?? ''}
                                        placeholder="Not yet chosen"
                                        options={people}
                                    />
                                </Field>
                                <Field
                                    id="start_on"
                                    label="Start"
                                    error={errors.start_on}
                                >
                                    <Input
                                        id="start_on"
                                        name="start_on"
                                        type="date"
                                        defaultValue={project?.startOn ?? ''}
                                    />
                                </Field>
                                <Field
                                    id="due_on"
                                    label="Due"
                                    error={errors.due_on}
                                >
                                    <Input
                                        id="due_on"
                                        name="due_on"
                                        type="date"
                                        defaultValue={project?.dueOn ?? ''}
                                        {...invalid('due_on', errors.due_on)}
                                    />
                                </Field>
                                <Field
                                    id="budget"
                                    label="Budget"
                                    error={errors.budget}
                                    hint={
                                        project?.hasBudgetLines
                                            ? 'The sum of the budget lines.'
                                            : 'Or break it down into lines later.'
                                    }
                                >
                                    <Input
                                        id="budget"
                                        name="budget"
                                        inputMode="decimal"
                                        readOnly={project?.hasBudgetLines}
                                        defaultValue={project?.budget ?? ''}
                                        {...invalid('budget', errors.budget)}
                                    />
                                </Field>
                                <div className="sm:col-span-3">
                                    <Button type="submit" disabled={processing}>
                                        {project ? 'Save' : 'Set up project'}
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
