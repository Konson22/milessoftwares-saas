import { Form } from '@inertiajs/react';
import { Plus, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { Field, invalid, Select } from '@/components/field';
import { ProjectFrame } from '@/components/project-frame';
import { Status } from '@/components/status';
import { formatDate, statusLabel, todayIso } from '@/lib/status';
import { store, update } from '@/routes/projects/risks';
import type { Option, ProjectHeader } from '@/types';

type Risk = {
    id: string;
    title: string;
    description: string | null;
    probability: number;
    impact: number;
    score: number;
    level: string;
    status: string;
    ownerId: string | null;
    owner: string | null;
    mitigation: string | null;
    reviewOn: string | null;
};

type Props = { project: ProjectHeader; risks: Risk[]; people: Option[] };

const LEVEL_TONE = {
    high: 'danger',
    medium: 'warning',
    low: 'neutral',
} as const;

const SCALE: Option[] = [
    { value: '1', label: '1 · Very low' },
    { value: '2', label: '2 · Low' },
    { value: '3', label: '3 · Medium' },
    { value: '4', label: '4 · High' },
    { value: '5', label: '5 · Very high' },
];

/**
 * Raising a risk, or changing one (with its status).
 */
function RiskForm({
    projectId,
    risk,
    people,
    onDone,
}: {
    projectId: string;
    risk?: Risk;
    people: Option[];
    onDone: () => void;
}) {
    const action = risk
        ? update.form({ project: projectId, risk: risk.id })
        : store.form(projectId);
    const id = (name: string) => `${risk?.id ?? 'new'}-${name}`;

    return (
        <Form
            {...action}
            onSuccess={onDone}
            options={{ preserveScroll: true }}
            className="grid gap-3 sm:grid-cols-4"
        >
            {({ errors, processing }) => (
                <>
                    <Field
                        id={id('title')}
                        label="Risk"
                        error={errors.title}
                        className="sm:col-span-4"
                    >
                        <Input
                            id={id('title')}
                            name="title"
                            required
                            placeholder="What could go wrong"
                            defaultValue={risk?.title}
                            {...invalid(id('title'), errors.title)}
                        />
                    </Field>
                    <Field
                        id={id('probability')}
                        label="Probability"
                        error={errors.probability}
                    >
                        <Select
                            id={id('probability')}
                            name="probability"
                            defaultValue={String(risk?.probability ?? 3)}
                            options={SCALE}
                        />
                    </Field>
                    <Field
                        id={id('impact')}
                        label="Impact"
                        error={errors.impact}
                    >
                        <Select
                            id={id('impact')}
                            name="impact"
                            defaultValue={String(risk?.impact ?? 3)}
                            options={SCALE}
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
                            defaultValue={risk?.ownerId ?? ''}
                            placeholder="Nobody yet"
                            options={people}
                        />
                    </Field>
                    <Field
                        id={id('review_on')}
                        label="Review on"
                        error={errors.review_on}
                    >
                        <Input
                            id={id('review_on')}
                            name="review_on"
                            type="date"
                            defaultValue={risk?.reviewOn ?? ''}
                        />
                    </Field>
                    <Field
                        id={id('description')}
                        label="Cause and effect"
                        error={errors.description}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id={id('description')}
                            name="description"
                            rows={3}
                            defaultValue={risk?.description ?? ''}
                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </Field>
                    <Field
                        id={id('mitigation')}
                        label="Mitigation"
                        error={errors.mitigation}
                        className="sm:col-span-2"
                    >
                        <textarea
                            id={id('mitigation')}
                            name="mitigation"
                            rows={3}
                            defaultValue={risk?.mitigation ?? ''}
                            className="w-full border border-input bg-card px-2.5 py-1.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50"
                        />
                    </Field>
                    {risk && (
                        <Field
                            id={id('status')}
                            label="Status"
                            error={errors.status}
                        >
                            <Select
                                id={id('status')}
                                name="status"
                                defaultValue={risk.status}
                                options={['open', 'mitigating', 'closed'].map(
                                    (value) => ({
                                        value,
                                        label: statusLabel(value),
                                    }),
                                )}
                            />
                        </Field>
                    )}
                    <div className="flex items-end gap-2 sm:col-span-4">
                        <Button type="submit" disabled={processing}>
                            {risk ? 'Save risk' : 'Add risk'}
                        </Button>
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/**
 * The risk register: probability × impact, highest first, closed last.
 */
export default function Risks({ project, risks, people }: Props) {
    const manage = project.can.manage;
    const [editing, setEditing] = useState<string | null>(null);
    const today = todayIso();
    const add = manage ? (
        <Button onClick={() => setEditing('new')}>
            <Plus className="size-4" aria-hidden />
            Raise a risk
        </Button>
    ) : undefined;

    return (
        <ProjectFrame project={project} title="Risks" actions={add}>
            {editing === 'new' && (
                <Panel className="mb-4">
                    <PanelHeader title="New risk" />
                    <PanelBody>
                        <RiskForm
                            projectId={project.id}
                            people={people}
                            onDone={() => setEditing(null)}
                        />
                    </PanelBody>
                </Panel>
            )}

            <Panel>
                <PanelHeader
                    title="Register"
                    description="Score = probability × impact (1–25): 15+ high, 8+ medium."
                />
                {risks.length === 0 ? (
                    <EmptyState
                        icon={ShieldAlert}
                        title="No risks raised"
                        action={add}
                    />
                ) : (
                    <ul className="divide-y">
                        {risks.map((risk) =>
                            editing === risk.id ? (
                                <li key={risk.id} className="p-3">
                                    <RiskForm
                                        projectId={project.id}
                                        risk={risk}
                                        people={people}
                                        onDone={() => setEditing(null)}
                                    />
                                </li>
                            ) : (
                                <li
                                    key={risk.id}
                                    className="flex flex-wrap items-start gap-3 px-3 py-3 text-sm"
                                >
                                    <StatusBadge
                                        tone={
                                            risk.status === 'closed'
                                                ? 'neutral'
                                                : (LEVEL_TONE[
                                                      risk.level as keyof typeof LEVEL_TONE
                                                  ] ?? 'neutral')
                                        }
                                        className="w-10 justify-center tabular-nums"
                                    >
                                        {risk.score}
                                    </StatusBadge>
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={
                                                risk.status === 'closed'
                                                    ? 'text-muted-foreground line-through'
                                                    : 'font-medium'
                                            }
                                        >
                                            {risk.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            Probability {risk.probability} ·
                                            Impact {risk.impact}
                                            {risk.owner && ` · ${risk.owner}`}
                                            {risk.reviewOn && (
                                                <span
                                                    className={
                                                        risk.status !==
                                                            'closed' &&
                                                        risk.reviewOn < today
                                                            ? 'font-medium text-destructive'
                                                            : undefined
                                                    }
                                                >
                                                    {' · '}Review{' '}
                                                    {formatDate(risk.reviewOn)}
                                                </span>
                                            )}
                                        </p>
                                        {risk.description && (
                                            <p className="mt-1 whitespace-pre-line">
                                                {risk.description}
                                            </p>
                                        )}
                                        {risk.mitigation && (
                                            <p className="mt-1 whitespace-pre-line text-muted-foreground">
                                                <span className="font-medium text-foreground">
                                                    Mitigation:
                                                </span>{' '}
                                                {risk.mitigation}
                                            </p>
                                        )}
                                    </div>
                                    <Status status={risk.status} />
                                    {manage && (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => setEditing(risk.id)}
                                        >
                                            Edit
                                        </Button>
                                    )}
                                </li>
                            ),
                        )}
                    </ul>
                )}
            </Panel>
        </ProjectFrame>
    );
}
