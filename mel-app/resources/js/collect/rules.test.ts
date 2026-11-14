import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vite-plus/test';
import type { Answers, Definition } from './definition';
import { evaluate } from './rules';

/*
 * The rules engine against the shared conformance cases (ADR-0012 §4).
 * tests/Unit/CollectRulesTest.php runs the same file against the PHP engine.
 */

type Case = {
    name: string;
    form: string;
    today: string;
    answers: Answers;
    expect: {
        visible: string[];
        required: string[];
        answers: Answers;
        errors: Record<string, string>;
    };
};

const file = resolve(
    import.meta.dirname,
    '../../../../contracts/collect/rules-cases.json',
);
const data = JSON.parse(readFileSync(file, 'utf8')) as {
    forms: Record<string, Definition>;
    cases: Case[];
};

describe('conformance', () => {
    it.each(data.cases.map((c) => [c.name, c] as const))('%s', (_, c) => {
        const result = evaluate(data.forms[c.form], c.answers, c.today);

        expect(result.visible).toEqual(c.expect.visible);
        expect(result.required).toEqual(c.expect.required);
        expect(result.errors).toEqual(c.expect.errors);
        expect(result.answers).toEqual(c.expect.answers);
    });
});
