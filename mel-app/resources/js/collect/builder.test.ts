import { describe, expect, it } from 'vite-plus/test';
import {
    deleteItem,
    duplicateItem,
    earlierQuestions,
    fromSimple,
    moveItem,
    nameFromLabel,
    newItem,
    renameItem,
    shiftItem,
    toSimple,
} from './builder';
import { flatten } from './definition';
import type { Definition } from './definition';

const form = (): Definition => ({
    schema_version: 1,
    settings: { title: 'T' },
    items: [
        { type: 'yes_no', name: 'safe', label: 'Safe water?' },
        {
            type: 'group',
            name: 'hh',
            label: 'Household',
            relevant: { q: 'safe', op: 'eq', value: 'no' },
            items: [
                { type: 'select_one', name: 'source', label: 'Source', choices: 'source' },
                { type: 'integer', name: 'members', label: 'Members' },
            ],
        },
        {
            type: 'text',
            name: 'why',
            label: 'Why?',
            relevant: { all: [{ q: 'safe', op: 'eq', value: 'no' }, { q: 'source', op: 'answered' }] },
        },
    ],
    choices: { source: [{ name: 'river', label: 'River' }] },
});

const names = (definition: Definition) => flatten(definition.items).map((item) => item.name);

describe('the form builder', () => {
    it('moves items around and into sections, never a section into itself', () => {
        const items = form().items;

        expect(names({ ...form(), items: moveItem(items, 'why', 'hh', 'inside') })).toEqual(['safe', 'hh', 'source', 'members', 'why']);
        expect(names({ ...form(), items: moveItem(items, 'safe', 'members', 'after') })).toEqual(['hh', 'source', 'members', 'safe', 'why']);
        expect(moveItem(items, 'hh', 'members', 'before')).toBe(items);
        expect(names({ ...form(), items: shiftItem(items, 'members', -1) })).toEqual(['safe', 'hh', 'members', 'source', 'why']);
        expect(shiftItem(items, 'safe', -1)).toEqual(items);
    });

    it('renames a question and every rule that uses it', () => {
        const renamed = renameItem(form(), 'safe', 'safe_water');

        expect(renamed.items[0].name).toBe('safe_water');
        expect(renamed.items[1].relevant).toEqual({ q: 'safe_water', op: 'eq', value: 'no' });
        expect(renamed.items[2].relevant).toEqual({
            all: [
                { q: 'safe_water', op: 'eq', value: 'no' },
                { q: 'source', op: 'answered' },
            ],
        });
    });

    it('drops choice lists nobody uses any more', () => {
        const without = deleteItem(form(), 'hh');

        expect(names(without)).toEqual(['safe', 'why']);
        expect(without.choices).toEqual({});
    });

    it('duplicates under new names, with its own choice list', () => {
        const copied = duplicateItem(form(), 'hh');

        expect(names(copied)).toEqual(['safe', 'hh', 'source', 'members', 'section_1', 'q1', 'q2', 'why']);
        expect(copied.choices.q1).toEqual([{ name: 'river', label: 'River' }]);
        expect(new Set(names(copied)).size).toBe(names(copied).length);
    });

    it('makes unique names from labels', () => {
        expect(nameFromLabel(form(), 'Main water source?', 'q9')).toBe('main_water_source');
        expect(nameFromLabel(form(), 'Members', 'q9')).toBe('members_2');
        expect(nameFromLabel(form(), 'Members', 'members')).toBe('members');
        expect(nameFromLabel(form(), '2 or more?', 'q9')).toBe('_2_or_more');
        expect(nameFromLabel(form(), 'Écoles', 'q9')).toBe('ecoles');
        expect(nameFromLabel(form(), '???', 'q9')).toBe('q9');
    });

    it('gives new choice questions a list of their own', () => {
        const { item, definition } = newItem(form(), 'select_multiple');

        expect(item.name).toBe('q1');
        expect(item.choices).toBe('q1');
        expect(definition.choices.q1).toHaveLength(2);
    });

    it('offers only earlier questions to a rule', () => {
        expect(earlierQuestions(form(), 'members').map((item) => item.name)).toEqual(['safe', 'source']);
    });

    it('edits simple rules and leaves complex ones alone', () => {
        expect(toSimple(undefined)).toEqual({ join: 'all', leaves: [] });
        expect(toSimple({ q: 'a', op: 'answered' })).toEqual({ join: 'all', leaves: [{ q: 'a', op: 'answered' }] });
        expect(toSimple({ not: { q: 'a', op: 'answered' } })).toBeNull();
        expect(fromSimple({ join: 'any', leaves: [] })).toBeUndefined();
        expect(fromSimple({ join: 'any', leaves: [{ q: 'a', op: 'answered' }] })).toEqual({ q: 'a', op: 'answered' });
        expect(
            fromSimple({
                join: 'any',
                leaves: [
                    { q: 'a', op: 'answered' },
                    { q: 'b', op: 'answered' },
                ],
            }),
        ).toEqual({
            any: [
                { q: 'a', op: 'answered' },
                { q: 'b', op: 'answered' },
            ],
        });
    });
});
