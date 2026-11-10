<?php

namespace App\Notifications;

use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A donor's invitation to set up, or connect, an organization on the portal.
 * The wording is the same whether the address has an account: the email tells
 * nobody who already uses the app.
 */
final class InvitedAsBeneficiary extends Notification
{
    public function __construct(
        public readonly string $donor,
        public readonly string $inviter,
        public readonly string $organization,
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
        return (new MailMessage)
            ->subject("{$this->donor} invited {$this->organization} to ".config('app.name'))
            ->line("{$this->inviter} of {$this->donor} invited you to run {$this->organization} on the portal.")
            ->line('You choose what each grant shares with them. Nothing is shared until you turn it on.')
            ->action('Open the invitation', $this->url)
            ->line('This link works once and expires on '.$this->expiresAt->toDayDateTimeString().' (UTC).')
            ->line('If you were not expecting this, you can ignore this email.');
    }
}
