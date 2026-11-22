<?php

namespace App\Logbook;

use App\Jobs\RunInWorkspace;
use App\Logbook\Models\Notification;
use App\Logbook\Models\NotificationDelivery;
use App\Logbook\Models\NotificationSetting;
use App\Logbook\Models\ScheduleOccurrence;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\PlatformConfig;
use Miles\PlatformClient\Tenancy\CurrentTenant;

/**
 * The one notification engine (ADR-0026 §2.8), for tasks, entries,
 * schedules and exceptions.
 *
 * - A recipient is told only while they hold Logbook access and reach the
 *   record ($reaches gets them as a Member): access is read from Central
 *   when the notice is made, not when the event was planned.
 * - Nobody is told about what they did themselves.
 * - One notification per recipient and dedupe key: a repeated event or
 *   reminder adds nothing.
 * - Text is a reference and a title, never entry values.
 * - Every notice is also pushed to the person's phones, and important ones
 *   emailed (ADR-0027), unless the person turned that category off on that
 *   channel. Each channel is a delivery row (App\Logbook\Deliveries).
 */
final class Notifications
{
    /** Members looked at when a notice goes to everyone holding a permission. */
    public const MAX_BROADCAST = 200;

    /**
     * @param  array{category: string, event: string, title: string, body?: string|null, subject_type: string, subject_id: string, link: string, dedupe: string, email?: bool, channels?: list<string>}  $notice
     * @param  (Closure(Member): bool)|null  $reaches
     *
     * @throws PlatformUnavailable
     */
    public static function notify(string $userId, array $notice, ?Closure $reaches = null, ?string $actorId = null): ?Notification
    {
        if ($userId === $actorId) {
            return null;
        }
        $dedupe = mb_substr($notice['dedupe'], 0, 160);
        if (Notification::query()->where('user_id', $userId)->where('dedupe_key', $dedupe)->exists()) {
            return null;
        }
        $member = Member::of($userId);
        if ($member === null || ($reaches !== null && ! $reaches($member))) {
            return null;
        }

        try {
            // A savepoint: a duplicate raced in does not spoil the caller's transaction.
            $notification = DB::transaction(fn () => Notification::query()->create([
                'user_id' => $userId,
                'category' => $notice['category'],
                'event' => $notice['event'],
                'title' => mb_substr($notice['title'], 0, 160),
                'body' => isset($notice['body']) ? mb_substr((string) $notice['body'], 0, 300) : null,
                'subject_type' => $notice['subject_type'],
                'subject_id' => $notice['subject_id'],
                'link' => $notice['link'],
                'dedupe_key' => $dedupe,
                'created_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        // Push for every notice, email for the important ones; a record's own
        // reminder channels (tasks, schedules) narrow that. Personal choices
        // are applied by Deliveries::plan.
        $channels = $notice['channels'] ?? (($notice['email'] ?? false) ? ['push', 'email'] : ['push']);
        Deliveries::plan($notification, array_values(array_intersect($channels, NotificationDelivery::CHANNELS)));

        return $notification;
    }

    /**
     * Where the phone opens a notification: a screen and its ids, or null
     * (the notification list). The phone checks access again when it
     * opens the record, so a route never shows what the person cannot.
     *
     * @return array{screen: string, id?: string, sheet?: string, occurrence?: string}|null
     */
    public static function route(Notification $notification): ?array
    {
        return match ($notification->subject_type) {
            'task' => ['screen' => 'task', 'id' => $notification->subject_id],
            'entry' => $notification->event === 'entry.submitted'
                ? ['screen' => 'review', 'id' => $notification->subject_id]
                : ['screen' => 'entry', 'id' => $notification->subject_id],
            'occurrence' => self::occurrenceRoute($notification->subject_id),
            default => null,
        };
    }

    /** @return array{screen: string, sheet: string, occurrence: string}|null */
    private static function occurrenceRoute(string $occurrenceId): ?array
    {
        $sheet = ScheduleOccurrence::query()->whereKey($occurrenceId)->value('log_sheet_id');

        return $sheet !== null ? ['screen' => 'record', 'sheet' => (string) $sheet, 'occurrence' => $occurrenceId] : null;
    }

    /**
     * After the change commits, and on the queue: the Central calls that
     * check each recipient never hold the change's locks or slow the page.
     * Central being unreachable then drops this notice, logged; reminders
     * (which matter more) are retried by the dispatcher instead.
     *
     * @param  class-string  $class
     * @param  list<scalar|null>  $args
     */
    public static function later(string $class, string $method, array $args): void
    {
        $organizationId = app(CurrentTenant::class)->require()->id;
        DB::afterCommit(fn () => RunInWorkspace::dispatch($organizationId, $class, $method, $args));
    }

    /**
     * Notifies without failing the caller: an event notice that cannot be
     * checked against Central is logged and dropped.
     *
     * @param  array{category: string, event: string, title: string, body?: string|null, subject_type: string, subject_id: string, link: string, dedupe: string, email?: bool, channels?: list<string>}  $notice
     * @param  (Closure(Member): bool)|null  $reaches
     */
    public static function tell(string $userId, array $notice, ?Closure $reaches = null, ?string $actorId = null): void
    {
        try {
            self::notify($userId, $notice, $reaches, $actorId);
        } catch (PlatformUnavailable $e) {
            Log::warning('Logbook notice not sent: Central unavailable', ['event' => $notice['event'], 'message' => $e->getMessage()]);
        }
    }

    /**
     * Members (who have opened Logbook here) holding a permission, checked
     * by $reaches as well. For notices to "the reviewers" or "the managers".
     *
     * @param  (Closure(Member): bool)|null  $reaches
     * @return list<string> user ids
     */
    public static function holders(string $permission, ?Closure $reaches = null): array
    {
        $ids = [];
        $members = WorkspaceMember::query()->orderByDesc('last_seen_at')->limit(self::MAX_BROADCAST)->pluck('user_id');
        foreach ($members as $userId) {
            $member = Member::of((string) $userId);
            if ($member !== null && $member->can($permission) && ($reaches === null || $reaches($member))) {
                $ids[] = (string) $userId;
            }
        }

        return $ids;
    }

    public static function wantsEmail(string $userId, string $category): bool
    {
        return self::wants($userId, $category, 'email');
    }

    /** A person's choice for a category on a channel (email, push); missing: on. */
    public static function wants(string $userId, string $category, string $channel): bool
    {
        $setting = NotificationSetting::query()->where('user_id', $userId)->first();
        $choices = $channel === 'push' ? $setting?->push : $setting?->email;

        return (($choices ?? [])[$category] ?? true) === true;
    }

    /** Absolute address of a Logbook path in this workspace. */
    public static function url(string $link): string
    {
        $config = app(PlatformConfig::class);
        $scheme = parse_url($config->issuer(), PHP_URL_SCHEME) ?: 'https';
        $slug = app(CurrentTenant::class)->require()->slug;

        return $scheme.'://'.$slug.'.'.$config->rootDomain().'/'.trim($config->path(), '/').'/'.ltrim($link, '/');
    }
}
