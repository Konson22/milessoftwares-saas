<?php

namespace App\Modules\Administration\Notifications;

use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StaffInvitationSent extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The token is the only way to accept; it is never stored in plain text.
     */
    public function __construct(
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

    /** The admin host's setup page: password, then two-factor. */
    public function acceptUrl(): string
    {
        return PlatformHosts::url(PlatformHosts::admin(), 'invitation/'.$this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} invited you to the Miles Softwares admin")
            ->line("{$this->inviterName} invited you to join the Miles Softwares staff.")
            ->line('The link takes you through choosing a password and setting up two-factor authentication, which every administrator needs. Have an authenticator app ready on your phone.')
            ->action('Set up your admin access', $this->acceptUrl())
            ->line('The link can be used once and expires on '.$this->expiresAt->utc()->toFormattedDayDateString().'.')
            ->line('If you were not expecting it, you can ignore this email.');
    }
}
