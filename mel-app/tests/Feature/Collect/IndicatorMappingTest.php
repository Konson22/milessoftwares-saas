<?php

use App\Collect\Models\CalculationRow;
use App\Collect\Models\CalculationRun;
use App\Collect\Models\IndicatorMapping;
use App\Mel\Models\AuditEvent;
use App\Mel\Models\ReportingPeriod;
use App\Mel\Models\Submission as MelSubmission;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Collected data → indicators (ADR-0019 §26): mappings, calculation runs
 * sent to M&E as draft results, no double counting, and flags when a
 * counted submission changes.
 */

beforeEach(function () {
    $this->manager = actingAsRole('mel_manager', name: 'Mary Manager');
    ['programme' => $this->programme, 'units' => $this->units, 'output' => $this->output] = setUpProgramme();
    $this->form = publishForm($this->programme->id);
    $this->version = $this->form->currentVersion;
    // The quarter today falls in: phones send completed_at a few minutes ago.
    $this->period = inAcme(fn () => ReportingPeriod::query()
        ->where('programme_id', $this->programme->id)
        ->where('starts_on', '<=', now()->toDateString())->where('ends_on', '>=', now()->toDateString())->sole());

    $this->count = addIndicator($this->programme, $this->output, 'HH1', ['name' => 'Households without safe water']);
    $this->percent = addIndicator($this->programme, $this->output, 'HH2', ['name' => 'Households with safe water', 'measure' => 'percentage', 'unit_label' => 'households', 'time_aggregation' => 'recompute', 'unit_aggregation' => 'recompute']);
    $this->people = addIndicator($this->programme, $this->output, 'HH3', ['name' => 'People reached']);

    $this->enumerator = actingAsRole('mel_enumerator', name: 'Eli Enumerator');
    actingAsRole('mel_manager', $this->manager);
    $this->post(mel("collect/forms/{$this->form->id}/assignments"), ['user_ids' => [$this->enumerator->id]])->assertSessionHasNoErrors();

    $this->officer = actingAsRole('mel_officer', name: 'Otto Officer');

    // Sends a submission from the enumerator's phone, then reviews it as the officer.
    $this->collect = function (array $answers, string $decision = 'accept', ?string $unit = 'JUB'): string {
        $this->actingAsAppMember('acme', rolePermissions('mel_enumerator'), user: $this->enumerator);
        $payload = surveyPayload($this->version->id, ['answers' => $answers, 'programme_unit_id' => $unit === null ? null : $this->units[$unit]->id]);
        $this->postJson(collectApi('submissions'), $payload)->assertCreated();
        $this->flushHeaders();

        actingAsRole('mel_officer', $this->officer);
        if ($decision === 'accept') {
            $this->post(mel("collect/submissions/{$payload['id']}/accept"))->assertSessionHasNoErrors();
        } elseif ($decision === 'reject') {
            $this->post(mel("collect/submissions/{$payload['id']}/reject"), ['reason' => 'Wrong household'])->assertSessionHasNoErrors();
        }

        return $payload['id'];
    };

    $this->map = function ($indicator, array $data): IndicatorMapping {
        actingAsRole('mel_manager', $this->manager);
        $this->post(mel("collect/forms/{$this->form->id}/indicators"), ['indicator_id' => $indicator->id, ...$data])->assertSessionHasNoErrors();
        actingAsRole('mel_officer', $this->officer);

        return inAcme(fn () => IndicatorMapping::query()->where('indicator_id', $indicator->id)->where('status', 'active')->sole());
    };

    $this->send = fn (IndicatorMapping $mapping, string $unit = 'JUB') => $this->post(mel("collect/indicator-mappings/{$mapping->id}/send"), [
        'period_id' => $this->period->id, 'unit_id' => $this->units[$unit]->id,
    ]);
});

it('counts accepted submissions that meet the condition and sends the value to M&E as a draft', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'vendor', 'members' => 3]);
    ($this->collect)(['safe_water' => 'yes', 'members' => 4]);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 2], decision: 'reject');
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 2], decision: 'none');
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 6], unit: 'BOR');

    $mapping = ($this->map)($this->count, ['calculation' => 'count', 'condition' => ['q' => 'safe_water', 'op' => 'eq', 'value' => 'no']]);

    // The preview works it out without saving anything.
    $this->get(mel("collect/forms/{$this->form->id}/indicators?mapping={$mapping->id}&period={$this->period->id}&unit={$this->units['JUB']->id}"))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mel/collect/forms/indicators')
        ->where('preview.value', '2')
        ->where('preview.considered', 5)
        ->where('preview.accepted', 3)
        ->where('preview.used', 2)
        ->where('preview.excluded', [
            ['reason' => 'filtered_out', 'label' => 'Does not meet the mapping\'s condition', 'count' => 1],
            ['reason' => 'not_accepted', 'label' => 'Not accepted (yet)', 'count' => 2],
        ])
        ->where('can.send', true)
        ->where('can.manage', false));
    expect(inAcme(fn () => CalculationRun::query()->count()))->toBe(0);

    ($this->send)($mapping)->assertSessionHasNoErrors();

    inAcme(function () use ($mapping) {
        $run = CalculationRun::query()->sole();
        expect((float) $run->value)->toBe(2.0)
            ->and($run->used)->toBe(2)
            ->and($run->status)->toBe('sent')
            ->and($run->mapping_id)->toBe($mapping->id);

        $result = MelSubmission::query()->sole();
        expect($result->status)->toBe(MelSubmission::DRAFT)
            ->and($result->source)->toBe('collect')
            ->and($result->source_reference)->toBe($run->id)
            ->and((float) $result->value)->toBe(2.0)
            ->and($result->unit_id)->toBe($this->units['JUB']->id)
            ->and($result->narrative)->toContain('Calculated from 2 accepted submissions of "Household survey"');
        expect($run->mel_submission_id)->toBe($result->id);
        expect(CalculationRow::query()->where('standing', true)->count())->toBe(2);
        expect(AuditEvent::query()->where('action', 'calculation.sent')->exists())->toBeTrue();
    });
});

it('works out percentages and averages as numerator and denominator, and sums numbers', function () {
    ($this->collect)(['safe_water' => 'yes', 'members' => 4]);
    ($this->collect)(['safe_water' => 'yes', 'members' => 2]);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 6]);

    $percent = ($this->map)($this->percent, ['calculation' => 'percentage', 'numerator_condition' => ['q' => 'safe_water', 'op' => 'eq', 'value' => 'yes']]);
    $people = ($this->map)($this->people, ['calculation' => 'sum', 'question' => 'members']);

    ($this->send)($percent)->assertSessionHasNoErrors();
    ($this->send)($people)->assertSessionHasNoErrors();

    inAcme(function () {
        $percent = MelSubmission::query()->where('indicator_id', $this->percent->id)->sole();
        expect((float) $percent->numerator)->toBe(2.0)->and((float) $percent->denominator)->toBe(3.0)->and($percent->value)->toBeNull();

        $people = MelSubmission::query()->where('indicator_id', $this->people->id)->sole();
        expect((float) $people->value)->toBe(12.0);

        $rows = CalculationRow::query()->whereHas('run', fn ($query) => $query->where('indicator_id', $this->percent->id))->get();
        expect($rows->where('in_numerator', true))->toHaveCount(2)->and($rows->where('in_numerator', false))->toHaveCount(1);
    });
});

it('counts a household once when it was visited again, by its latest visit', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5, 'respondent' => 'Achol Deng']);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 7, 'respondent' => ' achol deng ']);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'vendor', 'members' => 3, 'respondent' => 'Deng Garang']);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'vendor', 'members' => 3]);

    $mapping = ($this->map)($this->people, ['calculation' => 'sum', 'question' => 'members', 'distinct_by' => 'respondent']);
    ($this->send)($mapping)->assertSessionHasNoErrors();

    inAcme(function () {
        $run = CalculationRun::query()->sole();
        // 7 (the later visit to the same household) + 3; the one without a name is left out.
        expect((float) $run->value)->toBe(10.0)
            ->and($run->used)->toBe(2)
            ->and($run->excluded)->toBe(['no_identifier' => 1, 'repeat_visit' => 1]);
    });
});

it('replaces its own earlier draft on a new run, and counts each submission once', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    $mapping = ($this->map)($this->count, ['calculation' => 'count']);
    ($this->send)($mapping)->assertSessionHasNoErrors();

    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 2]);
    ($this->send)($mapping)->assertSessionHasNoErrors();

    inAcme(function () {
        [$first, $second] = CalculationRun::query()->orderBy('created_at')->orderBy('id')->get()->all();
        expect($first->status)->toBe('superseded')->and($second->status)->toBe('sent')->and((float) $second->value)->toBe(2.0);
        expect(CalculationRow::query()->where('run_id', $first->id)->where('standing', true)->count())->toBe(0);
        expect(CalculationRow::query()->where('standing', true)->count())->toBe(2);

        // Still one M&E result, now pointing to the second run.
        $result = MelSubmission::query()->sole();
        expect((float) $result->value)->toBe(2.0)->and($result->source_reference)->toBe($second->id);
    });
});

it('never overwrites a result entered by hand or one waiting for verification', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    $mapping = ($this->map)($this->count, ['calculation' => 'count']);

    enterResult($this->programme, $this->period, $this->count, $this->units['JUB'], ['value' => 9], submit: false);
    ($this->send)($mapping)->assertSessionHasErrors(['run' => 'A result for this period and unit is already being entered by hand. Finish or discard it in Data entry first.']);

    // Sent from collected data, then submitted for verification: not touched by a new run.
    ($this->send)($mapping, 'BOR')->assertSessionHasErrors('run');
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5], unit: 'BOR');
    ($this->send)($mapping, 'BOR')->assertSessionHasNoErrors();
    $result = inAcme(fn () => MelSubmission::query()->where('unit_id', $this->units['BOR']->id)->sole());
    $this->post(mel("submissions/{$result->id}/submit"))->assertSessionHasNoErrors();
    ($this->send)($mapping, 'BOR')->assertSessionHasErrors(['run' => 'The result for this period and unit is waiting for verification. Wait for the decision, or ask the verifier to send it back.']);

    expect(inAcme(fn () => (float) MelSubmission::query()->where('unit_id', $this->units['JUB']->id)->sole()->value))->toBe(9.0);
});

it('flags a sent run when a submission it counted is reopened', function () {
    $id = ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    $mapping = ($this->map)($this->count, ['calculation' => 'count']);
    ($this->send)($mapping)->assertSessionHasNoErrors();

    $this->post(mel("collect/submissions/{$id}/reopen"), ['reason' => 'Household visited twice'])->assertSessionHasNoErrors();

    $run = inAcme(fn () => CalculationRun::query()->sole());
    expect($run->flagged_at)->not->toBeNull()->and($run->flag_reason)->toContain('reopened');

    $this->get(mel("collect/forms/{$this->form->id}/indicators"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('mappings.0.runs.0.flag', $run->flag_reason));

    // Recalculating leaves the reopened submission out until it is accepted again.
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 3]);
    ($this->send)($mapping)->assertSessionHasNoErrors();
    expect(inAcme(fn () => (float) CalculationRun::query()->where('status', 'sent')->sole()->value))->toBe(1.0);
});

it('refuses mappings that do not fit the indicator or the form', function () {
    actingAsRole('mel_manager', $this->manager);
    $url = mel("collect/forms/{$this->form->id}/indicators");

    $this->post($url, ['indicator_id' => $this->percent->id, 'calculation' => 'count'])
        ->assertSessionHasErrors(['calculation' => 'A percentage indicator cannot be calculated as a count of submissions.']);
    $this->post($url, ['indicator_id' => $this->people->id, 'calculation' => 'sum', 'question' => 'respondent'])
        ->assertSessionHasErrors(['question' => 'Choose a number question of the form.']);
    $this->post($url, ['indicator_id' => $this->count->id, 'calculation' => 'count', 'condition' => ['q' => 'nothing', 'op' => 'eq', 'value' => 'x']])
        ->assertSessionHasErrors(['condition' => 'Choose a question of the form in each condition.']);
    $this->post($url, ['indicator_id' => $this->count->id, 'calculation' => 'count', 'condition' => ['q' => 'safe_water', 'op' => 'gt', 'value' => 'no']])
        ->assertSessionHasErrors('condition');
    $this->post($url, ['indicator_id' => $this->percent->id, 'calculation' => 'percentage'])
        ->assertSessionHasErrors(['numerator_condition' => 'Say which submissions count in the percentage.']);

    $this->post($url, ['indicator_id' => $this->count->id, 'calculation' => 'count'])->assertSessionHasNoErrors();
    $this->post($url, ['indicator_id' => $this->count->id, 'calculation' => 'count'])
        ->assertSessionHasErrors(['indicator_id' => 'This form already feeds HH1. Change that mapping instead.']);

    // A form outside the indicator's programme.
    $other = publishForm(null, name: 'Standalone survey');
    $this->post(mel("collect/forms/{$other->id}/indicators"), ['indicator_id' => $this->count->id, 'calculation' => 'count'])->assertSessionHasErrors('indicator_id');
});

it('keeps a used mapping as it was and makes a new one when it changes', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    $mapping = ($this->map)($this->count, ['calculation' => 'count']);
    ($this->send)($mapping)->assertSessionHasNoErrors();

    actingAsRole('mel_manager', $this->manager);
    $this->put(mel("collect/indicator-mappings/{$mapping->id}"), ['calculation' => 'count', 'condition' => ['q' => 'safe_water', 'op' => 'eq', 'value' => 'no']])
        ->assertSessionHasNoErrors();

    inAcme(function () use ($mapping) {
        expect($mapping->fresh()->status)->toBe('retired')->and($mapping->fresh()->condition)->toBeNull();
        $new = IndicatorMapping::query()->where('status', 'active')->sole();
        expect($new->supersedes_id)->toBe($mapping->id)->and($new->condition)->toBe(['q' => 'safe_water', 'op' => 'eq', 'value' => 'no']);
        // The sent run still names the mapping it was worked out with.
        expect(CalculationRun::query()->sole()->mapping_id)->toBe($mapping->id);
    });
});

it('lets only the right people map, send and see', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    $mapping = ($this->map)($this->count, ['calculation' => 'count']);

    // An officer reviews and sends, but does not define calculations.
    $this->post(mel("collect/forms/{$this->form->id}/indicators"), ['indicator_id' => $this->people->id, 'calculation' => 'count'])->assertForbidden();
    // A manager defines them, but does not enter results.
    actingAsRole('mel_manager', $this->manager);
    ($this->send)($mapping)->assertForbidden();
    // Enumerators see none of it.
    actingAsRole('mel_enumerator', $this->enumerator);
    $this->get(mel("collect/forms/{$this->form->id}/indicators"))->assertForbidden();
    // Another organization does not find it.
    actingAsRole('mel_administrator', slug: 'globex');
    $this->get(mel("collect/forms/{$this->form->id}/indicators", 'globex'))->assertNotFound();
    $this->post(mel("collect/indicator-mappings/{$mapping->id}/send", 'globex'), ['period_id' => $this->period->id, 'unit_id' => $this->units['JUB']->id])->assertNotFound();
});

it('traces a sent result back to each submission, hiding personal amounts', function () {
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 5]);
    ($this->collect)(['safe_water' => 'no', 'main_source' => 'river', 'members' => 2]);
    $mapping = ($this->map)($this->people, ['calculation' => 'sum', 'question' => 'members']);
    ($this->send)($mapping)->assertSessionHasNoErrors();
    $run = inAcme(fn () => CalculationRun::query()->sole());

    $this->get(mel("collect/calculations/{$run->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('mel/collect/calculations/show')
        ->where('run.value', '7')
        ->where('run.result.status', 'draft')
        ->where('run.result.fromThisRun', true)
        ->has('rows', 2)
        ->where('rows.0.amount', '5')
        ->where('rows.0.author', 'Eli Enumerator')
        ->where('amountsHidden', false));

    // The M&E result says where it came from.
    $result = inAcme(fn () => MelSubmission::query()->sole());
    $this->get(mel("submissions/{$result->id}"))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('calculation.id', $run->id)
        ->where('calculation.used', 2));
});
