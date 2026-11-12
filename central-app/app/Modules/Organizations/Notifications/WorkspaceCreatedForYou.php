<?php

namespace App\Modules\Organizations\Notifications;

use App\Support\Hosts\PlatformHosts;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * To the person a donor created a workspace for: the link that makes it
 * theirs (App\Modules\Organizations\Actions\SponsoredOrganizations::claim).
 */
class WorkspaceCreatedForYou extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $name,
        public readonly string $organizationName,
        public readonly string $sponsorName,
        public readonly string $creatorName,
        public readonly string $token,
        public readonly CarbonInterface $expiresAt,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * On the auth host: guests sign in or create their account first, then
     * come back to claim it.
     */
    public function claimUrl(): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), 'workspaces/claim/'.$this->token);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->sponsorName} set up a workspace for {$this->organizationName}")
            ->greeting("Hello {$this->name},")
            ->line("{$this->creatorName} of {$this->sponsorName} created a workspace for {$this->organizationName} on Tatook, so you can run the projects they fund and report to them.")
            ->line("Claim it to become its owner. It is yours: {$this->sponsorName} has no access to it, and sees only what you choose to share, grant by grant.")
            ->action('Claim the workspace', $this->claimUrl())
            ->line('The link can be used once and expires on '.$this->expiresAt->utc()->toFormattedDayDateString().'.')
            ->line('If you were not expecting it, you can ignore this email.');
    }
}
