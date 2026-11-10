<?php

namespace App\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the audit log: Audit::record('member.invited', $invitation, ['email' => ...]).
 * The organization defaults to the current one, the actor to the signed-in
 * user; the request's address and browser are kept.
 *
 * A change has one format, 'before' and 'after' holding what changed
 * (Audit::change). Secrets never reach the log: their values are masked by key.
 */
final class Audit
{
    private const SECRET = '/password|secret|token|recovery|api_?key|credential/i';

    /**
     * Records what changed between two sets of values, or nothing if nothing did.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $context  anything else worth keeping
     */
    public static function change(
        string $action,
        ?Model $subject,
        array $before,
        array $after,
        array $context = [],
        ?string $organizationId = null,
        ?User $actor = null,
    ): ?AuditEvent {
        $changed = array_filter(array_keys($after), fn (string $key) => ($before[$key] ?? null) !== $after[$key]);
        if ($changed === []) {
            return null;
        }
        $keep = array_flip($changed);

        return self::record($action, $subject, [
            ...$context,
            'before' => array_intersect_key($before, $keep),
            'after' => array_intersect_key($after, $keep),
        ], $organizationId, $actor);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function record(
        string $action,
        ?Model $subject = null,
        array $properties = [],
        ?string $organizationId = null,
        ?User $actor = null,
    ): AuditEvent {
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
        $actor ??= Auth::user() instanceof User ? Auth::user() : null;

        return AuditEvent::query()->create([
            'organization_id' => $organizationId ?? app(CurrentOrganization::class)->id(),
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : class_basename($subject),
            'subject_id' => $subject === null ? null : (string) $subject->getKey(),
            'properties' => $properties === [] ? null : self::masked($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 512),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function masked(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET, $key) === 1) {
                $values[$key] = '[hidden]';
            } elseif (is_array($value)) {
                $values[$key] = self::masked($value);
            }
        }

        return $values;
    }
}
