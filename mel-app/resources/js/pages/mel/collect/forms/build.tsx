import { Head, Link, router, useForm } from '@inertiajs/react';
import { PageHeader } from '@miles/ui/components/page-header';
import { Panel, PanelBody, PanelHeader } from '@miles/ui/components/panel';
import { StatusBadge } from '@miles/ui/components/status-badge';
import { Button } from '@miles/ui/components/ui/button';
import { Input } from '@miles/ui/components/ui/input';
import { ArrowDown, ArrowUp, Copy, Trash2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
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
 * The form builder: an outline, the selected item's properties, and a live
 * preview that runs the same rules engine as the phone and the server. The
 * draft saves itself; Publish (checked again on the server) makes it the
 * next immutable version.
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

const ADDABLE: ItemType[] = [
    'text',
    'textarea',
    'integer',
    'decimal',
    'select_one',
    'select_multiple',
    'yes_no',
    'date',
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

    const patch = (changes: Partial<Item>) => {
        if (!item) return;
        setDefinition({
            ...definition,
            items: updateItem(definition.items, item.name, changes),
        });
    };

    const issueFor = (name: string) =>
        issues.filter((issue) => issue.question === name);

    return (
        <>
            <Head title={`Build: ${form.name}`} />
            <PageHeader
                title={form.name}
                description="Build the questions. Changes save themselves; phones get them only when you publish."
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
                            {saving === 'error' &&
                                'Not saved: check the issues below'}
                        </span>
                    </>
                }
                actions={
                    <Button variant="outline" asChild>
                        <Link href={show(form.id)}>Done</Link>
                    </Button>
                }
            />
            <div className="grid gap-3 xl:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)_minmax(0,1fr)]">
                <Panel>
                    <PanelHeader
                        title="Outline"
                        description={`${issues.length === 0 ? 'No issues.' : `${issues.length} issue${issues.length === 1 ? '' : 's'} to fix before publishing.`}`}
                    />
                    <PanelBody className="grid gap-3">
                        <label className="grid gap-1 text-sm">
                            <span>Title shown on the phone</span>
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
                        <label className="grid gap-1 text-sm">
                            <span>
                                Consent statement (optional: asked before the
                                first question)
                            </span>
                            <textarea
                                className={textareaClass}
                                value={definition.settings.consent?.text ?? ''}
                                onChange={(e) => {
                                    const text = e.target.value;
                                    const { consent: _consent, ...rest } =
                                        definition.settings;
                                    setDefinition({
                                        ...definition,
                                        settings:
                                            text.trim() === ''
                                                ? rest
                                                : {
                                                      ...rest,
                                                      consent: { text },
                                                  },
                                    });
                                }}
                            />
                        </label>
                        <Outline
                            items={definition.items}
                            depth={0}
                            selected={selected}
                            onSelect={setSelected}
                            issueFor={issueFor}
                        />
                        {definition.items.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No questions yet. Add the first one below.
                            </p>
                        )}
                        <div>
                            <p className="mb-1 text-xs text-muted-foreground">
                                Add{' '}
                                {item?.type === 'group'
                                    ? `inside “${item.label || item.name}”`
                                    : selected
                                      ? 'after the selected item'
                                      : 'at the end'}
                                :
                            </p>
                            <div className="flex flex-wrap gap-1">
                                {ADDABLE.map((type) => (
                                    <Button
                                        key={type}
                                        size="sm"
                                        variant="outline"
                                        title={QUESTION_TYPES[type].hint}
                                        onClick={() => add(type)}
                                    >
                                        {QUESTION_TYPES[type].label}
                                    </Button>
                                ))}
                            </div>
                        </div>
                        {issues.some((issue) => issue.question === null) && (
                            <ul className="text-sm text-destructive">
                                {issues
                                    .filter((issue) => issue.question === null)
                                    .map((issue) => (
                                        <li key={issue.path}>
                                            {issue.message}
                                        </li>
                                    ))}
                            </ul>
                        )}
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader
                        title={
                            item
                                ? (QUESTION_TYPES[item.type]?.label ??
                                  item.type)
                                : 'Properties'
                        }
                    />
                    <PanelBody>
                        {item ? (
                            <Properties
                                key={item.name}
                                item={item}
                                definition={definition}
                                issues={issueFor(item.name)}
                                onPatch={patch}
                                onDefinition={setDefinition}
                                onRenamed={setSelected}
                                onRemoved={() => setSelected(null)}
                            />
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Select an item in the outline to change it.
                            </p>
                        )}
                    </PanelBody>
                </Panel>
                <Panel>
                    <PanelHeader
                        title="Preview"
                        description="Try it: the rules work as on the phone. Nothing is saved."
                    />
                    <PanelBody className="grid gap-3">
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
            </div>
            {canPublish && (
                <PublishPanel
                    formId={form.id}
                    hasVersion={form.version !== null}
                    ready={
                        issues.length === 0 &&
                        saving === 'saved' &&
                        unpublishedChanges
                    }
                    acceptOldDays={acceptOldDays}
                />
            )}
        </>
    );
}

function Outline({
    items,
    depth,
    selected,
    onSelect,
    issueFor,
}: {
    items: Item[];
    depth: number;
    selected: string | null;
    onSelect: (name: string) => void;
    issueFor: (name: string) => Issue[];
}) {
    return (
        <ul className="grid gap-1" role={depth === 0 ? 'tree' : 'group'}>
            {items.map((item) => (
                <li
                    key={item.name}
                    role="treeitem"
                    aria-selected={selected === item.name}
                >
                    <button
                        type="button"
                        onClick={() => onSelect(item.name)}
                        className={`flex w-full items-center gap-2 rounded-md border px-2 py-1.5 text-left text-sm ${selected === item.name ? 'border-primary bg-primary/5' : ''}`}
                        style={{ marginLeft: depth * 16 }}
                    >
                        <span className="truncate">
                            {item.label || (
                                <span className="text-muted-foreground italic">
                                    No label
                                </span>
                            )}
                            {item.required && (
                                <span className="text-destructive"> *</span>
                            )}
                        </span>
                        <span className="ml-auto shrink-0 text-xs text-muted-foreground">
                            {QUESTION_TYPES[item.type]?.label ?? item.type}
                        </span>
                        {issueFor(item.name).length > 0 && (
                            <span className="shrink-0 text-xs text-destructive">
                                ●
                            </span>
                        )}
                    </button>
                    {item.type === 'group' &&
                        Array.isArray(item.items) &&
                        item.items.length > 0 && (
                            <Outline
                                items={item.items}
                                depth={depth + 1}
                                selected={selected}
                                onSelect={onSelect}
                                issueFor={issueFor}
                            />
                        )}
                </li>
            ))}
        </ul>
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

    return (
        <div className="grid gap-3 text-sm">
            <label className="grid gap-1">
                <span>{item.type === 'note' ? 'Text shown' : 'Label'}</span>
                <textarea
                    className={textareaClass}
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
            <label className="grid gap-1">
                <span>
                    Name (used in exports and rules; letters, digits and _)
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
            {answerable && (
                <>
                    <label className="grid gap-1">
                        <span>Hint (optional)</span>
                        <Input
                            value={item.hint ?? ''}
                            onChange={(e) =>
                                onPatch({ hint: e.target.value || undefined })
                            }
                        />
                    </label>
                    <div className="flex flex-wrap gap-4">
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
                        {!['photo', 'geopoint'].includes(item.type) && (
                            <label className="flex items-center gap-1.5">
                                <input
                                    type="checkbox"
                                    checked={item.personal === true}
                                    onChange={(e) =>
                                        onPatch({
                                            personal:
                                                e.target.checked || undefined,
                                        })
                                    }
                                />
                                Personal data (names, phone numbers, ID numbers)
                            </label>
                        )}
                    </div>
                    {['photo', 'geopoint'].includes(item.type) && (
                        <p className="text-xs text-muted-foreground">
                            Photos and places are always treated as personal
                            data.
                        </p>
                    )}
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
            {['integer', 'decimal', 'date', 'time'].includes(item.type) && (
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
            {['text', 'textarea'].includes(item.type) && (
                <div className="grid grid-cols-3 gap-2">
                    {(['min_length', 'max_length'] as const).map((key) => (
                        <label key={key} className="grid gap-1">
                            <span>
                                {key === 'min_length' ? 'Shortest' : 'Longest'}
                            </span>
                            <Input
                                type="number"
                                min={0}
                                value={String(constraint[key] ?? '')}
                                onChange={(e) =>
                                    setConstraint(key, e.target.value)
                                }
                            />
                        </label>
                    ))}
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
            <RuleEditor item={item} definition={definition} onPatch={onPatch} />
            {issues.length > 0 && (
                <ul className="grid gap-1 text-destructive">
                    {issues.map((issue) => (
                        <li key={issue.path}>{issue.message}</li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-2 border-t pt-3">
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        onDefinition({
                            ...definition,
                            items: shiftItem(definition.items, item.name, -1),
                        })
                    }
                    aria-label="Move up"
                >
                    <ArrowUp className="size-4" />
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        onDefinition({
                            ...definition,
                            items: shiftItem(definition.items, item.name, 1),
                        })
                    }
                    aria-label="Move down"
                >
                    <ArrowDown className="size-4" />
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    onClick={() =>
                        onDefinition(duplicateItem(definition, item.name))
                    }
                >
                    <Copy className="size-4" /> Duplicate
                </Button>
                <Button
                    size="sm"
                    variant="destructive"
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
            <legend className="mb-1">Show only when</legend>
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
}: {
    formId: string;
    hasVersion: boolean;
    ready: boolean;
    acceptOldDays: number;
}) {
    const form = useForm({ note: '', accept_old_days: acceptOldDays });

    return (
        <Panel className="mt-3">
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
                    className="grid gap-3 md:grid-cols-[2fr_1fr_auto] md:items-end"
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
                        Publish
                    </Button>
                    {(form.errors as Record<string, string>).definition && (
                        <p className="text-sm text-destructive md:col-span-3">
                            {(form.errors as Record<string, string>).definition}
                        </p>
                    )}
                    {!ready && (
                        <p className="text-xs text-muted-foreground md:col-span-3">
                            Publish is available once the draft is saved, has no
                            issues and differs from the version in use.
                        </p>
                    )}
                </form>
            </PanelBody>
        </Panel>
    );
}
