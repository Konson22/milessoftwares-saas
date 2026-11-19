<?php

use App\Logbook\Fields;

/*
 * A number field's normal range: a reading outside it is recorded and
 * flagged, never refused (that is what the hard min / max are for).
 */

function fridgeSheet(array $extra = []): array
{
    return Fields::check([[
        'label' => 'Fridge temperature', 'name' => 'temp', 'type' => 'decimal', 'required' => true,
        'unit' => ' °C ', 'expected_min' => '2', 'expected_max' => '8', 'min' => '-40', 'max' => '60', ...$extra,
    ]]);
}

it('keeps the unit and the normal range on number fields', function () {
    $checked = fridgeSheet();

    expect($checked['errors'])->toBe([])
        ->and($checked['fields'][0])->toMatchArray(['unit' => '°C', 'expected_min' => 2, 'expected_max' => 8]);
});

it('refuses a normal range whose top is below its bottom', function () {
    expect(fridgeSheet(['expected_min' => '9'])['errors'])->toHaveKey('fields.0.expected_max');
});

it('drops range settings from types that do not take them', function () {
    $checked = Fields::check([['label' => 'Notes', 'name' => 'notes', 'type' => 'text', 'expected_min' => 1, 'unit' => 'kg']]);

    expect($checked['fields'][0])->not->toHaveKey('expected_min')->not->toHaveKey('unit');
});

it('accepts a reading outside the normal range and flags it', function () {
    $fields = fridgeSheet()['fields'];

    expect(Fields::value($fields[0], '10.5')[1])->toBeNull()
        ->and(Fields::deviations($fields, ['temp' => '10.5']))->toBe([[
            'name' => 'temp', 'label' => 'Fridge temperature', 'value' => 10.5, 'unit' => '°C',
            'direction' => 'high', 'range' => '2 to 8 °C',
        ]])
        ->and(Fields::deviations($fields, ['temp' => '1'])[0]['direction'])->toBe('low')
        ->and(Fields::deviations($fields, ['temp' => '5']))->toBe([])
        ->and(Fields::deviations($fields, ['temp' => null]))->toBe([]);
});

it('still refuses values beyond the hard limits', function () {
    expect(Fields::value(fridgeSheet()['fields'][0], '99')[1])->toBe('At most 60.');
});

it('describes one-sided ranges', function () {
    expect(Fields::rangeText(['expected_min' => 2, 'unit' => 'bar']))->toBe('at least 2 bar')
        ->and(Fields::rangeText(['expected_max' => 8]))->toBe('at most 8');
});
