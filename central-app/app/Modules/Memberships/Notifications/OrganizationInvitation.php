<?php

namespace App\Modules\Memberships\Notifications;

use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrganizationInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The token is the only way to accept; it is never stored in plain text.
     */
    public function __construct(
        public readonly string $organizationName,
        public readonly string $inviterName,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * The auth host's join page: new people choose a password there, people
     * with an account are sent on to sign in and accept.
     */
    public function acceptUrl(): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), 'join/'.$this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} invited you to {$this->organizationName}")
            ->line("{$this->inviterName} invited you to join {$this->organizationName} on Miles Softwares.")
            ->action('View invitation', $this->acceptUrl())
            ->line('The invitation can be used once and expires on '.$this->expiresAt->utc()->toFormattedDayDateString().'.')
            ->line('If you were not expecting it, you can ignore this email.');
    }
}
