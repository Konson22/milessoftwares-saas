<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One thing someone needs to act on or know now: a request to approve, or a
 * decision. Queued, after the change commits, so a mail failure cannot undo
 * the change and a refused change sends nothing.
 */
final class AlertEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $organization,
        public readonly string $title,
        public readonly ?string $excerpt,
        public readonly string $url,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject("{$this->organization}: {$this->title}")->line($this->title);
        if ($this->excerpt !== null) {
            $mail->line($this->excerpt);
        }

        return $mail->action('Open', $this->url)
            ->line('You can turn these emails off under Your account.');
    }
}
