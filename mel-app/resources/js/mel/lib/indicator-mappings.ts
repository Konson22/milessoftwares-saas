import type { Tone } from '@miles/ui/components/status-badge';

/*
 * Indicators fed by collected data (ADR-0019 §26): what the mapping and
 * calculation pages share, and how a mapping reads in plain words.
 */

export type Calculation = 'count' | 'sum' | 'average' | 'percentage';

export type Op =
    | 'eq'
    | 'ne'
    | 'gt'
    | 'gte'
    | 'lt'
    | 'lte'
    | 'answered'
    | 'not_answered'
    | 'includes'
    | 'in';

/** An answer a condition compares with: a choice, a number, a date, or a list ("in"). */
export type LeafValue = string | number | boolean | null | (string | number)[];

export type Leaf = { q: string; op: Op; value?: LeafValue };

export type Condition =
    | Leaf
    | { all: Condition[] }
    | { any: Condition[] }
    | { not: Condition };

export type Question = {
    name: string;
    label: string;
    type: string;
    choices: { value: string; label: string }[];
};

export const CALCULATIONS: Record<
    Calculation,
    { label: string; explain: string }
> = {
    count: {
        label: 'Count submissions',
        explain: 'How many accepted submissions meet the condition.',
    },
    sum: {
        label: 'Add up a number',
        explain:
            'The total of a number question over the accepted submissions, such as people in the household.',
    },
    average: {
        label: 'Average of a number',
        explain:
            'The total of a number question divided by the number of submissions (sent as both figures).',
    },
    percentage: {
        label: 'Percentage of submissions',
        explain:
            'Out of the accepted submissions that count, the share that meet a second condition.',
    },
};

export const OPERATORS: Record<Op, string> = {
    eq: 'is',
    ne: 'is not',
    gt: 'is more than',
    gte: 'is at least',
    lt: 'is less than',
    lte: 'is at most',
    answered: 'is answered',
    not_answered: 'is not answered',
    includes: 'includes',
    in: 'is one of',
};

const ORDERED = ['integer', 'decimal', 'date', 'time', 'datetime'];
const CHOICE = ['select_one', 'select_multiple', 'yes_no'];

/** The comparisons that suit a question, as the server allows them. */
export function operatorsFor(type: string): Op[] {
    if (type === 'select_multiple') {
        return ['includes', 'answered', 'not_answered'];
    }

    if (ORDERED.includes(type)) {
        return [
            'eq',
            'ne',
            'gt',
            'gte',
            'lt',
            'lte',
            'answered',
            'not_answered',
        ];
    }

    return ['eq', 'ne', 'answered', 'not_answered'];
}

export function needsValue(op: Op): boolean {
    return op !== 'answered' && op !== 'not_answered';
}

export function isChoice(type: string): boolean {
    return CHOICE.includes(type);
}

export function isNumber(type: string): boolean {
    return type === 'integer' || type === 'decimal';
}

export function isLeaf(condition: Condition): condition is Leaf {
    return 'q' in condition;
}

/** The leaves of a condition the editor can show: one, or several joined by "and". */
export function leavesOf(condition: Condition | null): Leaf[] | null {
    if (condition === null) {
        return [];
    }

    if (isLeaf(condition)) {
        return [condition];
    }

    if ('all' in condition && condition.all.every((part) => isLeaf(part))) {
        return condition.all as Leaf[];
    }

    return null;
}

export function joinLeaves(leaves: Leaf[]): Condition | null {
    if (leaves.length === 0) {
        return null;
    }

    return leaves.length === 1 ? leaves[0] : { all: leaves };
}

function valueText(leaf: Leaf, question: Question | undefined): string {
    const value = leaf.value;
    const label = (item: unknown) =>
        question?.choices.find((choice) => choice.value === String(item))
            ?.label ?? String(item);

    return Array.isArray(value) ? value.map(label).join(' or ') : label(value);
}

/** "Safe drinking water? is No and People is at least 3". */
export function describeCondition(
    condition: Condition | null,
    questions: Question[],
): string {
    if (condition === null) {
        return '';
    }

    if (isLeaf(condition)) {
        const question = questions.find((item) => item.name === condition.q);
        const name = `"${question?.label ?? condition.q}"`;

        return needsValue(condition.op)
            ? `${name} ${OPERATORS[condition.op]} ${valueText(condition, question)}`
            : `${name} ${OPERATORS[condition.op]}`;
    }

    if ('all' in condition) {
        return condition.all
            .map((part) => describeCondition(part, questions))
            .join(' and ');
    }

    if ('any' in condition) {
        return `(${condition.any.map((part) => describeCondition(part, questions)).join(' or ')})`;
    }

    return `not (${describeCondition(condition.not, questions)})`;
}

export type MappingRules = {
    calculation: Calculation;
    question: string | null;
    condition: Condition | null;
    numeratorCondition: Condition | null;
    distinctBy: string | null;
};

/** What the mapping does, in one or two sentences. */
export function describeMapping(
    mapping: MappingRules,
    questions: Question[],
): string {
    const label = (name: string | null) =>
        `"${questions.find((item) => item.name === name)?.label ?? name}"`;
    const where = mapping.condition
        ? ` where ${describeCondition(mapping.condition, questions)}`
        : '';

    const main = {
        count: `Counts accepted submissions${where}.`,
        sum: `Adds up ${label(mapping.question)} over accepted submissions${where}.`,
        average: `Averages ${label(mapping.question)} over accepted submissions${where}.`,
        percentage: `Of the accepted submissions${where}, the percentage where ${describeCondition(mapping.numeratorCondition, questions)}.`,
    }[mapping.calculation];

    return mapping.distinctBy
        ? `${main} Repeat visits with the same ${label(mapping.distinctBy)} count once, by the latest visit.`
        : main;
}

export type RunStatus = 'sent' | 'superseded';

export const RUN_STATUS: Record<RunStatus, { label: string; tone: Tone }> = {
    sent: { label: 'Current', tone: 'success' },
    superseded: { label: 'Replaced', tone: 'neutral' },
};

export type Figure = {
    value: string | null;
    numerator: string | null;
    denominator: string | null;
};

export function figureText(figure: Figure, calculation?: Calculation): string {
    if (figure.value !== null) {
        return figure.value;
    }

    if (figure.numerator === null || figure.denominator === null) {
        return '–';
    }

    const share =
        Number(figure.denominator) === 0
            ? null
            : Number(figure.numerator) / Number(figure.denominator);
    const shown =
        share === null
            ? ''
            : calculation === 'percentage'
              ? ` (${(share * 100).toLocaleString(undefined, { maximumFractionDigits: 1 })}%)`
              : ` (${share.toLocaleString(undefined, { maximumFractionDigits: 2 })})`;

    return `${figure.numerator} / ${figure.denominator}${shown}`;
}
