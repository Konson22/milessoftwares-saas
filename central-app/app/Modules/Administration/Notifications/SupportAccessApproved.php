<?php

namespace App\Modules\Administration\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an organization owner that Miles Global Softwares staff were given
 * temporary access to their organization's data.
 */
class SupportAccessApproved extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organizationName,
        public readonly string $reason,
        public readonly string $scope,
        public readonly CarbonInterface $expiresAt,
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
            ->subject("Support access to {$this->organizationName} was approved")
            ->line("A Miles Global Softwares support team member now has temporary access to {$this->organizationName}.")
            ->line('Reason: '.$this->reason)
            ->line('Access: '.($this->scope === 'read_only' ? 'view only' : 'view and change configuration'))
            ->line('Ends: '.$this->expiresAt->utc()->toDayDateTimeString().' UTC')
            ->line('Every action taken under this access is recorded in your audit log. Contact support if you did not expect this.');
    }
}
