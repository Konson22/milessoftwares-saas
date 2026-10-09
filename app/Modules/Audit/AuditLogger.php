<?php

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes hash-chained audit records. Each row's hash covers its own content
 * plus the previous row's hash, so editing or removing any row breaks the
 * chain from that point on (see verifyChain()).
 */
final class AuditLogger
{
    /**
     * Postgres advisory lock key serializing chain appends.
     */
    private const CHAIN_LOCK = 7_310_000_001;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ?Request $request = null,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $context = [],
        ?Authenticatable $actor = null,
        ?string $organizationId = null,
        string $actorType = 'user',
    ): AuditLog {
        $actor ??= $this->request?->user();

        $attributes = [
            'id' => (string) Str::uuid7(),
            // Second precision: Eloquent's date cast drops microseconds on save.
            'occurred_at' => Date::now()->utc()->format('Y-m-d\TH:i:sP'),
            'actor_type' => $actor === null ? 'system' : $actorType,
            'actor_id' => $actor?->getAuthIdentifier(),
            'organization_id' => $organizationId ?? $this->tenant->id(),
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject->getMorphClass(),
            'subject_id' => $subject?->getKey() === null ? null : (string) $subject->getKey(),
            'ip_address' => $this->request?->ip(),
            'user_agent' => $this->request?->userAgent(),
            'context' => $context === [] ? null : $context,
        ];

        return DB::transaction(function () use ($attributes): AuditLog {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [self::CHAIN_LOCK]);

            $prevHash = AuditLog::query()->orderByDesc('sequence')->value('hash');

            $log = new AuditLog;
            $log->forceFill([
                ...$attributes,
                'prev_hash' => $prevHash,
                'hash' => self::hash($attributes, $prevHash),
            ])->save();

            return $log;
        });
    }

    /**
     * Walk the chain and return the sequence number of the first broken row,
     * or null if the chain is intact.
     */
    public function verifyChain(): ?int
    {
        $expectedPrev = null;
        $broken = null;

        AuditLog::query()->orderBy('sequence')->chunk(500, function ($logs) use (&$expectedPrev, &$broken): bool {
            foreach ($logs as $log) {
                $attributes = [
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
                ];

                if ($log->prev_hash !== $expectedPrev || $log->hash !== self::hash($attributes, $log->prev_hash)) {
                    $broken = $log->sequence;

                    return false;
                }

                $expectedPrev = $log->hash;
            }

            return true;
        });

        return $broken;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function hash(array $attributes, ?string $prevHash): string
    {
        $context = $attributes['context'];
        if (is_array($context)) {
            $context = self::sortRecursive($context);
        }

        return hash('sha256', json_encode([
            $prevHash,
            $attributes['id'],
            $attributes['occurred_at'],
            $attributes['actor_type'],
            $attributes['actor_id'],
            $attributes['organization_id'],
            $attributes['action'],
            $attributes['subject_type'],
            $attributes['subject_id'],
            $attributes['ip_address'],
            $attributes['user_agent'],
            $context,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * JSONB does not preserve key order, so hash a canonical ordering.
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function sortRecursive(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortRecursive($item);
            }
        }

        return $value;
    }
}
