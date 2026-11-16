import { flatten, isAnswerable } from './definition';
import type {
    AnswerableType,
    Choice,
    Condition,
    Definition,
    Item,
    ItemType,
    Leaf,
    Operator,
} from './definition';

/*
 * What the form builder does to a definition, as pure functions: add,
 * move, change, rename and remove items. Names are unique in a form, so
 * items are found by name.
 */

export type Position = 'before' | 'after' | 'inside';

function nameBase(type: ItemType): string {
    return type === 'group' ? 'section_' : type === 'note' ? 'note_' : 'q';
}

/** A name no item uses yet: q1, q2…, section_1…, note_1… */
export function freshName(definition: Definition, type: ItemType): string {
    const used = new Set(flatten(definition.items).map((item) => item.name));
    const base = nameBase(type);
    let n = 1;
    while (used.has(`${base}${n}`)) {
        n++;
    }

    return `${base}${n}`;
}

/** Whether a name is still the one freshName gave (so it may follow the label). */
export function isGeneratedName(name: string): boolean {
    return /^(q|section_|note_)\d+$/.test(name);
}

/** A name made from a label: "Main water source?" → "main_water_source". */
export function nameFromLabel(
    definition: Definition,
    label: string,
    own: string,
): string {
    let base = label
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 40)
        .replace(/_+$/g, '');
    if (base === '') {
        return own;
    }
    if (/^[0-9]/.test(base)) {
        base = `_${base}`;
    }
    const used = new Set(
        flatten(definition.items)
            .map((item) => item.name)
            .filter((name) => name !== own),
    );
    let name = base;
    let n = 2;
    while (used.has(name)) {
        name = `${base}_${n++}`;
    }

    return name;
}

/** A new item of a type, with its choice list when it needs one. */
export function newItem(
    definition: Definition,
    type: ItemType,
): { item: Item; definition: Definition } {
    const name = freshName(definition, type);
    const item: Item = { type, name, label: '' };

    if (type === 'group') {
        item.items = [];
    }
    if (type === 'select_one' || type === 'select_multiple') {
        item.choices = name;
        return {
            item,
            definition: {
                ...definition,
                choices: {
                    ...definition.choices,
                    [name]: [
                        { name: 'option_1', label: 'Option 1' },
                        { name: 'option_2', label: 'Option 2' },
                    ],
                },
            },
        };
    }

    return { item, definition };
}

export function findItem(items: Item[], name: string): Item | undefined {
    return flatten(items).find((item) => item.name === name);
}

/** The item and everything inside it. */
function contains(item: Item, name: string): boolean {
    return (
        item.name === name ||
        flatten(item.items ?? []).some((child) => child.name === name)
    );
}

/** The items without the named one; and the item taken out. */
export function removeItem(
    items: Item[],
    name: string,
): [Item[], Item | undefined] {
    let removed: Item | undefined;
    const walk = (list: Item[]): Item[] =>
        list.flatMap((item) => {
            if (item.name === name) {
                removed = item;
                return [];
            }
            return item.type === 'group'
                ? [{ ...item, items: walk(item.items ?? []) }]
                : [item];
        });
    const rest = walk(items);

    return [rest, removed];
}

/**
 * Puts an item before or after a target, or at the end of a section
 * ("inside"). Without a target it goes at the end of the form.
 */
export function insertItem(
    items: Item[],
    item: Item,
    target: string | null,
    position: Position,
): Item[] {
    if (target === null) {
        return [...items, item];
    }
    let placed = false;
    const walk = (list: Item[]): Item[] =>
        list.flatMap((current) => {
            if (current.name === target) {
                placed = true;
                if (position === 'inside' && current.type === 'group') {
                    return [
                        { ...current, items: [...(current.items ?? []), item] },
                    ];
                }
                return position === 'before'
                    ? [item, current]
                    : [current, item];
            }
            return current.type === 'group'
                ? [{ ...current, items: walk(current.items ?? []) }]
                : [current];
        });
    const result = walk(items);

    return placed ? result : [...items, item];
}

/** Moves an item; refused (unchanged) when a section would go inside itself. */
export function moveItem(
    items: Item[],
    name: string,
    target: string | null,
    position: Position,
): Item[] {
    if (target === name) {
        return items;
    }
    const moving = findItem(items, name);
    if (!moving || (target !== null && contains(moving, target))) {
        return items;
    }
    const [rest, removed] = removeItem(items, name);

    return removed ? insertItem(rest, removed, target, position) : items;
}

/** Moves an item one place up or down among its siblings. */
export function shiftItem(items: Item[], name: string, by: -1 | 1): Item[] {
    const walk = (list: Item[]): Item[] => {
        const index = list.findIndex((item) => item.name === name);
        if (index >= 0) {
            const to = index + by;
            if (to < 0 || to >= list.length) {
                return list;
            }
            const copy = [...list];
            [copy[index], copy[to]] = [copy[to], copy[index]];
            return copy;
        }
        return list.map((item) =>
            item.type === 'group'
                ? { ...item, items: walk(item.items ?? []) }
                : item,
        );
    };

    return walk(items);
}

export function updateItem(
    items: Item[],
    name: string,
    patch: Partial<Item>,
): Item[] {
    return items.map((item) => {
        if (item.name === name) {
            const next = { ...item, ...patch };
            for (const key of Object.keys(patch) as (keyof Item)[]) {
                if (patch[key] === undefined) {
                    delete next[key];
                }
            }
            return next;
        }
        return item.type === 'group'
            ? { ...item, items: updateItem(item.items ?? [], name, patch) }
            : item;
    });
}

function renameInCondition(
    condition: Condition,
    from: string,
    to: string,
): Condition {
    if ('all' in condition) {
        return {
            all: condition.all.map((part) => renameInCondition(part, from, to)),
        };
    }
    if ('any' in condition) {
        return {
            any: condition.any.map((part) => renameInCondition(part, from, to)),
        };
    }
    if ('not' in condition) {
        return { not: renameInCondition(condition.not, from, to) };
    }

    return condition.q === from ? { ...condition, q: to } : condition;
}

/** Renames a question, and every rule that refers to it. */
export function renameItem(
    definition: Definition,
    from: string,
    to: string,
): Definition {
    const walk = (list: Item[]): Item[] =>
        list.map((item) => {
            const next: Item = {
                ...item,
                name: item.name === from ? to : item.name,
            };
            if (item.relevant) {
                next.relevant = renameInCondition(item.relevant, from, to);
            }
            if (item.required_if) {
                next.required_if = renameInCondition(
                    item.required_if,
                    from,
                    to,
                );
            }
            if (item.type === 'group') {
                next.items = walk(item.items ?? []);
            }
            return next;
        });

    return { ...definition, items: walk(definition.items) };
}

/** Removes an item, and choice lists no other question uses. */
export function deleteItem(definition: Definition, name: string): Definition {
    const [items] = removeItem(definition.items, name);
    const used = new Set(
        flatten(items)
            .map((item) => item.choices)
            .filter((list): list is string => typeof list === 'string'),
    );
    const choices = Object.fromEntries(
        Object.entries(definition.choices ?? {}).filter(([list]) =>
            used.has(list),
        ),
    );

    return { ...definition, items, choices };
}

/** A copy of an item (and its contents) under new names, placed after it. */
export function duplicateItem(
    definition: Definition,
    name: string,
): Definition {
    const original = findItem(definition.items, name);
    if (!original) {
        return definition;
    }
    const used = new Set(flatten(definition.items).map((item) => item.name));
    const choices = { ...definition.choices };
    const fresh = (type: ItemType): string => {
        const base = nameBase(type);
        let n = 1;
        while (used.has(`${base}${n}`)) {
            n++;
        }
        used.add(`${base}${n}`);
        return `${base}${n}`;
    };
    const copy = (item: Item): Item => {
        const clone: Item = { ...item, name: fresh(item.type) };
        if (item.choices) {
            clone.choices = clone.name;
            choices[clone.name] = [...(definition.choices[item.choices] ?? [])];
        }
        if (item.type === 'group') {
            clone.items = (item.items ?? []).map(copy);
        }
        return clone;
    };

    return {
        ...definition,
        choices,
        items: insertItem(definition.items, copy(original), name, 'after'),
    };
}

/** The questions before an item, which its rules may use. */
export function earlierQuestions(definition: Definition, name: string): Item[] {
    const out: Item[] = [];
    for (const item of flatten(definition.items)) {
        if (item.name === name) {
            break;
        }
        if (isAnswerable(item.type)) {
            out.push(item);
        }
    }

    return out;
}

export const OPERATORS: Record<
    AnswerableType,
    { op: Operator; label: string }[]
> = (() => {
    const presence = [
        { op: 'answered' as const, label: 'is answered' },
        { op: 'not_answered' as const, label: 'is not answered' },
    ];
    const text = [
        { op: 'eq' as const, label: 'is' },
        { op: 'ne' as const, label: 'is not' },
        ...presence,
    ];
    const number = [
        { op: 'eq' as const, label: '=' },
        { op: 'ne' as const, label: '≠' },
        { op: 'gt' as const, label: '>' },
        { op: 'gte' as const, label: '≥' },
        { op: 'lt' as const, label: '<' },
        { op: 'lte' as const, label: '≤' },
        ...presence,
    ];
    const choice = [
        { op: 'eq' as const, label: 'is' },
        { op: 'ne' as const, label: 'is not' },
        { op: 'in' as const, label: 'is one of' },
        ...presence,
    ];
    const when = [
        { op: 'eq' as const, label: 'is' },
        { op: 'lt' as const, label: 'is before' },
        { op: 'lte' as const, label: 'is on or before' },
        { op: 'gt' as const, label: 'is after' },
        { op: 'gte' as const, label: 'is on or after' },
        ...presence,
    ];

    return {
        text,
        textarea: text,
        integer: number,
        decimal: number,
        select_one: choice,
        yes_no: choice,
        select_multiple: [
            { op: 'includes' as const, label: 'includes' },
            ...presence,
        ],
        date: when,
        time: when,
        datetime: when,
        geopoint: presence,
        photo: presence,
    };
})();

/** A simple rule the builder can edit: one leaf, or all/any of leaves. */
export type SimpleRule = { join: 'all' | 'any'; leaves: Leaf[] };

function isLeaf(condition: unknown): condition is Leaf {
    return (
        typeof condition === 'object' &&
        condition !== null &&
        'q' in condition &&
        'op' in condition
    );
}

/** The rule as the builder edits it; null when it is too complex for the editor. */
export function toSimple(condition: Condition | undefined): SimpleRule | null {
    if (condition === undefined) {
        return { join: 'all', leaves: [] };
    }
    if (isLeaf(condition)) {
        return { join: 'all', leaves: [condition] };
    }
    if ('all' in condition && condition.all.every(isLeaf)) {
        return { join: 'all', leaves: condition.all as Leaf[] };
    }
    if ('any' in condition && condition.any.every(isLeaf)) {
        return { join: 'any', leaves: condition.any as Leaf[] };
    }

    return null;
}

export function fromSimple(rule: SimpleRule): Condition | undefined {
    if (rule.leaves.length === 0) {
        return undefined;
    }
    if (rule.leaves.length === 1) {
        return rule.leaves[0];
    }

    return rule.join === 'all' ? { all: rule.leaves } : { any: rule.leaves };
}

/** A choice value made from its label, unique in the list. */
export function choiceName(
    list: Choice[],
    label: string,
    own?: string,
): string {
    const base =
        label
            .normalize('NFKD')
            .replace(/[̀-ͯ]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '_')
            .replace(/^_+|_+$/g, '')
            .slice(0, 40) || 'option';
    const used = new Set(
        list.map((choice) => choice.name).filter((name) => name !== own),
    );
    let name = base;
    let n = 2;
    while (used.has(name)) {
        name = `${base}_${n++}`;
    }

    return name;
}
