<?php

use App\Mel\Calculator;
use App\Mel\Numbers;
use App\Mel\Performance;
use App\Models\IndicatorVersion;

function version(string $measure, string $time, string $units, ?int $multiplier = null): IndicatorVersion
{
    return new IndicatorVersion([
        'measure' => $measure,
        'time_aggregation' => $time,
        'unit_aggregation' => $units,
        'rate_multiplier' => $multiplier,
    ]);
}

it('parses numbers exactly and refuses what is not one', function () {
    expect(Numbers::parse('1,250.5'))->toBe('1250.500000')
        ->and(Numbers::parse(' 42 '))->toBe('42.000000')
        ->and(Numbers::parse(7))->toBe('7.000000')
        ->and(Numbers::parse(0.1))->toBe('0.100000')
        ->and(Numbers::parse('-3.25'))->toBe('-3.250000')
        ->and(Numbers::parse(''))->toBeNull()
        ->and(Numbers::parse('12abc'))->toBeNull()
        ->and(Numbers::parse('1.1234567'))->toBeNull()
        ->and(Numbers::parse('123456789012345'))->toBeNull()
        ->and(Numbers::parse(NAN))->toBeNull();
});

it('rounds half up and trims', function () {
    expect(Numbers::round('2.345000', 2))->toBe('2.35')
        ->and(Numbers::round('-2.345000', 2))->toBe('-2.35')
        ->and(Numbers::round('-0.001000', 2))->toBe('0.00')
        ->and(Numbers::trim('12.500000'))->toBe('12.5')
        ->and(Numbers::trim('3.000000'))->toBe('3');
});

it('adds counts across units, and leaves out a total when a unit has no value', function () {
    $count = version('count', 'sum', 'sum');

    expect(Calculator::acrossUnits($count, [['value' => '120.000000'], ['value' => '80.000000']]))
        ->toMatchArray(['value' => '200.000000', 'complete' => true]);
    expect(Calculator::acrossUnits($count, [['value' => '120.000000'], ['value' => null]]))
        ->toMatchArray(['value' => null, 'complete' => false]);
});

it('recomputes percentages from their parts instead of averaging them', function () {
    $percentage = version('percentage', 'recompute', 'recompute');

    // 3 of 4 (75 %) and 1 of 100 (1 %): 4 of 104, not the 38 % an average gives.
    $combined = Calculator::acrossUnits($percentage, [
        ['numerator' => '3', 'denominator' => '4'],
        ['numerator' => '1', 'denominator' => '100'],
    ]);

    expect($combined)->toMatchArray(['numerator' => '4.000000', 'denominator' => '104.000000', 'complete' => true])
        ->and(Numbers::round((string) $combined['value'], 2))->toBe('3.85');
});

it('marks a combined fraction incomplete when a part lacks its denominator', function () {
    $percentage = version('percentage', 'recompute', 'recompute');

    expect(Calculator::acrossUnits($percentage, [['numerator' => '3', 'denominator' => '4'], ['numerator' => '1', 'denominator' => null]]))
        ->toMatchArray(['value' => null, 'complete' => false]);
});

it('shows rates per their multiplier', function () {
    $rate = version('rate', 'recompute', 'recompute', 1000);

    expect(Calculator::valueOf($rate, ['numerator' => '12', 'denominator' => '4800']))->toBe('2.500000');
});

it('never combines what the indicator says not to, nor what its measure forbids', function () {
    expect(Calculator::acrossUnits(version('count', 'sum', 'none'), [['value' => '1']]))->toBeNull()
        // Scores are never added, whatever is stored.
        ->and(Calculator::acrossUnits(version('number', 'latest', 'sum'), [['value' => '1'], ['value' => '2']]))->toBeNull()
        // A percentage is never summed.
        ->and(Calculator::acrossUnits(version('percentage', 'recompute', 'sum'), [['numerator' => '1', 'denominator' => '2']]))->toBeNull();
});

it('takes the latest or the highest over time', function () {
    $cumulative = version('count', 'latest', 'sum');
    $score = version('number', 'max', 'none');

    expect(Calculator::overTime($cumulative, [['value' => '100'], ['value' => '180']]))->toMatchArray(['value' => '180'])
        ->and(Calculator::overTime($score, [['value' => '3.5'], ['value' => '4.25'], ['value' => '2']]))->toMatchArray(['value' => '4.25']);
});

it('assesses increase indicators against target and baseline', function () {
    expect(Performance::assess('increase', '95', '100'))->toMatchArray(['status' => Performance::ON_TRACK, 'achievement' => '95.000000'])
        ->and(Performance::assess('increase', '80', '100')['status'])->toBe(Performance::ATTENTION)
        ->and(Performance::assess('increase', '50', '100')['status'])->toBe(Performance::OFF_TRACK)
        ->and(Performance::assess('increase', '60', '100', baseline: '20')['progress'])->toBe('50.000000');
});

it('assesses decrease indicators by progress from the baseline', function () {
    // Baseline 40 % stunting, target 30 %, now 35 %: halfway.
    expect(Performance::assess('decrease', '35', '30', baseline: '40'))->toMatchArray(['status' => Performance::OFF_TRACK, 'achievement' => '50.000000'])
        ->and(Performance::assess('decrease', '28', '30')['status'])->toBe(Performance::ON_TRACK)
        ->and(Performance::assess('decrease', '40', '30')['achievement'])->toBe('75.000000');
});

it('assesses maintain indicators against a range', function () {
    expect(Performance::assess('maintain', '5', null, min: '4', max: '6')['status'])->toBe(Performance::ON_TRACK)
        ->and(Performance::assess('maintain', '7', null, min: '4', max: '6')['status'])->toBe(Performance::OFF_TRACK);
});

it('says when there is no target or no data rather than guessing', function () {
    expect(Performance::assess('increase', '10', null)['status'])->toBe(Performance::NO_TARGET)
        ->and(Performance::assess('increase', null, '10')['status'])->toBe(Performance::NO_DATA)
        ->and(Performance::assess('increase', '10', '0')['status'])->toBe(Performance::NO_TARGET);
});

it('uses the organization\'s thresholds', function () {
    expect(Performance::assess('increase', '85', '100', onTrack: 80, attention: 60)['status'])->toBe(Performance::ON_TRACK);
});
