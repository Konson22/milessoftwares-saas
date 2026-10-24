<?php

namespace App\Modules\Billing\Notifications;

use App\Modules\Billing\Support\TrialConfirmation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the owner when a free trial starts: a short email with the
 * trial confirmation (an invoice at zero, see TrialConfirmation) attached
 * as a PDF.
 */
class TrialStarted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $confirmation  TrialConfirmation::data()
     */
    public function __construct(public readonly array $confirmation) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->confirmation;

        return (new MailMessage)
            ->subject("Your {$data['application']} free trial: invoice {$data['number']}")
            ->markdown('mail.trial-started', $data)
            ->attachData(TrialConfirmation::pdf($data), "invoice-{$data['number']}.pdf", ['mime' => 'application/pdf']);
    }
}
