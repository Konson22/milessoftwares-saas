<?php

namespace App\Logbook;

use App\Logbook\Models\Notification;
use App\Logbook\Models\NotificationDelivery;
use App\Logbook\Models\PushDevice;
use App\Logbook\Push\ExpoPush;
use App\Logbook\Push\PushRejected;
use App\Logbook\Push\PushUnavailable;
use App\Mail\LogbookNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Miles\PlatformClient\Api\PlatformUnavailable;
use Miles\PlatformClient\Tenancy\CurrentTenant;
use Throwable;

/**
 * Email and push for a notification (ADR-0027 §2.4).
 *
 * - The in-app notification is written first; each channel is a delivery
 *   row, unique per notification and channel, so a repeated event or a
 *   retried job never adds a second email or push.
 * - send() locks the row, re-checks that the recipient still holds Logbook
 *   access, and tries once. A transient failure schedules the next try
 *   (config logbook.delivery.backoff); after the last it is failed.
 * - Providers do not promise exactly-once: a crash after the provider took
 *   a message and before the row is marked sent can send it twice. Rows
 *   make that rare, not impossible.
 * - Push: one message per registered phone; a phone the provider reports
 *   unregistered is revoked, at once or when its receipt comes in.
 */
final class Deliveries
{
    /**
     * Creates the delivery rows a new notification needs and queues them.
     *
     * @param  list<string>  $channels  wanted channels (email, push), before personal choices
     */
    public static function plan(Notification $notification, array $channels): void
    {
        $wanted = [];
        if (in_array('email', $channels, true) && Notifications::wants($notification->user_id, $notification->category, 'email')) {
            $wanted[] = 'email';
        }
        if (in_array('push', $channels, true) && ExpoPush::enabled() && Notifications::wants($notification->user_id, $notification->category, 'push')
            && PushDevices::active($notification->user_id)->exists()) {
            $wanted[] = 'push';
        }
        foreach ($wanted as $channel) {
            try {
                $delivery = DB::transaction(fn () => NotificationDelivery::query()->create([
                    'notification_id' => $notification->id,
                    'channel' => $channel,
                    'status' => 'pending',
                ]));
            } catch (UniqueConstraintViolationException) {
                continue;
            }
            Notifications::later(self::class, 'send', [$delivery->id]);
        }
    }

    /** Tries one delivery, if it is still pending and its time has come. */
    public static function send(string $deliveryId, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();
        DB::transaction(function () use ($deliveryId, $now) {
            $delivery = NotificationDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();
            if ($delivery === null || $delivery->status !== 'pending' || ($delivery->next_attempt_at !== null && $delivery->next_attempt_at->gt($now))) {
                return;
            }
            $notification = Notification::query()->find($delivery->notification_id);
            if ($notification === null) {
                self::finish($delivery, 'skipped', 'notification gone');

                return;
            }

            try {
                if (Member::of($notification->user_id) === null) {
                    self::finish($delivery, 'skipped', 'no access');

                    return;
                }
                $delivery->attempts++;
                match ($delivery->channel) {
                    'email' => self::email($delivery, $notification, $now),
                    'push' => self::push($delivery, $notification, $now),
                    default => self::finish($delivery, 'skipped', 'unknown channel'),
                };
            } catch (PlatformUnavailable|PushUnavailable $e) {
                self::retry($delivery, $now, $e->getMessage());
            } catch (PushRejected $e) {
                Log::error('Logbook push refused by the provider', ['delivery' => $delivery->id, 'message' => $e->getMessage()]);
                self::finish($delivery, 'failed', $e->getMessage());
            } catch (Throwable $e) {
                report($e);
                self::retry($delivery, $now, $e->getMessage());
            }
        });
    }

    /**
     * Picks up deliveries the queue has not sent (worker down) and those
     * waiting for their retry time. Called every minute by the clock.
     *
     * @return int deliveries tried
     */
    public static function sweep(?CarbonImmutable $now = null, int $limit = 200): int
    {
        $now ??= CarbonImmutable::now();
        $stale = $now->subMinutes((int) config('logbook.delivery.sweep_after_minutes', 2));
        $ids = NotificationDelivery::query()->where('status', 'pending')
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereNull('next_attempt_at')->where('created_at', '<=', $stale))
                ->orWhere('next_attempt_at', '<=', $now))
            ->orderBy('created_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            self::send((string) $id, $now);
        }

        return $ids->count();
    }

    /**
     * Asks the push service what became of sent tickets, and revokes
     * phones it says are no longer registered.
     *
     * @return int deliveries checked
     */
    public static function checkReceipts(?CarbonImmutable $now = null, int $limit = 100): int
    {
        if (! ExpoPush::enabled()) {
            return 0;
        }
        $now ??= CarbonImmutable::now();
        $rows = NotificationDelivery::query()->where('channel', 'push')->where('status', 'sent')->whereNull('receipts_checked_at')
            ->where('sent_at', '<=', $now->subMinutes((int) config('logbook.delivery.receipts_after_minutes', 15)))
            ->where('sent_at', '>=', $now->subDay())
            ->orderBy('sent_at')->limit($limit)->get();
        if ($rows->isEmpty()) {
            return 0;
        }
        $byTicket = [];
        foreach ($rows as $row) {
            foreach ($row->tickets ?? [] as $ticket) {
                if (! empty($ticket['ticket'])) {
                    $byTicket[$ticket['ticket']] = $ticket['device'];
                }
            }
        }
        try {
            $receipts = $byTicket === [] ? [] : ExpoPush::receipts(array_keys($byTicket));
        } catch (PushUnavailable|PushRejected $e) {
            Log::warning('Logbook push receipts not fetched', ['message' => $e->getMessage()]);

            return 0;
        }
        foreach ($receipts as $ticket => $receipt) {
            if (($receipt['error'] ?? null) === 'DeviceNotRegistered' && isset($byTicket[$ticket])) {
                PushDevices::revoke($byTicket[$ticket], 'unregistered');
            }
        }
        NotificationDelivery::query()->whereKey($rows->pluck('id'))->update(['receipts_checked_at' => $now]);

        return $rows->count();
    }

    // ---- Channels ----

    private static function email(NotificationDelivery $delivery, Notification $notification, CarbonImmutable $now): void
    {
        $user = User::query()->find($notification->user_id);
        if ($user === null || filter_var($user->email, FILTER_VALIDATE_EMAIL) === false) {
            self::finish($delivery, 'skipped', 'no email address');

            return;
        }
        Mail::to($user->email, $user->name)->send(new LogbookNotice(
            $notification->title,
            $notification->body,
            Notifications::url($notification->link),
            app(CurrentTenant::class)->require()->displayName,
        ));
        $notification->forceFill(['emailed_at' => $now])->save();
        self::finish($delivery, 'sent', null, $now);
    }

    private static function push(NotificationDelivery $delivery, Notification $notification, CarbonImmutable $now): void
    {
        $devices = PushDevices::active($notification->user_id)->get();
        if ($devices->isEmpty()) {
            self::finish($delivery, 'skipped', 'no phone');

            return;
        }
        // A retry sends only to phones that did not take it the first time.
        $done = collect($delivery->tickets ?? [])->filter(fn ($t) => ! empty($t['ticket']))->pluck('device')->all();
        $devices = $devices->reject(fn (PushDevice $d) => in_array($d->id, $done, true))->values();
        if ($devices->isEmpty()) {
            self::finish($delivery, 'sent', null, $now);

            return;
        }

        $tenant = app(CurrentTenant::class)->require();
        $data = [
            'notificationId' => $notification->id,
            // The phone drops a push meant for someone no longer signed in on it (signed out offline).
            'userId' => $notification->user_id,
            'workspace' => $tenant->slug,
            'route' => Notifications::route($notification),
        ];
        $messages = array_values($devices->map(fn (PushDevice $d) => [
            'to' => $d->token,
            'title' => $notification->title,
            'body' => $notification->body ?? $tenant->displayName,
            'data' => $data,
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => (string) config('logbook.push.android_channel', 'work'),
        ])->all());

        $tickets = ExpoPush::send($messages);
        $kept = $delivery->tickets ?? [];
        $transient = false;
        foreach ($devices as $i => $device) {
            $ticket = $tickets[$i] ?? ['status' => 'error', 'error' => null];
            if ($ticket['status'] === 'ok') {
                $kept[] = ['device' => $device->id, 'ticket' => $ticket['id'] ?? null];
                $device->forceFill(['failures' => 0])->save();

                continue;
            }
            if (($ticket['error'] ?? null) === 'DeviceNotRegistered') {
                PushDevices::revoke($device->id, 'unregistered');

                continue;
            }
            $device->forceFill(['failures' => $device->failures + 1])->save();
            $transient = true;
        }
        $delivery->tickets = $kept;
        $anyTaken = collect($kept)->contains(fn ($t) => ! empty($t['ticket']));
        if ($transient) {
            self::retry($delivery, $now, 'Some phones did not take the message');
        } elseif ($anyTaken) {
            self::finish($delivery, 'sent', null, $now);
        } else {
            self::finish($delivery, 'skipped', 'no registered phone');
        }
    }

    // ---- State ----

    private static function retry(NotificationDelivery $delivery, CarbonImmutable $now, string $error): void
    {
        /** @var list<int> $backoff */
        $backoff = (array) config('logbook.delivery.backoff', [1, 5, 15, 60]);
        if ($delivery->attempts > count($backoff)) {
            self::finish($delivery, 'failed', $error);

            return;
        }
        $delivery->forceFill([
            'status' => 'pending',
            'next_attempt_at' => $now->addMinutes((int) ($backoff[max(0, $delivery->attempts - 1)] ?? end($backoff))),
            'last_error' => mb_substr($error, 0, 300),
        ])->save();
    }

    private static function finish(NotificationDelivery $delivery, string $status, ?string $error, ?CarbonImmutable $at = null): void
    {
        $delivery->forceFill([
            'status' => $status,
            'next_attempt_at' => null,
            'sent_at' => $status === 'sent' ? ($at ?? now()) : $delivery->sent_at,
            'last_error' => $error !== null ? mb_substr($error, 0, 300) : null,
        ])->save();
    }
}
