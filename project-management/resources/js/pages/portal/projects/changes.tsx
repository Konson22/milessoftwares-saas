import { Form, Link } from '@inertiajs/react';
import { GitPullRequestArrow, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { NoteAction, StepAction } from '@/components/note-action';
import { ProjectFrame } from '@/components/project-frame';
import { Status } from '@/components/status';
import { formatMoney } from '@/lib/money';
import { formatDate, formatDateTime, statusLabel } from '@/lib/status';
import { budget } from '@/routes/projects';
import {
    approve,
    reject,
    restartBudget,
    store,
    withdraw,
} from '@/routes/projects/changes';
import type { ProjectHeader } from '@/types';

type Change = {
    id: string;
    reference: string;
    title: string;
    description: string;
    status: string;
    proposedDueOn: string | null;
    previousDueOn: string | null;
    asksBudget: boolean;
    budgetChangeMinor: number | null;
    budgetVersion: { number: number; status: string } | null;
    raisedBy: string;
    raisedAt: string | null;
    decidedBy: string | null;
    decidedAt: string | null;
    decisionNote: string | null;
    canDecide: boolean;
    canWithdraw: boolean;
    canRestartBudget: boolean;
};

type Props = {
    project: ProjectHeader;
    changes: Change[];
    canRaise: boolean;
};

/**
 * Raising a request. It can't be changed afterwards, only withdrawn.
 */
function RaiseForm({
    project,
    onDone,
}: {
    project: ProjectHeader;
    onDone: () => void;
}) {
    return (
        <Form
            {...store.form({ project: project.id })}
            onSuccess={onDone}
            options={{ preserveScroll: true }}
            className="grid gap-3 sm:grid-cols-3"
        >
            {({ errors, processing }) => (
                <>
                    <Field
                        id="title"
                        label="Change"
                        error={errors.title}
                        className="sm:col-span-3"
                    >
                        <Input
                            id="title"
                            name="title"
                            required
                            maxLength={255}
                            placeholder="What should change"
                            {...invalid('title', errors.title)}
                        />
                    </Field>
                    <Field
                        id="description"
                        label="Why, and what it affects"
                        error={errors.description}
                        className="sm:col-span-3"
                    >
                        <textarea
                            id="description"
                            name="description"
                            rows={3}
                            required
                            maxLength={5000}
                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </Field>
                    <Field
                        id="proposed_due_on"
                        label="New due date (if it moves)"
                        error={errors.proposed_due_on}
                    >
                        <Input
                            id="proposed_due_on"
                            name="proposed_due_on"
                            type="date"
                            {...invalid(
                                'proposed_due_on',
                                errors.proposed_due_on,
                            )}
                        />
                    </Field>
                    {project.can.finance && (
                        <>
                            <Field
                                id="budget_direction"
                                label="Budget"
                                error={errors.budget_direction}
                            >
                                <Select
                                    id="budget_direction"
                                    name="budget_direction"
                                    defaultValue="increase"
                                    options={[
                                        {
                                            value: 'increase',
                                            label: 'Increase by',
                                        },
                                        {
                                            value: 'decrease',
                                            label: 'Decrease by',
                                        },
                                    ]}
                                />
                            </Field>
                            <Field
                                id="budget_change"
                                label={`Amount (${project.currency}, if it changes)`}
                                error={errors.budget_change}
                            >
                                <Input
                                    id="budget_change"
                                    name="budget_change"
                                    inputMode="decimal"
                                    {...invalid(
                                        'budget_change',
                                        errors.budget_change,
                                    )}
                                />
                            </Field>
                        </>
                    )}
                    {errors.change && (
                        <p
                            role="alert"
                            className="text-xs text-destructive sm:col-span-3"
                        >
                            {errors.change}
                        </p>
                    )}
                    <div className="flex gap-2 sm:col-span-3">
                        <Button type="submit" size="sm" disabled={processing}>
                            Raise request
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
 * One request: what it asks, how it was decided, what became of its budget.
 */
function Row({ project, change }: { project: ProjectHeader; change: Change }) {
    const route = { project: project.id, changeRequest: change.id };
    const amount = change.budgetChangeMinor;

    return (
        <li className="grid gap-2 p-4">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-muted-foreground">
                    {change.reference}
                </span>
                <span className="font-medium">{change.title}</span>
                <Status status={change.status} />
            </div>
            <p className="text-sm whitespace-pre-wrap">{change.description}</p>
            <ul className="grid gap-0.5 text-sm">
                {change.proposedDueOn && (
                    <li>
                        Due date: {formatDate(change.proposedDueOn)}
                        {change.previousDueOn &&
                            ` (was ${formatDate(change.previousDueOn)})`}
                    </li>
                )}
                {amount !== null && (
                    <li className="tabular-nums">
                        Budget: {amount > 0 ? 'increase' : 'decrease'} of{' '}
                        {formatMoney(Math.abs(amount), project.currency)}
                    </li>
                )}
                {amount === null && change.asksBudget && (
                    <li>Budget: asks for a change</li>
                )}
            </ul>
            <p className="text-xs text-muted-foreground">
                Raised by {change.raisedBy}
                {change.raisedAt && `, ${formatDateTime(change.raisedAt)}`}
            </p>
            {change.decidedAt && (
                <p className="text-xs text-muted-foreground">
                    {statusLabel(change.status)} by {change.decidedBy},{' '}
                    {formatDateTime(change.decidedAt)}
                    {change.decisionNote && `: ${change.decisionNote}`}
                </p>
            )}
            {change.budgetVersion && (
                <p className="text-sm">
                    Budget version {change.budgetVersion.number} (
                    {statusLabel(change.budgetVersion.status).toLowerCase()}
                    ):{' '}
                    <Link
                        href={budget({ project: project.id })}
                        className="underline"
                    >
                        open the budget
                    </Link>
                </p>
            )}
            {change.canRestartBudget && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                    <span>The budget draft it started was discarded.</span>
                    <StepAction
                        form={restartBudget.form(route)}
                        label="Start a new draft"
                    />
                </div>
            )}
            {(change.canDecide || change.canWithdraw) && (
                <div className="flex flex-wrap items-start gap-2">
                    {change.canDecide && (
                        <>
                            <NoteAction
                                form={approve.form(route)}
                                label="Approve"
                                prompt="A note for the record (optional)"
                                variant="default"
                            />
                            <NoteAction
                                form={reject.form(route)}
                                label="Reject"
                                prompt="Why it is rejected"
                                required
                            />
                        </>
                    )}
                    {change.canWithdraw && (
                        <StepAction
                            form={withdraw.form(route)}
                            label="Withdraw"
                            variant="ghost"
                        />
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * Requests to change the project. Approving moves the due date; a budget
 * change starts a draft version, approved in the budget's own steps.
 */
export default function Changes({ project, changes, canRaise }: Props) {
    const [raising, setRaising] = useState(false);

    return (
        <ProjectFrame project={project} title="Changes">
            <div className="grid gap-4">
                {raising && (
                    <Panel className="max-w-3xl">
                        <PanelHeader title="Raise a change request" />
                        <PanelBody>
                            <RaiseForm
                                project={project}
                                onDone={() => setRaising(false)}
                            />
                        </PanelBody>
                    </Panel>
                )}
                <Panel>
                    <PanelHeader
                        title="Change requests"
                        actions={
                            canRaise &&
                            !raising && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => setRaising(true)}
                                >
                                    <Plus /> Raise
                                </Button>
                            )
                        }
                    />
                    {changes.length === 0 ? (
                        <EmptyState
                            icon={GitPullRequestArrow}
                            title="No change requests yet"
                        />
                    ) : (
                        <ul className="divide-y">
                            {changes.map((change) => (
                                <Row
                                    key={change.id}
                                    project={project}
                                    change={change}
                                />
                            ))}
                        </ul>
                    )}
                </Panel>
            </div>
        </ProjectFrame>
    );
}
