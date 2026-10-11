<?php

namespace App\Modules\Identity\Notifications;

use App\Modules\Identity\Enums\SecurityAlertType;
use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SecurityAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly SecurityAlertType $type,
        public readonly CarbonInterface $occurredAt,
        public readonly ?string $ipAddress = null,
        public readonly ?string $device = null,
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
        $message = (new MailMessage)
            ->subject($this->type->subject())
            ->line($this->type->summary())
            ->line('When: '.$this->occurredAt->utc()->toDayDateTimeString().' UTC');

        if ($this->device !== null) {
            $message->line('Device: '.$this->device);
        }

        if ($this->ipAddress !== null) {
            $message->line('IP address: '.$this->ipAddress);
        }

        return $message
            ->line('If this was not you, change your password and review your signed-in sessions now.')
            ->action('Review account security', PlatformHosts::url(PlatformHosts::auth(), 'settings/sessions'));
    }
}
