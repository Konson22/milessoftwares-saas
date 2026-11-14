import { TEXTAREA_MAX, TEXT_MAX, choicesOf, isAnswerable } from './definition';
import type { Answers, Definition, Item } from './definition';

/*
 * The logic and validation engine (ADR-0012 §4), the same as
 * app/Collect/Rules.php, whose comment states the semantics in full. Both
 * run every case in contracts/collect/rules-cases.json; a change here is a
 * change there. Nothing in a definition is ever executed.
 *
 * No imports beyond ./definition: the field app carries an identical copy.
 */

export type ErrorCode =
    | 'required'
    | 'type'
    | 'choice'
    | 'min'
    | 'max'
    | 'min_length'
    | 'max_length'
    | 'pattern'
    | 'min_choices'
    | 'max_choices';

export type Evaluation = {
    /** Visible items (questions, notes and sections), in document order. */
    visible: string[];
    /** Visible questions that must be answered, in document order. */
    required: string[];
    /** The valid answers of visible questions. */
    answers: Answers;
    errors: Record<string, ErrorCode>;
};

export const PATTERNS: Record<string, RegExp> = {
    digits: /^[0-9]+$/,
    phone: /^\+?[0-9][0-9 ]{5,19}$/,
};

const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

export function evaluate(
    definition: Definition,
    answers: Answers,
    today: string,
): Evaluation {
    const state: Evaluation = {
        visible: [],
        required: [],
        answers: {},
        errors: {},
    };
    walk(definition.items, true, definition, answers, today, state);

    return state;
}

function walk(
    items: unknown,
    shown: boolean,
    definition: Definition,
    answers: Answers,
    today: string,
    state: Evaluation,
): void {
    if (!Array.isArray(items)) {
        return;
    }

    for (const item of items as Item[]) {
        if (!isObject(item) || typeof item.name !== 'string') {
            continue;
        }
        const name = item.name;
        const visible =
            shown &&
            (item.relevant === undefined || test(item.relevant, state.answers));

        if (visible) {
            state.visible.push(name);
        }

        if (item.type === 'group') {
            walk(item.items, visible, definition, answers, today, state);
            continue;
        }

        if (!visible || !isAnswerable(item.type)) {
            continue;
        }

        const required =
            item.required === true ||
            (item.required_if !== undefined &&
                test(item.required_if, state.answers));
        if (required) {
            state.required.push(name);
        }

        const answer = Object.prototype.hasOwnProperty.call(answers, name)
            ? answers[name]
            : undefined;
        if (isEmpty(answer)) {
            if (required) {
                state.errors[name] = 'required';
            }
            continue;
        }

        const error = check(item, answer, definition, today);
        if (error !== null) {
            state.errors[name] = error;
        } else {
            state.answers[name] = answer;
        }
    }
}

/** Whether a condition holds against the answers cleaned so far. */
export function test(condition: unknown, answers: Answers): boolean {
    if (!isObject(condition)) {
        return false;
    }

    if ('all' in condition) {
        const parts = Array.isArray(condition.all) ? condition.all : [];
        return parts.every((part) => test(part, answers));
    }
    if ('any' in condition) {
        const parts = Array.isArray(condition.any) ? condition.any : [];
        return parts.some((part) => test(part, answers));
    }
    if ('not' in condition) {
        return !test(condition.not, answers);
    }

    const op = condition.op;
    const q = condition.q;
    const answered =
        typeof q === 'string' && Object.prototype.hasOwnProperty.call(answers, q);
    const answer = answered ? answers[q] : undefined;
    const value = condition.value;

    if (op === 'answered') {
        return answered;
    }
    if (op === 'not_answered') {
        return !answered;
    }
    if (!answered) {
        return false;
    }

    switch (op) {
        case 'gt':
        case 'gte':
        case 'lt':
        case 'lte': {
            const order = compare(answer, value);
            if (order === null) {
                return false;
            }
            return op === 'gt'
                ? order > 0
                : op === 'gte'
                  ? order >= 0
                  : op === 'lt'
                    ? order < 0
                    : order <= 0;
        }
        case 'eq':
            return equals(answer, value);
        case 'ne':
            return !equals(answer, value);
        case 'includes':
            return Array.isArray(answer) && contains(answer, value);
        case 'in':
            return Array.isArray(value) && contains(value, answer);
        default:
            return false;
    }
}

export function isEmpty(answer: unknown): boolean {
    return (
        answer === undefined ||
        answer === null ||
        answer === '' ||
        (Array.isArray(answer) && answer.length === 0)
    );
}

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isNumber(value: unknown): value is number {
    return typeof value === 'number' && Number.isFinite(value);
}

function equals(a: unknown, b: unknown): boolean {
    if (isNumber(a) && isNumber(b)) {
        return a === b;
    }

    return typeof a === 'string' && typeof b === 'string' && a === b;
}

/** -1, 0 or 1; null when the two cannot be compared. */
function compare(a: unknown, b: unknown): number | null {
    if (isNumber(a) && isNumber(b)) {
        return a < b ? -1 : a > b ? 1 : 0;
    }
    if (typeof a === 'string' && typeof b === 'string') {
        return a < b ? -1 : a > b ? 1 : 0;
    }

    return null;
}

function contains(list: unknown[], value: unknown): boolean {
    return list.some((entry) => equals(entry, value));
}

/** Text length in code points, not UTF-16 units. */
function length(text: string): number {
    return [...text].length;
}

function check(
    question: Item,
    answer: unknown,
    definition: Definition,
    today: string,
): ErrorCode | null {
    const constraint = isObject(question.constraint) ? question.constraint : {};

    switch (question.type) {
        case 'text':
        case 'textarea': {
            if (typeof answer !== 'string') {
                return 'type';
            }
            const size = length(answer);
            const max =
                constraint.max_length ??
                (question.type === 'text' ? TEXT_MAX : TEXTAREA_MAX);
            if (
                constraint.min_length !== undefined &&
                size < constraint.min_length
            ) {
                return 'min_length';
            }
            if (size > max) {
                return 'max_length';
            }
            if (constraint.pattern !== undefined) {
                const pattern = PATTERNS[constraint.pattern];
                if (pattern === undefined || !pattern.test(answer)) {
                    return 'pattern';
                }
            }
            return null;
        }

        case 'integer':
        case 'decimal':
            if (
                !isNumber(answer) ||
                (question.type === 'integer' && !Number.isInteger(answer))
            ) {
                return 'type';
            }
            return range(answer, constraint.min, constraint.max);

        case 'select_one':
            if (typeof answer !== 'string') {
                return 'type';
            }
            return choicesOf(definition, question).some(
                (choice) => choice.name === answer,
            )
                ? null
                : 'choice';

        case 'yes_no':
            return answer === 'yes' || answer === 'no' ? null : 'type';

        case 'select_multiple': {
            if (
                !Array.isArray(answer) ||
                answer.some((choice) => typeof choice !== 'string') ||
                new Set(answer).size !== answer.length
            ) {
                return 'type';
            }
            const names = choicesOf(definition, question).map(
                (choice) => choice.name,
            );
            if (answer.some((choice) => !names.includes(choice as string))) {
                return 'choice';
            }
            if (
                constraint.min_choices !== undefined &&
                answer.length < constraint.min_choices
            ) {
                return 'min_choices';
            }
            if (
                constraint.max_choices !== undefined &&
                answer.length > constraint.max_choices
            ) {
                return 'max_choices';
            }
            return null;
        }

        case 'date':
            if (typeof answer !== 'string' || !isDate(answer)) {
                return 'type';
            }
            return range(
                answer,
                bound(constraint.min, today),
                bound(constraint.max, today),
            );

        case 'time':
            if (typeof answer !== 'string' || !TIME.test(answer)) {
                return 'type';
            }
            return range(answer, constraint.min, constraint.max);

        case 'datetime':
            if (
                typeof answer !== 'string' ||
                answer.length !== 16 ||
                answer[10] !== 'T' ||
                !isDate(answer.slice(0, 10)) ||
                !TIME.test(answer.slice(11))
            ) {
                return 'type';
            }
            return range(
                answer.slice(0, 10),
                bound(constraint.min, today),
                bound(constraint.max, today),
            );

        case 'geopoint':
            if (
                !isObject(answer) ||
                !isNumber(answer.lat) ||
                !isNumber(answer.lng) ||
                Math.abs(answer.lat) > 90 ||
                Math.abs(answer.lng) > 180 ||
                (answer.accuracy !== undefined &&
                    answer.accuracy !== null &&
                    (!isNumber(answer.accuracy) || answer.accuracy < 0))
            ) {
                return 'type';
            }
            return null;

        case 'photo':
            return typeof answer === 'string' && /^[0-9a-f]{64}$/.test(answer)
                ? null
                : 'type';
    }

    return 'type';
}

function range(answer: unknown, min: unknown, max: unknown): ErrorCode | null {
    if (min !== undefined && min !== null && (compare(answer, min) ?? 0) < 0) {
        return 'min';
    }
    if (max !== undefined && max !== null && (compare(answer, max) ?? 0) > 0) {
        return 'max';
    }

    return null;
}

export function isDate(value: string): boolean {
    const match = DATE.exec(value);
    if (match === null) {
        return false;
    }
    const [year, month, day] = [
        Number(match[1]),
        Number(match[2]),
        Number(match[3]),
    ];
    const date = new Date(Date.UTC(year, month - 1, day));

    return (
        year >= 1 &&
        date.getUTCFullYear() === year &&
        date.getUTCMonth() === month - 1 &&
        date.getUTCDate() === day
    );
}

/** A date bound as a date: "today", "today-30d" and "today+7d" resolved. */
export function bound(value: unknown, today: string): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    if (value === 'today') {
        return today;
    }
    const relative = /^today([+-])(\d{1,4})d$/.exec(value);
    if (relative !== null) {
        if (!isDate(today)) {
            return null;
        }
        const date = new Date(`${today}T00:00:00Z`);
        const days = Number(relative[2]) * (relative[1] === '+' ? 1 : -1);
        date.setUTCDate(date.getUTCDate() + days);

        return date.toISOString().slice(0, 10);
    }

    return isDate(value) ? value : null;
}
