<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The day's unread notifications in one organization, in one email.
 */
final class AlertDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $titles  the newest, at most App\Actions\Reminders::DIGEST_LINES
     */
    public function __construct(
        public readonly string $organization,
        public readonly int $total,
        public readonly array $titles,
        public readonly string $url,
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
        $mail = (new MailMessage)->subject("{$this->organization}: {$this->total} ".($this->total === 1 ? 'thing' : 'things').' waiting for you');
        foreach ($this->titles as $title) {
            $mail->line("- {$title}");
        }
        $more = $this->total - count($this->titles);
        if ($more > 0) {
            $mail->line("And {$more} more.");
        }

        return $mail->action('Open notifications', $this->url)
            ->line('You can turn these emails off under Your account.');
    }
}
