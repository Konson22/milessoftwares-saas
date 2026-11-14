<?php

use App\Collect\DefinitionCheck;
use App\Collect\Rules;

/**
 * The rules engine against the shared conformance cases (ADR-0012 §4).
 * resources/js/collect/rules.test.ts runs the same file.
 *
 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
 */
function conformanceCases(): array
{
    $file = dirname(__DIR__, 3).'/contracts/collect/rules-cases.json';
    $data = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];
    foreach ($data['cases'] as $case) {
        $cases[$case['name']] = [$data['forms'][$case['form']], $case];
    }

    return $cases;
}

dataset('conformance', fn () => conformanceCases());

it('evaluates each conformance case as specified', function (array $definition, array $case) {
    $result = Rules::evaluate($definition, $case['answers'], $case['today']);

    expect($result['visible'])->toBe($case['expect']['visible'])
        ->and($result['required'])->toBe($case['expect']['required'])
        ->and($result['errors'])->toEqual($case['expect']['errors'])
        ->and($result['answers'])->toEqual($case['expect']['answers']);
})->with('conformance');

it('accepts every form in the conformance file except where it tests evaluation only', function () {
    $file = dirname(__DIR__, 3).'/contracts/collect/rules-cases.json';
    $forms = json_decode((string) file_get_contents($file), true)['forms'];

    expect(DefinitionCheck::issues($forms['wash']))->toBe([])
        ->and(DefinitionCheck::issues($forms['chain']))->toBe([])
        ->and(DefinitionCheck::issues($forms['times']))->toBe([]);

    // "ops" deliberately compares text with a number and uses empty all/any,
    // which publishing refuses.
    $paths = array_column(DefinitionCheck::issues($forms['ops']), 'path');
    expect($paths)->toContain('items.17.relevant.value')
        ->and($paths)->toContain('items.25.relevant')
        ->and($paths)->toContain('items.26.relevant');
});

/**
 * @param  list<array<string, mixed>>  $items
 * @param  array<string, mixed>  $choices
 * @return array<string, mixed>
 */
function collectForm(array $items, array $choices = []): array
{
    return ['schema_version' => 1, 'settings' => ['title' => 'T'], 'items' => $items, 'choices' => $choices];
}

/**
 * @return list<string>
 */
function checkMessages(mixed $definition): array
{
    return array_column(DefinitionCheck::issues($definition), 'message');
}

it('refuses rules that refer to later questions, themselves or nothing', function () {
    $issues = DefinitionCheck::issues(collectForm([
        ['type' => 'integer', 'name' => 'a', 'label' => 'A', 'relevant' => ['q' => 'b', 'op' => 'gt', 'value' => 1]],
        ['type' => 'integer', 'name' => 'b', 'label' => 'B', 'required_if' => ['q' => 'b', 'op' => 'answered']],
        ['type' => 'integer', 'name' => 'c', 'label' => 'C', 'relevant' => ['q' => 'nope', 'op' => 'answered']],
    ]));

    expect(array_column($issues, 'path'))->toBe(['items.0.relevant.q', 'items.1.required_if.q', 'items.2.relevant.q'])
        ->and($issues[0]['message'])->toContain('not an earlier question')
        ->and($issues[2]['message'])->toContain('does not exist');
});

it('makes circular logic impossible', function () {
    // a shows when b is answered, b when a is: whichever comes first refers forward.
    $issues = DefinitionCheck::issues(collectForm([
        ['type' => 'text', 'name' => 'a', 'label' => 'A', 'relevant' => ['q' => 'b', 'op' => 'answered']],
        ['type' => 'text', 'name' => 'b', 'label' => 'B', 'relevant' => ['q' => 'a', 'op' => 'answered']],
    ]));

    expect(array_column($issues, 'path'))->toBe(['items.0.relevant.q']);
});

it('refuses duplicate names, unknown and future types, and unknown properties', function () {
    $issues = checkMessages(collectForm([
        ['type' => 'text', 'name' => 'a', 'label' => 'A'],
        ['type' => 'text', 'name' => 'a', 'label' => 'Again'],
        ['type' => 'signature', 'name' => 's', 'label' => 'Sign'],
        ['type' => 'slider', 'name' => 'x', 'label' => 'X'],
        ['type' => 'text', 'name' => '1bad', 'label' => 'Bad name'],
        ['type' => 'text', 'name' => 'c', 'label' => 'C', 'calculation' => '1+1'],
    ]));

    expect($issues)->toBe([
        'The name "a" is used twice. Names must be unique in the form.',
        'Signature questions are not available yet.',
        'Unknown question type.',
        'A name starts with a letter or "_" and uses letters, digits and "_" only (up to 64).',
        'Unknown property "calculation".',
    ]);
});

it('checks that comparisons suit the question compared', function () {
    $issues = DefinitionCheck::issues(collectForm([
        ['type' => 'select_one', 'name' => 's', 'label' => 'S', 'choices' => 'l'],
        ['type' => 'text', 'name' => 't', 'label' => 'T'],
        ['type' => 'photo', 'name' => 'p', 'label' => 'P'],
        ['type' => 'note', 'name' => 'n1', 'label' => 'N', 'relevant' => ['q' => 's', 'op' => 'eq', 'value' => 'zzz']],
        ['type' => 'note', 'name' => 'n2', 'label' => 'N', 'relevant' => ['q' => 't', 'op' => 'gt', 'value' => 'a']],
        ['type' => 'note', 'name' => 'n3', 'label' => 'N', 'relevant' => ['q' => 'p', 'op' => 'eq', 'value' => 'x']],
        ['type' => 'note', 'name' => 'n4', 'label' => 'N', 'relevant' => ['q' => 'p', 'op' => 'answered']],
        ['type' => 'note', 'name' => 'n5', 'label' => 'N', 'relevant' => ['q' => 's', 'op' => 'in', 'value' => ['a', 'b']]],
        ['type' => 'note', 'name' => 'n6', 'label' => 'N', 'relevant' => ['q' => 's', 'op' => 'like', 'value' => 'a']],
    ], ['l' => [['name' => 'a', 'label' => 'A'], ['name' => 'b', 'label' => 'B']]]));

    expect(array_column($issues, 'path'))->toBe(['items.3.relevant.value', 'items.4.relevant.op', 'items.5.relevant.op', 'items.8.relevant.op']);
});

it('checks constraints against the question type', function () {
    $issues = DefinitionCheck::issues(collectForm([
        ['type' => 'integer', 'name' => 'a', 'label' => 'A', 'constraint' => ['min' => 1.5, 'max' => 10]],
        ['type' => 'integer', 'name' => 'b', 'label' => 'B', 'constraint' => ['min' => 10, 'max' => 1]],
        ['type' => 'text', 'name' => 'c', 'label' => 'C', 'constraint' => ['min' => 1, 'pattern' => 'email']],
        ['type' => 'date', 'name' => 'd', 'label' => 'D', 'constraint' => ['min' => 'today', 'max' => 'today-7d']],
        ['type' => 'date', 'name' => 'e', 'label' => 'E', 'constraint' => ['max' => 'tomorrow']],
    ]));

    expect(array_column($issues, 'path'))->toBe([
        'items.0.constraint.min',
        'items.1.constraint.min',
        'items.2.constraint.min',
        'items.2.constraint.pattern',
        'items.3.constraint.min',
        'items.4.constraint.max',
    ]);
});

it('requires a title, a question, choices for choice questions and filled sections', function () {
    $issues = checkMessages(['schema_version' => 1, 'settings' => ['title' => ' '], 'items' => [
        ['type' => 'select_one', 'name' => 's', 'label' => 'S', 'choices' => 'missing'],
        ['type' => 'group', 'name' => 'g', 'label' => 'G', 'items' => []],
    ], 'choices' => []]);

    expect($issues)->toBe([
        'Give the form a title (up to 200 characters).',
        'Give the question its choices.',
        'This section is empty.',
    ]);

    expect(checkMessages(collectForm([['type' => 'note', 'name' => 'n', 'label' => 'Only a note']])))->toBe(['Add at least one question.'])
        ->and(checkMessages('not a form'))->toBe(['The form definition is not readable.']);
});

it('refuses duplicate choice values and unreadable rules', function () {
    $issues = checkMessages(collectForm([
        ['type' => 'select_one', 'name' => 's', 'label' => 'S', 'choices' => 'l'],
        ['type' => 'text', 'name' => 't', 'label' => 'T', 'relevant' => ['all' => [['q' => 's', 'op' => 'eq', 'value' => 'a']], 'any' => []]],
        ['type' => 'text', 'name' => 'u', 'label' => 'U', 'relevant' => 'expression()'],
    ], ['l' => [['name' => 'a', 'label' => 'A'], ['name' => 'a', 'label' => 'A again']]]));

    expect($issues)->toBe([
        'The value "a" appears twice in the list "l".',
        'The rule is not readable.',
        'The rule is not readable.',
    ]);
});
