import { Form, Head, Link } from '@inertiajs/react';
import { FileText, ListTree, Plus } from 'lucide-react';
import { useState } from 'react';
import { EmptyState } from '@miles/ui/components/empty-state';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    CheckboxField,
    Field,
    SelectField,
    TextAreaField,
    TextField,
    errorFor,
} from '@/mel/components/fields';
import { formatBytes, formatDate } from '@/mel/lib/mel';
import type { Option } from '@/mel/lib/mel';
import { destroy as removeFile, download } from '@/routes/mel/evidence';
import { levels as saveLevels, show } from '@/routes/mel/frameworks';
import {
    create as newIndicator,
    show as indicator,
} from '@/routes/mel/indicators';
import { show as programmeShow } from '@/routes/mel/programmes';
import {
    archive,
    documents,
    store as addResult,
    update as updateResult,
} from '@/routes/mel/results';

type Level = { id: string; depth: number; name: string };

type Result = {
    id: string;
    levelId: string;
    parentId: string | null;
    code: string;
    statement: string;
    description: string | null;
    assumptions: string | null;
    risks: string | null;
    meansOfVerification: string | null;
    responsibleId: string | null;
    responsible: string | null;
    linkedMilestoneId: string | null;
    milestone: {
        name: string;
        project: string;
        dueOn: string | null;
        reachedOn: string | null;
    } | null;
    archived: boolean;
    indicators: {
        id: string;
        code: string;
        name: string;
        status: string;
        meansOfVerification: string | null;
    }[];
    documents: { id: string; name: string; size: number }[];
};

type Props = {
    programme: { id: string; code: string; name: string; archived: boolean };
    levels: Level[];
    results: Result[];
    showArchived: boolean;
    options: { members: Option[]; milestones: Option[] } | null;
    can: { manage: boolean };
};

export default function Framework({
    programme,
    levels,
    results,
    showArchived,
    options,
    can,
}: Props) {
    const [adding, setAdding] = useState<{ parentId: string | null } | null>(
        null,
    );
    const [editingLevels, setEditingLevels] = useState(false);
    const depthOf = (levelId: string) =>
        levels.find((level) => level.id === levelId)?.depth ?? 1;
    const levelName = (levelId: string) =>
        levels.find((level) => level.id === levelId)?.name ?? '';

    return (
        <>
            <Head title={`Framework · ${programme.code}`} />
            <PageHeader
                title={
                    <>
                        <Link
                            href={programmeShow(programme.id)}
                            className="text-muted-foreground hover:underline"
                        >
                            {programme.code}
                        </Link>{' '}
                        Results framework
                    </>
                }
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <Link
                                href={show(programme.id, {
                                    query: showArchived ? {} : { archived: 1 },
                                })}
                            >
                                {showArchived
                                    ? 'Hide archived'
                                    : 'Show archived'}
                            </Link>
                        </Button>
                        {can.manage && (
                            <Button
                                onClick={() => setAdding({ parentId: null })}
                            >
                                <Plus aria-hidden />
                                Add {levels[0]?.name.toLowerCase() ?? 'result'}
                            </Button>
                        )}
                    </>
                }
            />

            <div className="grid gap-3">
                <Panel>
                    <PanelHeader
                        title="Levels"
                        description={levels
                            .map((level) => level.name)
                            .join(' › ')}
                        actions={
                            can.manage &&
                            !editingLevels && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => setEditingLevels(true)}
                                >
                                    Rename or change
                                </Button>
                            )
                        }
                    />
                    {editingLevels && (
                        <LevelsForm
                            programmeId={programme.id}
                            levels={levels}
                            onDone={() => setEditingLevels(false)}
                        />
                    )}
                </Panel>

                {adding?.parentId === null && options !== null && (
                    <Panel>
                        <PanelHeader title={`New ${levels[0]?.name ?? ''}`} />
                        <ResultForm
                            programmeId={programme.id}
                            levels={levels}
                            results={results}
                            options={options}
                            result={null}
                            parentId={null}
                            onDone={() => setAdding(null)}
                        />
                    </Panel>
                )}

                {results.length === 0 ? (
                    <Panel>
                        <EmptyState icon={ListTree} title="No results yet">
                            Start with the goal at the top, then the outcomes
                            and outputs that lead to it. Indicators measure each
                            result.
                        </EmptyState>
                    </Panel>
                ) : (
                    <Panel>
                        <ul className="divide-y">
                            {results.map((result) => (
                                <ResultRow
                                    key={result.id}
                                    result={result}
                                    depth={depthOf(result.levelId)}
                                    levelName={levelName(result.levelId)}
                                    childLevel={
                                        levels.find(
                                            (level) =>
                                                level.depth ===
                                                depthOf(result.levelId) + 1,
                                        ) ?? null
                                    }
                                    programmeId={programme.id}
                                    levels={levels}
                                    results={results}
                                    options={options}
                                    canManage={can.manage}
                                    adding={adding?.parentId === result.id}
                                    onAdd={() =>
                                        setAdding({ parentId: result.id })
                                    }
                                    onDoneAdding={() => setAdding(null)}
                                />
                            ))}
                        </ul>
                    </Panel>
                )}
            </div>
        </>
    );
}

function ResultRow({
    result,
    depth,
    levelName,
    childLevel,
    programmeId,
    levels,
    results,
    options,
    canManage,
    adding,
    onAdd,
    onDoneAdding,
}: {
    result: Result;
    depth: number;
    levelName: string;
    childLevel: Level | null;
    programmeId: string;
    levels: Level[];
    results: Result[];
    options: Props['options'];
    canManage: boolean;
    adding: boolean;
    onAdd: () => void;
    onDoneAdding: () => void;
}) {
    const [editing, setEditing] = useState(false);
    const [open, setOpen] = useState(false);

    return (
        <li
            className="px-3 py-2"
            style={{ paddingLeft: `${0.75 + (depth - 1) * 1.5}rem` }}
        >
            <div className="flex flex-wrap items-start justify-between gap-2">
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    className="min-w-0 flex-1 text-left"
                >
                    <div className="text-xs text-muted-foreground">
                        {levelName} {result.code}
                        {result.archived && (
                            <StatusBadge className="ml-2">Archived</StatusBadge>
                        )}
                    </div>
                    <div className="text-sm font-medium">
                        {result.statement}
                    </div>
                </button>
                <div className="flex flex-wrap gap-1">
                    {canManage && !result.archived && (
                        <>
                            {childLevel && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={onAdd}
                                >
                                    <Plus aria-hidden />
                                    {childLevel.name}
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" asChild>
                                <Link
                                    href={newIndicator(programmeId, {
                                        query: { result: result.id },
                                    })}
                                >
                                    <Plus aria-hidden />
                                    Indicator
                                </Link>
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setEditing(!editing)}
                            >
                                Edit
                            </Button>
                        </>
                    )}
                    {canManage && (
                        <Form
                            {...archive.form({
                                programme: programmeId,
                                result: result.id,
                            })}
                            options={{ preserveScroll: true }}
                        >
                            {({ errors }) => (
                                <>
                                    {result.archived && (
                                        <input
                                            type="hidden"
                                            name="restore"
                                            value="1"
                                        />
                                    )}
                                    <Button
                                        type="submit"
                                        size="sm"
                                        variant="ghost"
                                    >
                                        {result.archived
                                            ? 'Restore'
                                            : 'Archive'}
                                    </Button>
                                    {errors.result && (
                                        <p className="text-xs text-destructive">
                                            {errors.result}
                                        </p>
                                    )}
                                </>
                            )}
                        </Form>
                    )}
                </div>
            </div>

            {result.indicators.length > 0 && (
                <ul className="mt-1 flex flex-wrap gap-1.5">
                    {result.indicators.map((item) => (
                        <li key={item.id}>
                            <Link
                                href={indicator({
                                    programme: programmeId,
                                    indicator: item.id,
                                })}
                                className="inline-flex items-center gap-1 border px-1.5 py-0.5 text-xs hover:bg-muted"
                            >
                                <span className="font-medium">{item.code}</span>
                                {item.name}
                                {item.status !== 'active' && (
                                    <span className="text-muted-foreground">
                                        ({item.status})
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}

            {open && (
                <dl className="mt-2 grid gap-2 border-l-2 pl-3 text-sm sm:grid-cols-2">
                    <Detail label="Description" value={result.description} />
                    <Detail label="Responsible" value={result.responsible} />
                    <Detail label="Assumptions" value={result.assumptions} />
                    <Detail label="Risks" value={result.risks} />
                    <Detail
                        label="Means of verification"
                        value={result.meansOfVerification}
                    />
                    <Detail
                        label="Project milestone"
                        value={
                            result.milestone
                                ? `${result.milestone.project} · ${result.milestone.name}${result.milestone.reachedOn ? `, reached ${formatDate(result.milestone.reachedOn)}` : result.milestone.dueOn ? `, due ${formatDate(result.milestone.dueOn)}` : ''}`
                                : result.linkedMilestoneId &&
                                  'A milestone in Projects, not shown now (Projects did not list it for you). The reference is kept.'
                        }
                    />
                    <div className="sm:col-span-2">
                        <dt className="text-xs text-muted-foreground">
                            Documents
                        </dt>
                        <dd>
                            {result.documents.length === 0 ? (
                                '—'
                            ) : (
                                <ul className="grid gap-1">
                                    {result.documents.map((file) => (
                                        <li
                                            key={file.id}
                                            className="flex items-center gap-2"
                                        >
                                            <FileText
                                                className="size-4 text-muted-foreground"
                                                aria-hidden
                                            />
                                            <a
                                                href={download.url(file.id)}
                                                className="hover:underline"
                                            >
                                                {file.name}
                                            </a>
                                            <span className="text-xs text-muted-foreground">
                                                {formatBytes(file.size)}
                                            </span>
                                            {canManage && (
                                                <Form
                                                    {...removeFile.form(
                                                        file.id,
                                                    )}
                                                    options={{
                                                        preserveScroll: true,
                                                    }}
                                                >
                                                    <button
                                                        type="submit"
                                                        className="text-xs text-muted-foreground hover:text-destructive"
                                                    >
                                                        Remove
                                                    </button>
                                                </Form>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {canManage && !result.archived && (
                                <Form
                                    {...documents.form({
                                        programme: programmeId,
                                        result: result.id,
                                    })}
                                    options={{ preserveScroll: true }}
                                    resetOnSuccess
                                    className="mt-2 flex flex-wrap items-center gap-2"
                                >
                                    {({ errors, processing }) => (
                                        <>
                                            <Input
                                                type="file"
                                                name="file"
                                                aria-label="Document"
                                                className="h-8 w-auto"
                                            />
                                            <CheckboxField
                                                name="share_with_donors"
                                                label="Donors may see it"
                                                prefix={`doc-${result.id}-`}
                                            />
                                            <Button
                                                type="submit"
                                                size="sm"
                                                variant="outline"
                                                disabled={processing}
                                            >
                                                Attach
                                            </Button>
                                            {errors.file && (
                                                <p className="text-sm text-destructive">
                                                    {errors.file}
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                            )}
                        </dd>
                    </div>
                </dl>
            )}

            {editing && options !== null && (
                <ResultForm
                    programmeId={programmeId}
                    levels={levels}
                    results={results}
                    options={options}
                    result={result}
                    parentId={result.parentId}
                    onDone={() => setEditing(false)}
                />
            )}
            {adding && options !== null && childLevel && (
                <div className="mt-2 border-l-2">
                    <ResultForm
                        programmeId={programmeId}
                        levels={levels}
                        results={results}
                        options={options}
                        result={null}
                        parentId={result.id}
                        levelId={childLevel.id}
                        onDone={onDoneAdding}
                    />
                </div>
            )}
        </li>
    );
}

function Detail({ label, value }: { label: string; value: string | null }) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="whitespace-pre-line">{value ?? '—'}</dd>
        </div>
    );
}

function ResultForm({
    programmeId,
    levels,
    results,
    options,
    result,
    parentId,
    levelId,
    onDone,
}: {
    programmeId: string;
    levels: Level[];
    results: Result[];
    options: NonNullable<Props['options']>;
    result: Result | null;
    parentId: string | null;
    levelId?: string;
    onDone: () => void;
}) {
    const action = result
        ? updateResult.form({ programme: programmeId, result: result.id })
        : addResult.form(programmeId);
    const level = result?.levelId ?? levelId ?? levels[0]?.id ?? '';
    const depth = levels.find((item) => item.id === level)?.depth ?? 1;
    const parents = results
        .filter(
            (item) =>
                !item.archived &&
                levels.find((candidate) => candidate.id === item.levelId)
                    ?.depth ===
                    depth - 1,
        )
        .map((item) => ({
            value: item.id,
            label: `${item.code} · ${item.statement}`,
        }));
    const prefix = `result-${result?.id ?? parentId ?? 'top'}-`;

    return (
        <Form
            {...action}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="grid gap-3 p-3 sm:grid-cols-2"
        >
            {({ errors, processing }) => (
                <>
                    <input type="hidden" name="level_id" value={level} />
                    {depth > 1 ? (
                        <SelectField
                            name="parent_id"
                            label="Contributes to"
                            options={parents}
                            defaultValue={parentId}
                            error={errors.parent_id}
                            prefix={prefix}
                        />
                    ) : (
                        <input type="hidden" name="parent_id" value="" />
                    )}
                    <TextField
                        name="code"
                        label="Code"
                        placeholder="1.1"
                        defaultValue={result?.code}
                        error={errors.code}
                        prefix={prefix}
                        required
                    />
                    <TextAreaField
                        name="statement"
                        label="Result statement"
                        className="sm:col-span-2"
                        rows={2}
                        defaultValue={result?.statement}
                        error={errors.statement}
                        prefix={prefix}
                        required
                    />
                    <TextAreaField
                        name="description"
                        label="Description"
                        defaultValue={result?.description}
                        error={errors.description}
                        prefix={prefix}
                    />
                    <TextAreaField
                        name="means_of_verification"
                        label="Means of verification"
                        defaultValue={result?.meansOfVerification}
                        error={errors.means_of_verification}
                        prefix={prefix}
                    />
                    <TextAreaField
                        name="assumptions"
                        label="Assumptions"
                        defaultValue={result?.assumptions}
                        error={errors.assumptions}
                        prefix={prefix}
                    />
                    <TextAreaField
                        name="risks"
                        label="Risks"
                        defaultValue={result?.risks}
                        error={errors.risks}
                        prefix={prefix}
                    />
                    <SelectField
                        name="responsible_id"
                        label="Responsible"
                        options={options.members}
                        empty="Nobody"
                        defaultValue={result?.responsibleId}
                        error={errors.responsible_id}
                        prefix={prefix}
                    />
                    <SelectField
                        name="linked_milestone_id"
                        label="Project milestone"
                        options={options.milestones}
                        empty={
                            options.milestones.length === 0
                                ? 'Link a unit to a project first'
                                : 'None'
                        }
                        defaultValue={result?.linkedMilestoneId}
                        error={errors.linked_milestone_id}
                        prefix={prefix}
                    />
                    {errors.level_id && (
                        <p className="text-sm text-destructive sm:col-span-2">
                            {errors.level_id}
                        </p>
                    )}
                    <div className="flex gap-2 sm:col-span-2">
                        <Button type="submit" size="sm" disabled={processing}>
                            {result ? 'Save' : 'Add'}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

function LevelsForm({
    programmeId,
    levels,
    onDone,
}: {
    programmeId: string;
    levels: Level[];
    onDone: () => void;
}) {
    const [names, setNames] = useState(levels.map((level) => level.name));

    return (
        <Form
            {...saveLevels.form(programmeId)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
        >
            {({ errors, processing }) => (
                <PanelBody className="grid gap-3">
                    <Field
                        id="level-0"
                        label="From the top"
                        hint="Up to six levels. A level with results under it keeps its place; rename it instead."
                        error={errorFor(errors, 'levels')}
                    >
                        <ol className="grid gap-2">
                            {names.map((name, index) => (
                                <li key={index} className="flex gap-2">
                                    <Input
                                        id={`level-${index}`}
                                        name={`levels[${index}]`}
                                        value={name}
                                        onChange={(event) =>
                                            setNames(
                                                names.map((item, position) =>
                                                    position === index
                                                        ? event.target.value
                                                        : item,
                                                ),
                                            )
                                        }
                                        aria-label={`Level ${index + 1}`}
                                    />
                                    {names.length > 1 && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                setNames(
                                                    names.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            Remove
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ol>
                    </Field>
                    <div className="flex gap-2">
                        {names.length < 6 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => setNames([...names, ''])}
                            >
                                Add a level
                            </Button>
                        )}
                        <Button type="submit" size="sm" disabled={processing}>
                            Save levels
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={onDone}
                        >
                            Cancel
                        </Button>
                    </div>
                </PanelBody>
            )}
        </Form>
    );
}
