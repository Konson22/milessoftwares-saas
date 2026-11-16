/*
 * The form definition, schema_version 1 (ADR-0012 §3): the same shapes as
 * app/Collect/Definition.php. Plain TypeScript with no imports, so the
 * field app can carry an identical copy.
 */

export type AnswerableType =
    | 'text'
    | 'textarea'
    | 'integer'
    | 'decimal'
    | 'select_one'
    | 'select_multiple'
    | 'yes_no'
    | 'date'
    | 'time'
    | 'datetime'
    | 'geopoint'
    | 'photo';

export type ItemType = AnswerableType | 'note' | 'group';

export const ANSWERABLE: readonly AnswerableType[] = [
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
];

export type Operator =
    | 'eq'
    | 'ne'
    | 'gt'
    | 'gte'
    | 'lt'
    | 'lte'
    | 'includes'
    | 'in'
    | 'answered'
    | 'not_answered';

export type Leaf = { q: string; op: Operator; value?: unknown };

export type Condition =
    | { all: Condition[] }
    | { any: Condition[] }
    | { not: Condition }
    | Leaf;

export type Constraint = {
    min?: number | string;
    max?: number | string;
    min_length?: number;
    max_length?: number;
    pattern?: 'digits' | 'phone';
    min_choices?: number;
    max_choices?: number;
};

export type Item = {
    type: ItemType;
    name: string;
    label: string;
    hint?: string;
    required?: boolean;
    required_if?: Condition;
    relevant?: Condition;
    constraint?: Constraint;
    /** The choice list's name (select questions). */
    choices?: string;
    /** Answers are personal data (names, phone numbers, ID numbers). */
    personal?: boolean;
    /** Sections only. */
    items?: Item[];
};

export type Choice = { name: string; label: string };

export type Definition = {
    schema_version: 1;
    settings: { title: string; consent?: { text: string } };
    items: Item[];
    choices: Record<string, Choice[]>;
};

export type GeoPoint = { lat: number; lng: number; accuracy?: number };

export type Answer = string | number | string[] | GeoPoint;

export type Answers = Record<string, unknown>;

export const TEXT_MAX = 1000;
export const TEXTAREA_MAX = 10000;

export function isAnswerable(type: string): type is AnswerableType {
    return (ANSWERABLE as readonly string[]).includes(type);
}

/** Every item in document order, sections before their contents. */
export function flatten(items: Item[]): Item[] {
    const out: Item[] = [];
    const walk = (list: Item[]) => {
        for (const item of list) {
            out.push(item);
            if (item.type === 'group' && Array.isArray(item.items)) {
                walk(item.items);
            }
        }
    };
    walk(items);

    return out;
}

/** The choices of a select or yes/no question. */
export function choicesOf(definition: Definition, item: Item): Choice[] {
    if (item.type === 'yes_no') {
        return [
            { name: 'yes', label: 'Yes' },
            { name: 'no', label: 'No' },
        ];
    }

    const list = item.choices ? definition.choices?.[item.choices] : undefined;

    return Array.isArray(list) ? list : [];
}
