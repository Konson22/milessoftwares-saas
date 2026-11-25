<?php

namespace App\Modules\Marketing\Notifications;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the visitor their message arrived (resources/views/mail/
 * lead-acknowledged.blade.php). Deliberately repeats nothing they typed
 * beyond their first name, so the form cannot be used to send someone
 * else's words to any address under our name.
 */
class LeadAcknowledged extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly ?string $supportEmail,
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
        $support = $this->supportEmail ?: (string) config('mail.from.address');

        return (new MailMessage)
            ->subject('We received your message · Miles Global Softwares')
            ->replyTo($support, 'Miles Global Softwares')
            ->markdown('mail.lead-acknowledged', [
                'greeting' => $this->greeting(),
                'supportEmail' => $support,
                'phone' => (string) config('platform.billing.phone'),
                'from' => 'The Miles Global Softwares team',
                'address' => (string) config('platform.billing.address'),
                'website' => PlatformHosts::root(),
                'productsUrl' => PlatformHosts::url(PlatformHosts::root(), 'products'),
            ]);
    }

    /** "Hello Amina," from the first word of the name, when it is a plain name. */
    public function greeting(): string
    {
        $first = strtok(trim($this->name), " \t") ?: '';

        return preg_match('/^\p{L}[\p{L}\'-]{0,29}$/u', $first) === 1 ? "Hello {$first}," : 'Hello,';
    }
}
