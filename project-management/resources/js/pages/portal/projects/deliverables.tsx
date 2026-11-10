import { Form } from '@inertiajs/react';
import { PackageCheck, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { NoteAction, StepAction } from '@/components/note-action';
import { ProjectFrame } from '@/components/project-frame';
import { Status } from '@/components/status';
import { formatDate, formatDateTime, todayIso } from '@/lib/status';
import {
    accept,
    cancel,
    deliver,
    sendBack,
    store,
    update,
} from '@/routes/projects/deliverables';
import type { Option, ProjectHeader } from '@/types';

type Deliverable = {
    id: string;
    name: string;
    description: string | null;
    status: string;
    milestoneId: string | null;
    milestone: string | null;
    ownerId: string | null;
    owner: string | null;
    dueOn: string | null;
    deliveryNote: string | null;
    deliveredBy: string | null;
    deliveredAt: string | null;
    acceptedBy: string | null;
    acceptedAt: string | null;
    returnedReason: string | null;
};

type Props = {
    project: ProjectHeader;
    deliverables: Deliverable[];
    milestones: Option[];
    people: Option[];
};

/**
 * Adding a deliverable, or changing one.
 */
function DeliverableForm({
    projectId,
    deliverable,
    milestones,
    people,
    onDone,
}: {
    projectId: string;
    deliverable?: Deliverable;
    milestones: Option[];
    people: Option[];
    onDone: () => void;
}) {
    const action = deliverable
        ? update.form({ project: projectId, deliverable: deliverable.id })
        : store.form({ project: projectId });
    const id = (name: string) => `${deliverable?.id ?? 'new'}-${name}`;

    return (
        <Form
            {...action}
            onSuccess={onDone}
            options={{ preserveScroll: true }}
            className="grid gap-3 sm:grid-cols-3"
        >
            {({ errors, processing }) => (
                <>
                    <Field
                        id={id('name')}
                        label="Deliverable"
                        error={errors.name}
                        className="sm:col-span-3"
                    >
                        <Input
                            id={id('name')}
                            name="name"
                            required
                            maxLength={255}
                            placeholder="What will be handed over"
                            defaultValue={deliverable?.name}
                            {...invalid(id('name'), errors.name)}
                        />
                    </Field>
                    <Field id={id('due_on')} label="Due" error={errors.due_on}>
                        <Input
                            id={id('due_on')}
                            name="due_on"
                            type="date"
                            defaultValue={deliverable?.dueOn ?? ''}
                            {...invalid(id('due_on'), errors.due_on)}
                        />
                    </Field>
                    <Field
                        id={id('owner_id')}
                        label="Owner"
                        error={errors.owner_id}
                    >
                        <Select
                            id={id('owner_id')}
                            name="owner_id"
                            defaultValue={deliverable?.ownerId ?? ''}
                            placeholder="Not yet chosen"
                            options={people}
                        />
                    </Field>
                    <Field
                        id={id('milestone_id')}
                        label="Milestone"
                        error={errors.milestone_id}
                    >
                        <Select
                            id={id('milestone_id')}
                            name="milestone_id"
                            defaultValue={deliverable?.milestoneId ?? ''}
                            placeholder="None"
                            options={milestones}
                        />
                    </Field>
                    <Field
                        id={id('description')}
                        label="Description"
                        error={errors.description}
                        className="sm:col-span-3"
                    >
                        <textarea
                            id={id('description')}
                            name="description"
                            rows={2}
                            maxLength={5000}
                            defaultValue={deliverable?.description ?? ''}
                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </Field>
                    {errors.deliverable && (
                        <p
                            role="alert"
                            className="text-xs text-destructive sm:col-span-3"
                        >
                            {errors.deliverable}
                        </p>
                    )}
                    <div className="flex gap-2 sm:col-span-3">
                        <Button type="submit" size="sm" disabled={processing}>
                            {deliverable ? 'Save' : 'Add deliverable'}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Close
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * One deliverable: where it stands, and the steps open to this member.
 */
function Row({
    project,
    deliverable,
    milestones,
    people,
}: {
    project: ProjectHeader;
    deliverable: Deliverable;
    milestones: Option[];
    people: Option[];
}) {
    const [editing, setEditing] = useState(false);
    const route = { project: project.id, deliverable: deliverable.id };
    const live =
        project.open &&
        (deliverable.status === 'planned' ||
            deliverable.status === 'delivered');
    const overdue =
        deliverable.status === 'planned' &&
        deliverable.dueOn !== null &&
        deliverable.dueOn < todayIso();

    if (editing) {
        return (
            <li className="p-4">
                <DeliverableForm
                    projectId={project.id}
                    deliverable={deliverable}
                    milestones={milestones}
                    people={people}
                    onDone={() => setEditing(false)}
                />
            </li>
        );
    }

    return (
        <li className="grid gap-2 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="font-medium">{deliverable.name}</span>
                <Status status={deliverable.status} />
                {overdue && (
                    <span className="text-xs text-destructive">Overdue</span>
                )}
            </div>
            <p className="text-xs text-muted-foreground">
                {[
                    deliverable.dueOn && `Due ${formatDate(deliverable.dueOn)}`,
                    deliverable.owner,
                    deliverable.milestone &&
                        `Milestone: ${deliverable.milestone}`,
                ]
                    .filter(Boolean)
                    .join(' · ')}
            </p>
            {deliverable.description && (
                <p className="text-sm whitespace-pre-wrap">
                    {deliverable.description}
                </p>
            )}
            {deliverable.returnedReason && (
                <p className="text-sm text-destructive">
                    Sent back: {deliverable.returnedReason}
                </p>
            )}
            {deliverable.deliveredAt && (
                <p className="text-xs text-muted-foreground">
                    Delivered by {deliverable.deliveredBy},{' '}
                    {formatDateTime(deliverable.deliveredAt)}
                    {deliverable.deliveryNote &&
                        `: ${deliverable.deliveryNote}`}
                </p>
            )}
            {deliverable.acceptedAt && (
                <p className="text-xs text-muted-foreground">
                    Accepted by {deliverable.acceptedBy},{' '}
                    {formatDateTime(deliverable.acceptedAt)}
                </p>
            )}
            {live && (
                <div className="flex flex-wrap items-start gap-2">
                    {deliverable.status === 'planned' && project.can.tasks && (
                        <NoteAction
                            form={deliver.form(route)}
                            label="Mark delivered"
                            prompt="What was handed over, and where (optional)"
                        />
                    )}
                    {deliverable.status === 'delivered' &&
                        project.can.manage && (
                            <>
                                <StepAction
                                    form={accept.form(route)}
                                    label="Accept"
                                    variant="default"
                                />
                                <NoteAction
                                    form={sendBack.form(route)}
                                    label="Send back"
                                    prompt="What still needs doing"
                                    required
                                />
                            </>
                        )}
                    {project.can.manage && (
                        <>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                onClick={() => setEditing(true)}
                            >
                                Edit
                            </Button>
                            <StepAction
                                form={cancel.form(route)}
                                label="Cancel it"
                                variant="ghost"
                            />
                        </>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * What the project promises to hand over, and whether it was accepted.
 */
export default function Deliverables({
    project,
    deliverables,
    milestones,
    people,
}: Props) {
    const [adding, setAdding] = useState(false);
    const canAdd = project.can.manage && project.open;

    return (
        <ProjectFrame project={project} title="Deliverables">
            <div className="grid gap-4">
                {adding && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Add a deliverable" />
                        <PanelBody>
                            <DeliverableForm
                                projectId={project.id}
                                milestones={milestones}
                                people={people}
                                onDone={() => setAdding(false)}
                            />
                        </PanelBody>
                    </Panel>
                )}
                <Panel>
                    <PanelHeader
                        title="Deliverables"
                        actions={
                            canAdd &&
                            !adding && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setAdding(true)}
                                >
                                    <Plus /> Add
                                </Button>
                            )
                        }
                    />
                    {deliverables.length === 0 ? (
                        <EmptyState
                            icon={PackageCheck}
                            title="No deliverables yet"
                        />
                    ) : (
                        <ul className="divide-y">
                            {deliverables.map((deliverable) => (
                                <Row
                                    key={deliverable.id}
                                    project={project}
                                    deliverable={deliverable}
                                    milestones={milestones}
                                    people={people}
                                />
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </ProjectFrame>
    );
}
