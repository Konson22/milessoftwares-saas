<?php

namespace App\Logbook;

use App\Logbook\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Miles\PlatformClient\Api\PlatformUnavailable;

/**
 * Reminders for tasks, schedule occurrences and exceptions (ADR-0026 §2.8).
 *
 * A record's reminders are tokens, chosen per record:
 *   start        when the task starts
 *   before:N     N minutes before the due time
 *   due          at the due time
 *   after:N      N minutes after it (after:0 is "now overdue")
 *   at:ISO-8601  a chosen moment
 *
 * plan() keeps the record's reminder rows in step with its tokens and
 * dates; sendDue() (logbook:operations, every minute) sends what is due:
 * each row locked, the record and the recipient checked again as they are
 * now, sent once, its outcome kept. Central unreachable: the row stays
 * unsent and is tried again next minute.
 */
final class Reminders
{
    public const PRESETS = ['start', 'before:1440', 'before:120', 'before:30', 'due', 'after:0', 'after:60'];

    /** Channels beside in-app that a record's reminders can use. */
    public const CHANNELS = ['push', 'email'];

    private const TOKEN = '/^(start|due|before:\d{1,5}|after:\d{1,5}|at:\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?([+-]\d{2}:\d{2}|Z)?)$/';

    /** A reminder older than this when the dispatcher gets to it is not sent (after an outage). */
    public const STALE_HOURS = 12;

    /**
     * Checks tokens against the record's dates: deadline reminders need a
     * due time, "start" a start time.
     *
     * @param  list<string>  $tokens
     * @return array<string, string> errors keyed "reminders"
     */
    public static function errors(array $tokens, ?CarbonImmutable $startsAt, ?CarbonImmutable $dueAt): array
    {
        foreach ($tokens as $token) {
            if (preg_match(self::TOKEN, $token) !== 1) {
                return ['reminders' => 'Choose reminders from the list, or a date and time.'];
            }
            if ($token === 'start' && $startsAt === null) {
                return ['reminders' => 'A reminder at the start needs a start time.'];
            }
            if ((in_array($token, ['due'], true) || str_starts_with($token, 'before:') || str_starts_with($token, 'after:')) && $dueAt === null) {
                return ['reminders' => 'Reminders around the deadline need a due time.'];
            }
        }
        if (count($tokens) > 6) {
            return ['reminders' => 'At most six reminders.'];
        }

        return [];
    }

    /**
     * Keeps the record's unsent reminder rows in step with its tokens and
     * dates. Rows already sent stay as history. A reminder whose moment is
     * already past is not planned, except "after" ones (the record is late
     * now: say so once).
     *
     * @param  list<string>  $tokens
     */
    public static function plan(string $type, string $id, array $tokens, ?CarbonImmutable $startsAt, ?CarbonImmutable $dueAt, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        $wanted = [];
        foreach (array_unique($tokens) as $token) {
            $slot = self::slot($token, $startsAt, $dueAt);
            if ($slot === null || ($slot['kind'] !== 'after' && $slot['at']->lt($now->subMinutes(5)))) {
                continue;
            }
            $wanted[$slot['kind'].'|'.$slot['offset'].'|'.$slot['at']->utc()->format('Y-m-d H:i:s')] = $slot;
        }

        $pending = Reminder::query()->where('subject_type', $type)->where('subject_id', $id)
            ->whereNull('sent_at')->whereNull('cancelled_at')->get();
        foreach ($pending as $row) {
            $key = $row->kind.'|'.$row->offset_minutes.'|'.$row->remind_at->utc()->format('Y-m-d H:i:s');
            if (isset($wanted[$key])) {
                unset($wanted[$key]);
            } else {
                $row->forceFill(['cancelled_at' => $now])->save();
            }
        }

        foreach ($wanted as $slot) {
            $done = Reminder::query()->where('subject_type', $type)->where('subject_id', $id)
                ->where('kind', $slot['kind'])->where('offset_minutes', $slot['offset'])->where('remind_at', $slot['at']->utc())->first();
            if ($done !== null) {
                // Sent before, or cancelled and wanted again.
                if ($done->sent_at === null && $done->cancelled_at !== null) {
                    $done->forceFill(['cancelled_at' => null])->save();
                }

                continue;
            }
            Reminder::query()->create([
                'subject_type' => $type,
                'subject_id' => $id,
                'kind' => $slot['kind'],
                'offset_minutes' => $slot['offset'],
                'remind_at' => $slot['at']->utc(),
            ]);
        }
    }

    public static function cancel(string $type, string $id): void
    {
        Reminder::query()->where('subject_type', $type)->where('subject_id', $id)
            ->whereNull('sent_at')->whereNull('cancelled_at')->update(['cancelled_at' => now()]);
    }

    /**
     * Sends the reminders due in this organization.
     *
     * @return array{sent: int, skipped: int, retry: int}
     */
    public static function sendDue(?CarbonImmutable $now = null, int $limit = 200): array
    {
        $now ??= CarbonImmutable::now();
        $counts = ['sent' => 0, 'skipped' => 0, 'retry' => 0];
        $due = Reminder::query()->whereNull('sent_at')->whereNull('cancelled_at')
            ->where('remind_at', '<=', $now)->orderBy('remind_at')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            try {
                $outcome = DB::transaction(function () use ($id, $now) {
                    $reminder = Reminder::query()->whereKey($id)->lockForUpdate()->first();
                    if ($reminder === null || $reminder->sent_at !== null || $reminder->cancelled_at !== null) {
                        return null;
                    }
                    $outcome = $reminder->remind_at->lt($now->subHours(self::STALE_HOURS))
                        ? 'skipped: stale'
                        : self::deliver($reminder);
                    $reminder->forceFill(['sent_at' => $now, 'outcome' => $outcome])->save();

                    return $outcome;
                });
            } catch (PlatformUnavailable $e) {
                Log::warning('Logbook reminder deferred: Central unavailable', ['reminder' => $id, 'message' => $e->getMessage()]);
                $counts['retry']++;

                continue;
            } catch (UniqueConstraintViolationException) {
                $outcome = 'skipped: duplicate';
            }
            if ($outcome !== null) {
                $counts[$outcome === 'sent' ? 'sent' : 'skipped']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{kind: string, offset: int, at: CarbonImmutable}|null
     */
    private static function slot(string $token, ?CarbonImmutable $startsAt, ?CarbonImmutable $dueAt): ?array
    {
        return match (true) {
            $token === 'start' => $startsAt !== null ? ['kind' => 'start', 'offset' => 0, 'at' => $startsAt] : null,
            $token === 'due' => $dueAt !== null ? ['kind' => 'due', 'offset' => 0, 'at' => $dueAt] : null,
            str_starts_with($token, 'before:') => $dueAt !== null ? ['kind' => 'before', 'offset' => -(int) substr($token, 7), 'at' => $dueAt->subMinutes((int) substr($token, 7))] : null,
            str_starts_with($token, 'after:') => $dueAt !== null ? ['kind' => 'after', 'offset' => (int) substr($token, 6), 'at' => $dueAt->addMinutes((int) substr($token, 6))] : null,
            str_starts_with($token, 'at:') => ['kind' => 'at', 'offset' => 0, 'at' => CarbonImmutable::parse(substr($token, 3))],
            default => null,
        };
    }

    /** 'sent', or 'skipped: why'. */
    private static function deliver(Reminder $reminder): string
    {
        return match ($reminder->subject_type) {
            'task' => Tasks::remind($reminder),
            'occurrence' => Schedules::remind($reminder),
            'exception' => Exceptions::remind($reminder),
            default => 'skipped: unknown',
        };
    }

    /**
     * The channels a reminder goes out on (in-app always). A record's own
     * choice wins; without one, push for every reminder and email only
     * once the work is overdue.
     *
     * @param  list<string>|null  $configured
     * @return list<string>
     */
    public static function channels(?array $configured, string $kind): array
    {
        if ($configured === null) {
            return $kind === 'after' ? ['push', 'email'] : ['push'];
        }

        return array_values(array_intersect(self::CHANNELS, $configured));
    }

    /**
     * Normalizes reminder channels from a form or the API: null (the
     * default) or the chosen subset of push and email.
     *
     * @return list<string>|null
     */
    public static function channelsInput(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_array($value) || array_diff($value, self::CHANNELS) !== []) {
            throw ValidationException::withMessages(['reminder_channels' => 'Choose push, email, or both.']);
        }

        return array_values(array_intersect(self::CHANNELS, $value));
    }

    /** "in 2 hours", "30 minutes ago": for reminder texts. */
    public static function relative(int $minutes): string
    {
        $abs = abs($minutes);
        $text = match (true) {
            $abs >= 1440 && $abs % 1440 === 0 => ($abs / 1440).' '.($abs === 1440 ? 'day' : 'days'),
            $abs >= 60 && $abs % 60 === 0 => ($abs / 60).' '.($abs === 60 ? 'hour' : 'hours'),
            default => $abs.' '.($abs === 1 ? 'minute' : 'minutes'),
        };

        return $text;
    }
}
