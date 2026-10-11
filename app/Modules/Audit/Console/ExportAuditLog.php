<?php

namespace App\Modules\Audit\Console;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ships new audit records to the `audit` disk as NDJSON, one file per batch,
 * continuing after the last exported sequence. Rows keep prev_hash and hash,
 * so the off-site copy can be verified without the database.
 */
class ExportAuditLog extends Command
{
    protected $signature = 'platform:audit-export {--batch=10000 : Rows per file}';

    protected $description = 'Copy new audit records to off-site storage';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $files = 0;

        while (true) {
            $after = (int) DB::table('audit_exports')->max('last_sequence');

            $rows = AuditLog::query()
                ->where('sequence', '>', $after)
                ->orderBy('sequence')
                ->limit($batch)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            /** @var AuditLog $first */
            $first = $rows->first();
            /** @var AuditLog $last */
            $last = $rows->last();

            $lines = $rows->map(fn (AuditLog $log) => json_encode([
                'sequence' => $log->sequence,
                'id' => $log->id,
                'occurred_at' => $log->occurred_at->utc()->format('Y-m-d\TH:i:sP'),
                'actor_type' => $log->actor_type,
                'actor_id' => $log->actor_id,
                'organization_id' => $log->organization_id,
                'action' => $log->action,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'context' => $log->context,
                'prev_hash' => $log->prev_hash,
                'hash' => $log->hash,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            $path = sprintf('%s/audit-%012d-%012d.ndjson', $last->occurred_at->utc()->format('Y/m'), $first->sequence, $last->sequence);

            // Write first: a crash before the insert re-exports the same range
            // to the same path next time instead of skipping it.
            Storage::disk('audit')->put($path, $lines->implode("\n")."\n");

            DB::table('audit_exports')->insert([
                'id' => (string) Str::uuid7(),
                'first_sequence' => $first->sequence,
                'last_sequence' => $last->sequence,
                'last_hash' => $last->hash,
                'row_count' => $rows->count(),
                'path' => $path,
                'created_at' => now(),
            ]);

            $files++;
        }

        $this->components->info("{$files} file(s) exported.");

        return self::SUCCESS;
    }
}
