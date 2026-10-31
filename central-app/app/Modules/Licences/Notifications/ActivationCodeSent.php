<?php

namespace App\Modules\Licences\Notifications;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * An activation code, emailed from the activation page to whoever holds the
 * licence key (resources/views/mail/activation-code.blade.php), with the code
 * attached as a file to carry to the PC on a USB stick.
 */
class ActivationCodeSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $device,
        public readonly ?string $pharmacyName,
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
        $support = (string) config('platform.marketing.support_email');

        return (new MailMessage)
            ->subject('Your Miles Pharmacy activation code')
            ->replyTo($support, 'Miles Global Softwares')
            ->markdown('mail.activation-code', [
                'code' => $this->code,
                'device' => $this->device,
                'pharmacyName' => $this->pharmacyName,
                'supportEmail' => $support,
                'website' => PlatformHosts::root(),
            ])
            ->attachData($this->code, 'miles-pharmacy-licence.lic', ['mime' => 'text/plain']);
    }
}
