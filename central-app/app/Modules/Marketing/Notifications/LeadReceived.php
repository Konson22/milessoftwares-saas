<?php

namespace App\Modules\Marketing\Notifications;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the sales team about a new lead. The message itself stays in the
 * admin area rather than in email, so mailboxes hold as little as possible.
 */
class LeadReceived extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly string $leadId,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $company,
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
            ->subject("New {$this->kind} from {$this->name}")
            ->line("{$this->name} ({$this->email})".($this->company ? " at {$this->company}" : '')." sent a {$this->kind}.")
            ->action('Open leads', PlatformHosts::url(PlatformHosts::admin(), 'leads'));
    }
}
