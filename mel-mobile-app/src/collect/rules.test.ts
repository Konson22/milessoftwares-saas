/// <reference types="jest" />
/// <reference types="node" />
import { readFileSync } from 'fs';
import { resolve } from 'path';

import type { Answers, Definition } from './definition';
import { evaluate } from './rules';

/*
 * The phone's copy of the rules engine against the shared conformance
 * cases (ADR-0012 §4): the same file the server (Pest) and the web
 * (Vitest) run, so the phone accepts exactly what the server accepts.
 */

type Case = {
  name: string;
  form: string;
  today: string;
  answers: Answers;
  expect: { visible: string[]; required: string[]; answers: Answers; errors: Record<string, string> };
};

const data = JSON.parse(readFileSync(resolve(__dirname, '../../../contracts/collect/rules-cases.json'), 'utf8')) as {
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
