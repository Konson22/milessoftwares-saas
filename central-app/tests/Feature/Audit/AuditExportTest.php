<?php

use App\Modules\Audit\AuditLogger;
use App\Modules\Audit\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('audit');
    $this->logger = app(AuditLogger::class);
});

/**
 * @return list<array<string, mixed>>
 */
function exportedRows(): array
{
    $files = collect(Storage::disk('audit')->allFiles())->sort()->values();

    return $files->flatMap(fn (string $path) => collect(explode("\n", trim((string) Storage::disk('audit')->get($path))))
        ->map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR)))
        ->all();
}

it('ships every audit record off-site in batches that keep the hash chain', function () {
    foreach (range(1, 5) as $i) {
        $this->logger->record('user.login', context: ['n' => $i]);
    }

    $this->artisan('platform:audit-export', ['--batch' => 2])
        ->expectsOutputToContain('3 file(s) exported')
        ->assertSuccessful();

    expect(Storage::disk('audit')->allFiles())->toHaveCount(3);

    $rows = exportedRows();
    $logs = AuditLog::query()->orderBy('sequence')->get();

    expect(array_column($rows, 'sequence'))->toBe($logs->pluck('sequence')->all())
        ->and($rows[0]['prev_hash'])->toBeNull();

    // The off-site copy verifies on its own: each row points at the one before.
    foreach (array_slice($rows, 1) as $i => $row) {
        expect($row['prev_hash'])->toBe($rows[$i]['hash']);
    }

    $last = DB::table('audit_exports')->orderByDesc('last_sequence')->first();
    expect($last->last_hash)->toBe($logs->last()->hash)
        ->and(DB::table('audit_exports')->sum('row_count'))->toBe(5);
});

it('continues after the last exported record and never ships one twice', function () {
    $this->logger->record('user.login');
    $this->artisan('platform:audit-export')->assertSuccessful();

    $this->artisan('platform:audit-export')
        ->expectsOutputToContain('0 file(s) exported')
        ->assertSuccessful();

    $this->logger->record('user.logout');
    $this->artisan('platform:audit-export')->assertSuccessful();

    $rows = exportedRows();
    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'action'))->toBe(['user.login', 'user.logout']);
});

it('does not record a batch whose upload failed, so the next run retries it', function () {
    $this->logger->record('user.login');

    Storage::shouldReceive('disk')->with('audit')->andThrow(new RuntimeException('bucket unreachable'));

    expect(fn () => $this->artisan('platform:audit-export')->run())->toThrow(RuntimeException::class);
    expect(DB::table('audit_exports')->count())->toBe(0);
});

it('verifies the chain on schedule and raises a critical log when it is broken', function () {
    $this->logger->record('user.login');
    $this->logger->record('user.logout');
    $this->logger->record('user.login');

    $this->artisan('platform:audit-verify')
        ->expectsOutputToContain('intact')
        ->assertSuccessful();

    // Remove a row from the middle, the way only a database superuser could.
    $middle = AuditLog::query()->orderBy('sequence')->skip(1)->value('sequence');
    DB::transaction(function () use ($middle) {
        DB::statement("SET LOCAL miles.audit_purge = 'on'");
        DB::table('audit_logs')->where('sequence', $middle)->delete();
    });

    Log::spy();

    $this->artisan('platform:audit-verify')
        ->expectsOutputToContain('broken')
        ->assertFailed();

    Log::shouldHaveReceived('critical')->withArgs(fn (string $message) => str_contains($message, 'Audit hash chain broken'))->once();
});
