import { Head, Link, router, useForm } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import {
    ArrowDown,
    ArrowUp,
    ChevronRight,
    Copy,
    Eye,
    EyeOff,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    OPERATORS,
    choiceName,
    deleteItem,
    duplicateItem,
    earlierQuestions,
    findItem,
    fromSimple,
    insertItem,
    nameFromLabel,
    newItem,
    renameItem,
    shiftItem,
    toSimple,
    updateItem,
} from '@/mel/collect/builder';
import type { Position, SimpleRule } from '@/mel/collect/builder';
import { choicesOf, isAnswerable } from '@/mel/collect/definition';
import type {
    Answers,
    Constraint,
    Definition,
    Item,
    ItemType,
    Leaf,
} from '@/mel/collect/definition';
import { FormRenderer, localToday } from '@/mel/collect/renderer';
import { selectClass, textareaClass } from '@/mel/components/fields';
import { FORM_STATUS, QUESTION_TYPES } from '@/mel/lib/collect';
import type { FormStatus } from '@/mel/lib/collect';
import { formatDateTime } from '@/mel/lib/mel';
import { draft, publish, show } from '@/routes/mel/collect/forms';

/*
 * The form builder: one list of questions, each opening in place to be
 * edited, with the rarely needed settings folded away, and a live preview
 * beside it that runs the same rules engine as the phone and the server.
 * The draft saves itself; Publish (checked again on the server) makes it
 * the next immutable version.
 */

type Issue = { path: string; question: string | null; message: string };

type Props = {
    form: {
        id: string;
        name: string;
        status: FormStatus;
        version: number | null;
        programme: { id: string; label: string } | null;
    };
    definition: Definition;
    issues: Issue[];
    savedAt: string;
    unpublishedChanges: boolean;
    canPublish: boolean;
    acceptOldDays: number;
};

/** The question types offered first; the rest sit under "More types". */
const COMMON: ItemType[] = [
    'text',
    'textarea',
    'integer',
    'select_one',
    'select_multiple',
    'yes_no',
    'date',
];

const MORE: ItemType[] = [
    'decimal',
    'time',
    'datetime',
    'geopoint',
    'photo',
    'note',
    'group',
];

function normalize(definition: Definition): Definition {
    return {
        ...definition,
        settings: definition.settings ?? { title: '' },
        items: Array.isArray(definition.items) ? definition.items : [],
        choices:
            definition.choices && !Array.isArray(definition.choices)
                ? definition.choices
                : {},
    };
}

function countQuestions(items: Item[]): number {
    return items.reduce(
        (total, item) =>
            total +
            (item.type === 'group'
                ? countQuestions(Array.isArray(item.items) ? item.items : [])
                : isAnswerable(item.type)
                  ? 1
                  : 0),
        0,
    );
}

export default function BuildForm({
    form,
    definition: initial,
    issues,
    savedAt,
    unpublishedChanges,
    canPublish,
    acceptOldDays,
}: Props) {
    const [definition, setDefinition] = useState<Definition>(() =>
        normalize(initial),
    );
    const [selected, setSelected] = useState<string | null>(null);
    const [saving, setSaving] = useState<
        'saved' | 'pending' | 'saving' | 'error'
    >('saved');
    const [preview, setPreview] = useState<Answers>({});
    const [showPreview, setShowPreview] = useState(true);
    const [publishing, setPublishing] = useState(false);
    const first = useRef(true);

    // Autosave, a second after the last change.
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        setSaving('pending');
        const timer = window.setTimeout(() => {
            setSaving('saving');
            router.put(draft.url(form.id), { definition } as never, {
                preserveScroll: true,
                preserveState: true,
                only: ['issues', 'savedAt', 'unpublishedChanges'],
                onSuccess: () => setSaving('saved'),
                onError: () => setSaving('error'),
            });
        }, 1000);

        return () => window.clearTimeout(timer);
    }, [definition, form.id]);

    const item = selected ? findItem(definition.items, selected) : undefined;

    const add = (type: ItemType) => {
        const made = newItem(definition, type);
        const target = item?.type === 'group' ? item.name : selected;
        const position: Position = item?.type === 'group' ? 'inside' : 'after';
        setDefinition({
            ...made.definition,
            items: insertItem(
                made.definition.items,
                made.item,
                target,
                position,
            ),
        });
        setSelected(made.item.name);
    };

    const patch = (name: string, changes: Partial<Item>) =>
        setDefinition({
            ...definition,
            items: updateItem(definition.items, name, changes),
        });

    const issueFor = (name: string) =>
        issues.filter((issue) => issue.question === name);
    const formIssues = issues.filter((issue) => issue.question === null);
    const questions = countQuestions(definition.items);
    const ready =
        issues.length === 0 && saving === 'saved' && unpublishedChanges;

    const cardProps = {
        definition,
        selected,
        issueFor,
        onSelect: (name: string) =>
            setSelected((current) => (current === name ? null : name)),
        onPatch: patch,
        onDefinition: setDefinition,
        onRenamed: setSelected,
        onRemoved: () => setSelected(null),
    };

    return (
        <>
            <Head title={`Build: ${form.name}`} />
            <PageHeader
                title={form.name}
                description="Changes save themselves. Phones get them only when you publish."
                badges={
                    <>
                        <StatusBadge tone={FORM_STATUS[form.status].tone}>
                            {FORM_STATUS[form.status].label}
                            {form.version !== null &&
                                ` · version ${form.version}`}
                        </StatusBadge>
                        <span
                            className="text-xs text-muted-foreground"
                            aria-live="polite"
                        >
                            {saving === 'saved' &&
                                `Saved ${formatDateTime(savedAt)}`}
                            {saving === 'pending' && 'Unsaved changes'}
                            {saving === 'saving' && 'Saving…'}
                            {saving === 'error' && 'Not saved: fix the issues'}
                        </span>
                    </>
                }
                actions={
                    <>
                        <Button
                            variant="outline"
                            onClick={() => setShowPreview(!showPreview)}
                        >
                            {showPreview ? (
                                <EyeOff className="size-4" />
                            ) : (
                                <Eye className="size-4" />
                            )}
                            {showPreview ? 'Hide preview' : 'Preview'}
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={show(form.id)}>Done</Link>
                        </Button>
                        {canPublish && (
                            <Button
                                disabled={!ready && !publishing}
                                title={
                                    ready
                                        ? undefined
                                        : 'Available once the draft is saved, has no issues and differs from the version in use.'
                                }
                                onClick={() => setPublishing(!publishing)}
                            >
                                Publish
                            </Button>
                        )}
                    </>
                }
            />
            {canPublish && publishing && (
                <PublishPanel
                    formId={form.id}
                    hasVersion={form.version !== null}
                    ready={ready}
                    acceptOldDays={acceptOldDays}
                    onCancel={() => setPublishing(false)}
                />
            )}
            <div
                className={`grid items-start gap-3 ${showPreview ? 'lg:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)]' : ''}`}
            >
                <div className="grid gap-3">
                    <Panel>
                        <PanelBody className="grid gap-3">
                            <label className="grid gap-1 text-sm">
                                <span className="font-medium">
                                    Title shown on the phone
                                </span>
                                <Input
                                    value={definition.settings.title ?? ''}
                                    maxLength={200}
                                    onChange={(e) =>
                                        setDefinition({
                                            ...definition,
                                            settings: {
                                                ...definition.settings,
                                                title: e.target.value,
                                            },
                                        })
                                    }
                                />
                            </label>
                            <More
                                label="Consent statement"
                                open={!!definition.settings.consent}
                            >
                                <label className="grid gap-1 text-sm">
                                    <span className="text-muted-foreground">
                                        Asked before the first question. Leave
                                        empty for none.
                                    </span>
                                    <textarea
                                        className={textareaClass}
                                        value={
                                            definition.settings.consent?.text ??
                                            ''
                                        }
                                        onChange={(e) => {
                                            const text = e.target.value;
                                            const {
                                                consent: _consent,
                                                ...rest
                                            } = definition.settings;
                                            setDefinition({
                                                ...definition,
                                                settings:
                                                    text.trim() === ''
                                                        ? rest
                                                        : {
                                                              ...rest,
                                                              consent: {
                                                                  text,
                                                              },
                                                          },
                                            });
                                        }}
                                    />
                                </label>
                            </More>
                        </PanelBody>
                    </Panel>

                    <div className="flex items-baseline justify-between px-1 text-sm">
                        <span className="font-medium">
                            {questions} question{questions === 1 ? '' : 's'}
                        </span>
                        <span
                            className={
                                issues.length > 0
                                    ? 'text-destructive'
                                    : 'text-muted-foreground'
                            }
                        >
                            {issues.length === 0
                                ? 'Ready to publish'
                                : `${issues.length} thing${issues.length === 1 ? '' : 's'} to fix before publishing`}
                        </span>
                    </div>

                    {formIssues.length > 0 && (
                        <ul className="grid gap-1 rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                            {formIssues.map((issue) => (
                                <li key={issue.path}>{issue.message}</li>
                            ))}
                        </ul>
                    )}

                    {definition.items.length === 0 ? (
                        <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                            No questions yet. Add the first one below.
                        </p>
                    ) : (
                        <ol className="grid gap-2">
                            {definition.items.map((child) => (
                                <QuestionCard
                                    key={child.name}
                                    item={child}
                                    {...cardProps}
                                />
                            ))}
                        </ol>
                    )}

                    <AddQuestion
                        where={
                            item?.type === 'group'
                                ? `inside “${item.label || 'this section'}”`
                                : selected
                                  ? 'after the open question'
                                  : 'at the end'
                        }
                        onAdd={add}
                    />
                </div>

                {showPreview && (
                    <Panel className="lg:sticky lg:top-4">
                        <PanelHeader
                            title="Preview"
                            description="Try it as a collector would. Nothing is saved."
                        />
                        <PanelBody className="grid max-h-[75vh] gap-3 overflow-y-auto">
                            <FormRenderer
                                definition={definition}
                                answers={preview}
                                onChange={setPreview}
                                today={localToday()}
                                showErrors
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => setPreview({})}
                            >
                                Clear the answers
                            </Button>
                        </PanelBody>
                    </Panel>
                )}
            </div>
        </>
    );
}

/** A folded block of settings most forms never need. */
function More({
    label,
    open,
    children,
}: {
    label: string;
    open?: boolean;
    children: ReactNode;
}) {
    return (
        <details className="group text-sm" open={open}>
            <summary className="flex cursor-pointer list-none items-center gap-1 text-muted-foreground select-none hover:text-foreground">
                <ChevronRight className="size-4 transition-transform group-open:rotate-90" />
                {label}
            </summary>
            <div className="mt-2 grid gap-3 pl-5">{children}</div>
        </details>
    );
}

function AddQuestion({
    where,
    onAdd,
}: {
    where: string;
    onAdd: (type: ItemType) => void;
}) {
    const [open, setOpen] = useState(false);
    const [more, setMore] = useState(false);
    const pick = (type: ItemType) => {
        onAdd(type);
        setOpen(false);
        setMore(false);
    };
    const option = (type: ItemType) => (
        <button
            key={type}
            type="button"
            onClick={() => pick(type)}
            className="grid rounded-md border px-3 py-2 text-left hover:border-primary hover:bg-primary/5"
        >
            <span className="text-sm font-medium">
                {QUESTION_TYPES[type].label}
            </span>
            <span className="text-xs text-muted-foreground">
                {QUESTION_TYPES[type].hint}
            </span>
        </button>
    );

    if (!open) {
        return (
            <Button
                variant="outline"
                className="w-full border-dashed"
                onClick={() => setOpen(true)}
            >
                <Plus className="size-4" /> Add a question
            </Button>
        );
    }

    return (
        <div className="grid gap-2 rounded-md border p-3">
            <div className="flex items-center justify-between text-sm">
                <span>
                    What kind of answer?{' '}
                    <span className="text-muted-foreground">
                        Added {where}.
                    </span>
                </span>
                <Button
                    size="sm"
                    variant="ghost"
                    aria-label="Close"
                    onClick={() => setOpen(false)}
                >
                    <X className="size-4" />
                </Button>
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {COMMON.map(option)}
                {more && MORE.map(option)}
            </div>
            {!more && (
                <button
                    type="button"
                    className="justify-self-start text-sm text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    onClick={() => setMore(true)}
                >
                    More types: decimal, time, location, photo, note, section…
                </button>
            )}
        </div>
    );
}

type CardProps = {
    item: Item;
    definition: Definition;
    selected: string | null;
    issueFor: (name: string) => Issue[];
    onSelect: (name: string) => void;
    onPatch: (name: string, changes: Partial<Item>) => void;
    onDefinition: (definition: Definition) => void;
    onRenamed: (name: string) => void;
    onRemoved: () => void;
};

function QuestionCard(props: CardProps) {
    const { item, selected, issueFor, onSelect } = props;
    const open = selected === item.name;
    const problems = issueFor(item.name);
    const children =
        item.type === 'group' && Array.isArray(item.items) ? item.items : [];

    return (
        <li
            className={`rounded-md border bg-card ${open ? 'border-primary shadow-sm' : ''} ${item.type === 'group' ? 'border-l-4 border-l-primary/40' : ''}`}
        >
            <button
                type="button"
                onClick={() => onSelect(item.name)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm"
            >
                <span
                    className={`min-w-0 flex-1 truncate ${item.type === 'group' ? 'font-semibold' : ''}`}
                >
                    {item.label || (
                        <span className="text-muted-foreground italic">
                            {item.type === 'note'
                                ? 'Empty note'
                                : 'Untitled question'}
                        </span>
                    )}
                    {item.required && (
                        <span className="text-destructive"> *</span>
                    )}
                </span>
                {item.relevant && (
                    <span className="shrink-0 text-xs text-muted-foreground">
                        conditional
                    </span>
                )}
                <span className="shrink-0 rounded bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">
                    {QUESTION_TYPES[item.type]?.label ?? item.type}
                </span>
                {problems.length > 0 && (
                    <span
                        className="shrink-0 text-xs text-destructive"
                        title={problems.map((p) => p.message).join('\n')}
                    >
                        ● {problems.length}
                    </span>
                )}
            </button>
            {open && (
                <div className="border-t px-3 py-3">
                    <Properties
                        key={item.name}
                        item={item}
                        definition={props.definition}
                        issues={problems}
                        onPatch={(changes) => props.onPatch(item.name, changes)}
                        onDefinition={props.onDefinition}
                        onRenamed={props.onRenamed}
                        onRemoved={props.onRemoved}
                    />
                </div>
            )}
            {children.length > 0 && (
                <ol className="grid gap-2 px-3 pb-3">
                    {children.map((child) => (
                        <QuestionCard
                            key={child.name}
                            {...props}
                            item={child}
                        />
                    ))}
                </ol>
            )}
        </li>
    );
}

function Properties({
    item,
    definition,
    issues,
    onPatch,
    onDefinition,
    onRenamed,
    onRemoved,
}: {
    item: Item;
    definition: Definition;
    issues: Issue[];
    onPatch: (changes: Partial<Item>) => void;
    onDefinition: (definition: Definition) => void;
    onRenamed: (name: string) => void;
    onRemoved: () => void;
}) {
    const [name, setName] = useState(item.name);
    const answerable = isAnswerable(item.type);
    const constraint: Constraint = item.constraint ?? {};
    const setConstraint = (key: keyof Constraint, value: string) => {
        const next: Record<string, unknown> = { ...constraint };
        if (value === '') {
            delete next[key];
        } else {
            next[key] =
                ['integer', 'decimal'].includes(item.type) ||
                [
                    'min_length',
                    'max_length',
                    'min_choices',
                    'max_choices',
                ].includes(key)
                    ? Number(value)
                    : value;
        }
        onPatch({
            constraint:
                Object.keys(next).length === 0
                    ? undefined
                    : (next as Constraint),
        });
    };
    const hasLimits = ['integer', 'decimal', 'date', 'time'].includes(
        item.type,
    );
    const hasText = ['text', 'textarea'].includes(item.type);

    return (
        <div className="grid gap-3 text-sm">
            <label className="grid gap-1">
                <span className="font-medium">
                    {item.type === 'note'
                        ? 'Text shown'
                        : item.type === 'group'
                          ? 'Section title'
                          : 'Question'}
                </span>
                <textarea
                    className={textareaClass}
                    rows={2}
                    autoFocus={item.label === ''}
                    value={item.label}
                    onChange={(e) => onPatch({ label: e.target.value })}
                    onBlur={(e) => {
                        // A generated name follows the first label written.
                        if (
                            /^(q|section_|note_)\d+$/.test(item.name) &&
                            e.target.value.trim() !== ''
                        ) {
                            const next = nameFromLabel(
                                definition,
                                e.target.value,
                                item.name,
                            );
                            if (next !== item.name) {
                                onDefinition(
                                    renameItem(definition, item.name, next),
                                );
                                onRenamed(next);
                            }
                        }
                    }}
                />
            </label>
            {answerable && (
                <>
                    <Input
                        placeholder="Hint for the collector (optional)"
                        aria-label="Hint"
                        value={item.hint ?? ''}
                        onChange={(e) =>
                            onPatch({ hint: e.target.value || undefined })
                        }
                    />
                    <label className="flex items-center gap-1.5">
                        <input
                            type="checkbox"
                            checked={item.required === true}
                            onChange={(e) =>
                                onPatch({
                                    required: e.target.checked || undefined,
                                })
                            }
                        />
                        Required
                    </label>
                </>
            )}
            {(item.type === 'select_one' ||
                item.type === 'select_multiple') && (
                <ChoicesEditor
                    item={item}
                    definition={definition}
                    onDefinition={onDefinition}
                />
            )}
            {issues.length > 0 && (
                <ul className="grid gap-1 rounded-md bg-destructive/5 p-2 text-destructive">
                    {issues.map((issue) => (
                        <li key={issue.path}>{issue.message}</li>
                    ))}
                </ul>
            )}
            <More label="Show only when…" open={!!item.relevant}>
                <RuleEditor
                    item={item}
                    definition={definition}
                    onPatch={onPatch}
                />
            </More>
            {(hasLimits || hasText) && (
                <More
                    label="Allowed answers"
                    open={Object.keys(constraint).length > 0}
                >
                    {hasLimits && (
                        <div className="grid grid-cols-2 gap-2">
                            {(['min', 'max'] as const).map((key) => (
                                <label key={key} className="grid gap-1">
                                    <span>
                                        {key === 'min'
                                            ? 'Lowest allowed'
                                            : 'Highest allowed'}
                                    </span>
                                    <Input
                                        value={String(constraint[key] ?? '')}
                                        placeholder={
                                            item.type === 'date'
                                                ? 'YYYY-MM-DD, today, today-30d'
                                                : ''
                                        }
                                        onChange={(e) =>
                                            setConstraint(key, e.target.value)
                                        }
                                    />
                                </label>
                            ))}
                        </div>
                    )}
                    {hasText && (
                        <div className="grid grid-cols-3 gap-2">
                            {(['min_length', 'max_length'] as const).map(
                                (key) => (
                                    <label key={key} className="grid gap-1">
                                        <span>
                                            {key === 'min_length'
                                                ? 'Shortest'
                                                : 'Longest'}
                                        </span>
                                        <Input
                                            type="number"
                                            min={0}
                                            value={String(
                                                constraint[key] ?? '',
                                            )}
                                            onChange={(e) =>
                                                setConstraint(
                                                    key,
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </label>
                                ),
                            )}
                            <label className="grid gap-1">
                                <span>Format</span>
                                <select
                                    className={selectClass}
                                    value={constraint.pattern ?? ''}
                                    onChange={(e) =>
                                        setConstraint('pattern', e.target.value)
                                    }
                                >
                                    <option value="">Any</option>
                                    <option value="digits">Digits only</option>
                                    <option value="phone">Phone number</option>
                                </select>
                            </label>
                        </div>
                    )}
                </More>
            )}
            <More label="Advanced">
                {answerable &&
                    (['photo', 'geopoint'].includes(item.type) ? (
                        <p className="text-xs text-muted-foreground">
                            Photos and places are always treated as personal
                            data.
                        </p>
                    ) : (
                        <label className="flex items-center gap-1.5">
                            <input
                                type="checkbox"
                                checked={item.personal === true}
                                onChange={(e) =>
                                    onPatch({
                                        personal: e.target.checked || undefined,
                                    })
                                }
                            />
                            Personal data (names, phone numbers, ID numbers)
                        </label>
                    ))}
                <label className="grid gap-1">
                    <span>
                        Column name in exports and rules (letters, digits and _)
                    </span>
                    <div className="flex gap-2">
                        <Input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                        />
                        <Button
                            variant="outline"
                            disabled={
                                name === item.name ||
                                !/^[A-Za-z_][A-Za-z0-9_]{0,63}$/.test(name) ||
                                findItem(definition.items, name) !== undefined
                            }
                            onClick={() => {
                                onDefinition(
                                    renameItem(definition, item.name, name),
                                );
                                onRenamed(name);
                            }}
                        >
                            Rename
                        </Button>
                    </div>
                </label>
            </More>
            <div className="flex flex-wrap items-center gap-1 border-t pt-3">
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        onDefinition({
                            ...definition,
                            items: shiftItem(definition.items, item.name, -1),
                        })
                    }
                    aria-label="Move up"
                    title="Move up"
                >
                    <ArrowUp className="size-4" />
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        onDefinition({
                            ...definition,
                            items: shiftItem(definition.items, item.name, 1),
                        })
                    }
                    aria-label="Move down"
                    title="Move down"
                >
                    <ArrowDown className="size-4" />
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() =>
                        onDefinition(duplicateItem(definition, item.name))
                    }
                >
                    <Copy className="size-4" /> Duplicate
                </Button>
                <Button
                    size="sm"
                    variant="ghost"
                    className="ml-auto text-destructive hover:text-destructive"
                    onClick={() => {
                        onDefinition(deleteItem(definition, item.name));
                        onRemoved();
                    }}
                >
                    <Trash2 className="size-4" /> Delete
                </Button>
            </div>
        </div>
    );
}

function ChoicesEditor({
    item,
    definition,
    onDefinition,
}: {
    item: Item;
    definition: Definition;
    onDefinition: (d: Definition) => void;
}) {
    const listName = item.choices ?? item.name;
    const list = choicesOf(definition, item);
    const save = (next: typeof list) =>
        onDefinition({
            ...definition,
            choices: { ...definition.choices, [listName]: next },
        });

    return (
        <fieldset className="grid gap-1.5">
            <legend className="mb-1">Options</legend>
            {list.map((choice, i) => (
                <div key={choice.name} className="flex items-center gap-2">
                    <Input
                        value={choice.label}
                        onChange={(e) =>
                            save(
                                list.map((c, j) =>
                                    j === i
                                        ? { ...c, label: e.target.value }
                                        : c,
                                ),
                            )
                        }
                        onBlur={(e) => {
                            if (
                                /^option_\d+$/.test(choice.name) &&
                                e.target.value.trim() !== ''
                            ) {
                                save(
                                    list.map((c, j) =>
                                        j === i
                                            ? {
                                                  ...c,
                                                  name: choiceName(
                                                      list,
                                                      e.target.value,
                                                      c.name,
                                                  ),
                                              }
                                            : c,
                                    ),
                                );
                            }
                        }}
                    />
                    <code
                        className="w-28 shrink-0 truncate text-xs text-muted-foreground"
                        title="Stored value"
                    >
                        {choice.name}
                    </code>
                    <Button
                        size="sm"
                        variant="ghost"
                        aria-label="Remove option"
                        onClick={() => save(list.filter((_, j) => j !== i))}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                </div>
            ))}
            <div>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        save([
                            ...list,
                            {
                                name: choiceName(
                                    list,
                                    `option ${list.length + 1}`,
                                ),
                                label: `Option ${list.length + 1}`,
                            },
                        ])
                    }
                >
                    Add an option
                </Button>
            </div>
        </fieldset>
    );
}

/** A condition value as the editor shows it (the first of a list). */
function leafText(value: unknown): string {
    const first = Array.isArray(value) ? value[0] : value;

    return typeof first === 'string' || typeof first === 'number'
        ? String(first)
        : '';
}

function RuleEditor({
    item,
    definition,
    onPatch,
}: {
    item: Item;
    definition: Definition;
    onPatch: (changes: Partial<Item>) => void;
}) {
    const earlier = earlierQuestions(definition, item.name).filter((q) =>
        isAnswerable(q.type),
    );
    const rule = toSimple(item.relevant);

    if (rule === null) {
        return (
            <p className="text-xs text-muted-foreground">
                This item has a visibility rule the simple editor cannot show.
                It is kept as it is.
            </p>
        );
    }
    if (earlier.length === 0) {
        return (
            <p className="text-xs text-muted-foreground">
                Visibility rules can refer to questions before this one.
            </p>
        );
    }

    const save = (next: SimpleRule) => onPatch({ relevant: fromSimple(next) });
    const setLeaf = (i: number, leaf: Leaf) =>
        save({
            ...rule,
            leaves: rule.leaves.map((l, j) => (j === i ? leaf : l)),
        });

    return (
        <fieldset className="grid gap-1.5">
            {rule.leaves.length === 0 && (
                <p className="text-xs text-muted-foreground">Always shown.</p>
            )}
            {rule.leaves.length > 1 && (
                <select
                    className={selectClass}
                    value={rule.join}
                    onChange={(e) =>
                        save({ ...rule, join: e.target.value as 'all' | 'any' })
                    }
                >
                    <option value="all">All of these hold</option>
                    <option value="any">Any of these holds</option>
                </select>
            )}
            {rule.leaves.map((leaf, i) => {
                const question = earlier.find((q) => q.name === leaf.q);
                const ops =
                    question && isAnswerable(question.type)
                        ? OPERATORS[question.type]
                        : [];
                const choices = question ? choicesOf(definition, question) : [];
                const needsValue = !['answered', 'not_answered'].includes(
                    leaf.op,
                );

                return (
                    <div
                        key={i}
                        className="grid grid-cols-[1fr_auto] gap-1 rounded-md border p-2"
                    >
                        <select
                            className={selectClass}
                            value={leaf.q}
                            onChange={(e) =>
                                setLeaf(i, {
                                    q: e.target.value,
                                    op: 'answered',
                                })
                            }
                        >
                            {earlier.map((q) => (
                                <option key={q.name} value={q.name}>
                                    {q.label || q.name}
                                </option>
                            ))}
                        </select>
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label="Remove condition"
                            onClick={() =>
                                save({
                                    ...rule,
                                    leaves: rule.leaves.filter(
                                        (_, j) => j !== i,
                                    ),
                                })
                            }
                        >
                            <Trash2 className="size-4" />
                        </Button>
                        <select
                            className={selectClass}
                            value={leaf.op}
                            onChange={(e) =>
                                setLeaf(i, {
                                    ...leaf,
                                    op: e.target.value as Leaf['op'],
                                })
                            }
                        >
                            {ops.map((op) => (
                                <option key={op.op} value={op.op}>
                                    {op.label}
                                </option>
                            ))}
                        </select>
                        <span />
                        {needsValue &&
                            (choices.length > 0 ? (
                                <select
                                    className={selectClass}
                                    value={leafText(leaf.value)}
                                    onChange={(e) =>
                                        setLeaf(i, {
                                            ...leaf,
                                            value:
                                                leaf.op === 'in'
                                                    ? [e.target.value]
                                                    : e.target.value,
                                        })
                                    }
                                >
                                    <option value="">Choose…</option>
                                    {choices.map((choice) => (
                                        <option
                                            key={choice.name}
                                            value={choice.name}
                                        >
                                            {choice.label}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <Input
                                    value={leafText(leaf.value)}
                                    onChange={(e) =>
                                        setLeaf(i, {
                                            ...leaf,
                                            value:
                                                question &&
                                                ['integer', 'decimal'].includes(
                                                    question.type,
                                                ) &&
                                                e.target.value !== ''
                                                    ? Number(e.target.value)
                                                    : e.target.value,
                                        })
                                    }
                                />
                            ))}
                    </div>
                );
            })}
            <div>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        save({
                            ...rule,
                            leaves: [
                                ...rule.leaves,
                                {
                                    q: earlier[earlier.length - 1].name,
                                    op: 'answered',
                                },
                            ],
                        })
                    }
                >
                    Add a condition
                </Button>
            </div>
        </fieldset>
    );
}

function PublishPanel({
    formId,
    hasVersion,
    ready,
    acceptOldDays,
    onCancel,
}: {
    formId: string;
    hasVersion: boolean;
    ready: boolean;
    acceptOldDays: number;
    onCancel: () => void;
}) {
    const form = useForm({ note: '', accept_old_days: acceptOldDays });

    return (
        <Panel className="mb-3">
            <PanelHeader
                title="Publish"
                description={
                    hasVersion
                        ? 'Publishing makes a new version. The one in use stops being offered to phones; what was filled in on it is still accepted for the days you choose.'
                        : 'Publishing makes version 1. Assigned collectors get it the next time their phone syncs.'
                }
            />
            <PanelBody>
                <form
                    className="grid gap-3 md:grid-cols-[2fr_1fr_auto_auto] md:items-end"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.submit(publish(formId));
                    }}
                >
                    <label className="grid gap-1 text-sm">
                        <span>What changed (optional)</span>
                        <Input
                            value={form.data.note}
                            maxLength={1000}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                        />
                    </label>
                    {hasVersion && (
                        <label className="grid gap-1 text-sm">
                            <span>Accept the old version for (days)</span>
                            <Input
                                type="number"
                                min={0}
                                max={365}
                                value={form.data.accept_old_days}
                                onChange={(e) =>
                                    form.setData(
                                        'accept_old_days',
                                        Number(e.target.value),
                                    )
                                }
                            />
                        </label>
                    )}
                    <Button type="submit" disabled={!ready || form.processing}>
                        Publish now
                    </Button>
                    <Button type="button" variant="ghost" onClick={onCancel}>
                        Cancel
                    </Button>
                    {(form.errors as Record<string, string>).definition && (
                        <p className="text-sm text-destructive md:col-span-4">
                            {(form.errors as Record<string, string>).definition}
                        </p>
                    )}
                    {!ready && (
                        <p className="text-xs text-muted-foreground md:col-span-4">
                            Publish is available once the draft is saved, has no
                            issues and differs from the version in use.
                        </p>
                    )}
                </form>
            </PanelBody>
        </Panel>
    );
}
