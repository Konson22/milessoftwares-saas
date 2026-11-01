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
 * attached as a file to carry to the PC on a USB stick (the restaurant's
 * phone pastes the code instead).
 */
class ActivationCodeSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $device,
        public readonly ?string $pharmacyName,
        public readonly string $product = 'pharmacy',
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
        $restaurant = $this->product === 'restaurant';
        $app = $restaurant ? 'Mataem Plus' : 'Miles Pharmacy';

        return (new MailMessage)
            ->subject("Your {$app} activation code")
            ->replyTo($support, 'Miles Global Softwares')
            ->markdown('mail.activation-code', [
                'code' => $this->code,
                'device' => $this->device,
                'pharmacyName' => $this->pharmacyName,
                'app' => $app,
                'restaurant' => $restaurant,
                'supportEmail' => $support,
                'website' => PlatformHosts::root(),
            ])
            ->attachData($this->code, $restaurant ? 'miles-restaurant-licence.txt' : 'miles-pharmacy-licence.lic', ['mime' => 'text/plain']);
    }
}
