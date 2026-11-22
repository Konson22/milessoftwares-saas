<?php

use App\Logbook\Route;

/*
 * Route summaries (ADR-0028 §4): distance from the points as observed,
 * inaccurate readings and implausible jumps left out, nothing counted
 * across a gap or a pause.
 */

/** A point $metres north of the start, $seconds after it. */
function northOf(int $seq, float $metres, int $seconds, ?float $accuracy = 10.0): array
{
    return ['seq' => $seq, 'lat' => 4.85 + $metres / 111_195, 'lng' => 31.58, 'accuracy_m' => $accuracy, 't' => 1_800_000_000 + $seconds];
}

it('measures distance along the recorded points', function () {
    $r = Route::summarize([northOf(1, 0, 0), northOf(2, 100, 10), northOf(3, 200, 20), northOf(4, 300, 30)], []);

    expect($r['distance_m'])->toBeBetween(299, 301)
        ->and($r['moving_seconds'])->toBe(30)
        ->and($r['used'])->toBe(4)
        ->and($r['segments'])->toHaveCount(1)
        ->and($r['gaps'])->toBe([]);
});

it('leaves out inaccurate readings and implausible jumps but keeps counting them', function () {
    $r = Route::summarize([
        northOf(1, 0, 0),
        northOf(2, 100, 10, accuracy: 400.0), // too inaccurate
        northOf(3, 5000, 20),                 // 5 km in 20 s: a bad fix
        northOf(4, 200, 30),
    ], []);

    expect($r['rejected'])->toBe(2)->and($r['distance_m'])->toBeBetween(199, 201);
});

it('does not count jitter while standing still', function () {
    $r = Route::summarize([northOf(1, 0, 0), northOf(2, 2, 30), northOf(3, 1, 60), northOf(4, 3, 90)], []);

    expect($r['distance_m'])->toBe(0)->and($r['moving_seconds'])->toBe(0);
});

it('counts nothing across a pause and does not call it a gap', function () {
    $r = Route::summarize([
        northOf(1, 0, 0), northOf(2, 100, 10),
        northOf(4, 5000, 1000), northOf(5, 5100, 1010),
    ], [
        ['seq' => 3, 'kind' => 'pause', 't' => 1_800_000_000 + 20],
        ['seq' => 3, 'kind' => 'resume', 't' => 1_800_000_000 + 990],
    ]);

    expect($r['distance_m'])->toBeBetween(199, 201)->and($r['gaps'])->toBe([])->and($r['segments'])->toHaveCount(2);
});

it('shows an interruption or a silence as a gap without distance', function () {
    $interrupted = Route::summarize([northOf(1, 0, 0), northOf(3, 3000, 900)], [['seq' => 2, 'kind' => 'interrupted', 't' => 1_800_000_000 + 100]]);
    $silent = Route::summarize([northOf(1, 0, 0), northOf(2, 3000, 900)], []);

    expect($interrupted['distance_m'])->toBe(0)
        ->and($interrupted['gaps'][0]['reason'])->toBe('interrupted')
        ->and($interrupted['gaps'][0]['seconds'])->toBe(900)
        ->and($silent['gaps'][0]['reason'])->toBe('no_signal')
        ->and($silent['gap_seconds'])->toBe(900)
        ->and($silent['segments'])->toHaveCount(2);
});

it('ignores repeated and out-of-order times', function () {
    $r = Route::summarize([northOf(1, 0, 0), northOf(2, 100, 10), northOf(3, 150, 10), northOf(4, 50, 5)], []);

    expect($r['rejected'])->toBe(2)->and($r['distance_m'])->toBeBetween(99, 101);
});
