<?php

namespace App\Modules\Marketing\Notifications;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a public form submission to support: who sent it, their subject
 * and message. Replying answers the visitor; the lead stays in the admin
 * area too.
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
        public readonly ?string $phone = null,
        public readonly ?string $subject = null,
        public readonly ?string $message = null,
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
            ->subject($this->subject ? "{$this->subject} · {$this->name}" : "New {$this->kind} from {$this->name}")
            ->replyTo($this->email, $this->name)
            ->line("{$this->name} ({$this->email})".($this->company ? " at {$this->company}" : '')." sent a {$this->kind}.");

        if ($this->phone) {
            $mail->line("Phone: {$this->phone}");
        }

        if ($this->subject) {
            $mail->line("Subject: {$this->subject}");
        }

        if ($this->message) {
            // One mail line per line of the message: a line joins its own breaks with spaces.
            $mail->line('Message:');

            foreach (preg_split('/\R/', trim($this->message)) ?: [] as $line) {
                if (trim($line) !== '') {
                    $mail->line($line);
                }
            }
        }

        return $mail
            ->line('Reply to this email to answer them.')
            ->action('Open leads', PlatformHosts::url(PlatformHosts::admin(), 'leads'));
    }
}
