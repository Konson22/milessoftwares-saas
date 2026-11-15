<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An invitation to take part in a project, to someone whose organization is
 * not on Tatook yet (or not known to be). The same words whether the address
 * has an account: the email tells nobody who already uses the app.
 */
final class InvitedToProject extends Notification
{
    public function __construct(
        public readonly string $inviting,
        public readonly string $inviter,
        public readonly string $project,
        public readonly string $relationship,
        public readonly ?string $message,
        public readonly string $url,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("{$this->inviting} invites your organization to the project {$this->project}")
            ->line("{$this->inviter} of {$this->inviting} invites your organization to take part in the project {$this->project} as {$this->relationship}.");
        if ($this->message !== null && $this->message !== '') {
            $mail->line('Their message: '.$this->message);
        }

        return $mail
            ->line('Someone who manages your organization on '.config('app.name').' accepts it there. Nothing is shared before.')
            ->action('Open the invitation', $this->url)
            ->line('This link expires on '.$this->expiresAt->toDayDateTimeString().' (UTC).')
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
