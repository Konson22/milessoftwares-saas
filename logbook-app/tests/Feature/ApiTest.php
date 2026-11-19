<?php

use App\Logbook\Models\Attachment;
use App\Logbook\Models\Entry;
use App\Logbook\Models\LogSheetVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
 * The phone app's API (ADR-0025 §8): the kit, entries written offline sent
 * in repeatable steps, corrections from a known revision, reviews online.
 */

function api(string $path = '', string $slug = 'acme'): string
{
    return test()->workspaceUrl($slug, 'api/v1/'.ltrim($path, '/'));
}

function phone(User $user, string $role, string $slug = 'acme'): void
{
    test()->actingAsAppMember($slug, rolePermissions($role), user: $user);
    Cache::flush();
}

/**
 * An entry as the phone sends it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function phoneEntry(string $sheetId, string $versionId, array $overrides = []): array
{
    return [
        'id' => (string) Str::uuid7(),
        'sheet_id' => $sheetId,
        'version_id' => $versionId,
        'occurred_at' => '2026-11-19T08:30:00+03:00',
        'values' => generatorValues(),
        'submit' => false,
        ...$overrides,
    ];
}

beforeEach(function () {
    Storage::fake('local');
    $this->team = team();
    as_($this->team['manager'], 'logbook_manager');
    $this->sheet = publishedSheet(logbook());
    $this->version = $this->sheet->published_version_id;
    phone($this->team['writer'], 'logbook_contributor');
});

it('gives the member who they are and their kit', function () {
    $this->getJson(api('me'))->assertOk()
        ->assertJsonPath('user.name', 'Writer')
        ->assertJsonPath('can.write', true)
        ->assertJsonPath('can.review', false);

    $kit = $this->getJson(api('kit'))->assertOk()->json();
    expect($kit['sheets'])->toHaveCount(1)
        ->and($kit['sheets'][0]['code'])->toBe('GEN')
        ->and($kit['sheets'][0]['version']['id'])->toBe($this->version)
        ->and($kit['limits']['fileMaxKb'])->toBe(10240)
        ->and(collect($kit['members'])->pluck('name'))->toContain('Writer', 'Reviewer');

    $this->getJson(api("sheet-versions/{$this->version}"))->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonCount(13, 'fields')
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('refuses calls without a valid token', function () {
    $this->withToken('not-a-token')->getJson(api('kit'))->assertUnauthorized();
});

it('takes an entry written offline in steps, each safe to repeat', function () {
    $payload = phoneEntry($this->sheet->id, $this->version);

    $first = $this->postJson(api('entries'), $payload)->assertCreated()->json();
    expect($first['status'])->toBe('draft')->and($first['key'])->toBe($payload['id'])->and($first['reference'])->toBeNull();
    // A lost reply: the same id answers the same entry.
    $this->postJson(api('entries'), $payload)->assertOk()->assertJsonPath('id', $first['id']);

    $photo = UploadedFile::fake()->image('gen.jpg', 40, 40);
    $file = $this->post(api("entries/{$first['id']}/attachments"), ['field' => 'photo', 'file' => $photo])->assertCreated()->json();
    // The same file again: the same attachment, no copy.
    $this->post(api("entries/{$first['id']}/attachments"), ['field' => 'photo', 'file' => $photo])->assertOk()->assertJsonPath('id', $file['id']);

    $submitted = $this->postJson(api("entries/{$first['id']}/submit"), ['revision' => 1])->assertOk()->json();
    expect($submitted['status'])->toBe('submitted')
        ->and($submitted['reference'])->toBe('GEN-'.now()->year.'-000001')
        ->and($submitted['files'])->toHaveCount(1);
    // Submitting again after a lost reply changes nothing.
    $this->postJson(api("entries/{$first['id']}/submit"), ['revision' => 1])->assertOk()->assertJsonPath('revision', 1);

    inTenant(function () {
        expect(Entry::query()->count())->toBe(1)
            ->and(Attachment::query()->count())->toBe(1);
    });
});

it('creates and submits at once, and checks values on the server', function () {
    $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version, ['submit' => true]))
        ->assertCreated()->assertJsonPath('status', 'submitted');

    $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version, ['submit' => true, 'values' => generatorValues(['hours' => '30'])]))
        ->assertUnprocessable()->assertJsonValidationErrors('values.hours');
});

it('accepts an entry written on a version since replaced, and refuses unknown or foreign versions', function () {
    $old = $this->version;
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("sheets/{$this->sheet->id}/publish"), ['fields' => [...generatorFields(), ['name' => 'site', 'label' => 'Site', 'type' => 'text', 'required' => true]]])
        ->assertSessionHasNoErrors();
    phone($this->team['writer'], 'logbook_contributor');

    $entry = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $old, ['submit' => true]))->assertCreated()->json();
    expect($entry['versionId'])->toBe($old);

    $this->postJson(api('entries'), phoneEntry($this->sheet->id, (string) Str::uuid7()))
        ->assertStatus(409)->assertJsonPath('code', 'version_unknown');

    as_($this->team['manager'], 'logbook_manager');
    $other = publishedSheet(logbook('Fleet'), 'VEH');
    phone($this->team['writer'], 'logbook_contributor');
    $this->postJson(api('entries'), phoneEntry($this->sheet->id, $other->published_version_id))
        ->assertUnprocessable()->assertJsonValidationErrors('version_id');
});

it('tells the phone when a sheet no longer takes its entries', function () {
    as_($this->team['manager'], 'logbook_manager');
    $this->post(p("sheets/{$this->sheet->id}/archive"))->assertSessionHasNoErrors();
    phone($this->team['writer'], 'logbook_contributor');

    $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version))
        ->assertStatus(409)->assertJsonPath('code', 'sheet_closed');
});

it('carries a return and its correction from the revision the phone saw', function () {
    $entry = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version, ['submit' => true]))->json();

    phone($this->team['reviewer'], 'logbook_reviewer');
    $queue = $this->getJson(api('reviews'))->assertOk()->json('entries');
    expect($queue)->toHaveCount(1)->and($queue[0]['revision'])->toBe(1);
    $this->getJson(api("entries/{$entry['id']}"))->assertOk()->assertJsonPath('canReview', true)->assertJsonCount(13, 'answers');
    $this->postJson(api("entries/{$entry['id']}/review"), ['decision' => 'returned', 'comment' => 'Hours look wrong', 'revision' => 1])
        ->assertOk()->assertJsonPath('status', 'returned');

    phone($this->team['writer'], 'logbook_contributor');
    $feed = $this->getJson(api('entries'))->assertOk()->json('entries');
    expect($feed[0]['status'])->toBe('returned')
        ->and($feed[0]['lastReview']['comment'])->toBe('Hours look wrong');

    $correction = ['base_revision' => 1, 'occurred_at' => '2026-11-19T08:30:00+03:00', 'values' => generatorValues(['hours' => '5'])];
    $this->putJson(api("entries/{$entry['id']}"), $correction)->assertOk()->assertJsonPath('values.hours', '5');
    $this->postJson(api("entries/{$entry['id']}/submit"), ['revision' => 2])->assertOk()->assertJsonPath('status', 'submitted');
    // The same correction resent after it went through: answered, not refused.
    $this->putJson(api("entries/{$entry['id']}"), $correction)->assertOk()->assertJsonPath('revision', 2);
    // A correction from an older revision is refused.
    $this->putJson(api("entries/{$entry['id']}"), [...$correction, 'base_revision' => 0])->assertStatus(409)->assertJsonPath('code', 'stale');
});

it('lists only changes since a moment', function () {
    $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version, ['submit' => true]))->assertCreated();
    $later = now()->addMinute()->toIso8601String();

    expect($this->getJson(api('entries'))->json('entries'))->toHaveCount(1)
        ->and($this->getJson(api('entries?since='.urlencode($later)))->json('entries'))->toHaveCount(0);
});

it('never lets anyone review their own entry or decide a stale revision', function () {
    as_($this->team['manager'], 'logbook_manager');
    $sheet = publishedSheet(logbook('Ops'), 'OPS');
    // An administrator both writes and reviews.
    phone($this->team['manager'], 'logbook_administrator');
    $own = $this->postJson(api('entries'), phoneEntry($sheet->id, $sheet->published_version_id, ['submit' => true]))->json();

    expect(collect($this->getJson(api('reviews'))->json('entries'))->pluck('id'))->not->toContain($own['id']);
    $this->postJson(api("entries/{$own['id']}/review"), ['decision' => 'reviewed', 'revision' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('decision');
    $this->postJson(api("entries/{$own['id']}/review"), ['decision' => 'reviewed', 'revision' => 2])
        ->assertStatus(409)->assertJsonPath('code', 'stale');
});

it('keeps one member out of another member\'s entries', function () {
    $entry = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version))->json();

    phone($this->team['other'], 'logbook_contributor');
    $this->getJson(api("entries/{$entry['id']}"))->assertNotFound();
    $this->putJson(api("entries/{$entry['id']}"), ['base_revision' => 0, 'occurred_at' => '2026-11-19T08:30:00Z', 'values' => []])->assertNotFound();
    $this->postJson(api("entries/{$entry['id']}/submit"), ['revision' => 1])->assertNotFound();
    expect($this->getJson(api('entries'))->json('entries'))->toBe([]);

    // Viewers and auditors do not write from the phone.
    phone($this->team['viewer'], 'logbook_viewer');
    $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version))->assertForbidden();
    $this->getJson(api('kit'))->assertOk()->assertJsonPath('sheets', []);
});

it('keeps workspaces apart', function () {
    $entry = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version, ['submit' => true]))->json();

    $stranger = actingAsRole('logbook_manager', slug: 'globex', name: 'Stranger');
    phone($stranger, 'logbook_manager', 'globex');
    $this->getJson(api("entries/{$entry['id']}", 'globex'))->assertNotFound();
    $this->getJson(api("sheet-versions/{$this->version}", 'globex'))->assertNotFound();
    expect($this->getJson(api('kit', 'globex'))->json('sheets'))->toBe([]);
});

it('discards a draft never submitted, and only that', function () {
    $draft = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version))->json();
    $this->deleteJson(api("entries/{$draft['id']}"))->assertNoContent();
    $this->deleteJson(api("entries/{$draft['id']}"))->assertNoContent();
    inTenant(fn () => expect(Entry::query()->count())->toBe(0));

    $sent = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version, ['submit' => true]))->json();
    $this->deleteJson(api("entries/{$sent['id']}"))->assertUnprocessable();
});

it('removes a file from a draft and serves files only to readers', function () {
    $draft = $this->postJson(api('entries'), phoneEntry($this->sheet->id, $this->version))->json();
    $file = $this->post(api("entries/{$draft['id']}/attachments"), ['field' => 'photo', 'file' => UploadedFile::fake()->image('a.jpg')])->json();

    $this->get(api("attachments/{$file['id']}"))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->post(api("entries/{$draft['id']}/attachments"), ['field' => 'notes', 'file' => UploadedFile::fake()->image('b.jpg')])
        ->assertUnprocessable();

    phone($this->team['other'], 'logbook_contributor');
    $this->get(api("attachments/{$file['id']}"))->assertNotFound();
    $this->deleteJson(api("attachments/{$file['id']}"))->assertNotFound();

    phone($this->team['writer'], 'logbook_contributor');
    $this->deleteJson(api("attachments/{$file['id']}"))->assertNoContent();
    inTenant(fn () => expect(Attachment::query()->count())->toBe(0)
        ->and(LogSheetVersion::query()->count())->toBe(1));
});
