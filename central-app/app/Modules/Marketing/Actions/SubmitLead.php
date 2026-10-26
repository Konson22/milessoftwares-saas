<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\ContactMessage;
use App\Modules\Marketing\Models\DemoRequest;
use App\Modules\Marketing\Notifications\LeadReceived;
use App\Modules\Marketing\Support\SpamGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Stores a public form submission and emails it to support. Submissions that
 * fail the spam checks are stored as `spam` (for tuning) and notify no one.
 */
final class SubmitLead
{
    public function __construct(private readonly SpamGuard $spam) {}

    /**
     * @param  array{name: string, email: string, company?: string|null, phone?: string|null, country_code?: string|null, subject?: string|null, message?: string|null}  $data
     */
    public function contact(Request $request, array $data): ContactMessage
    {
        $lead = new ContactMessage;

        return $this->store($request, $lead, $data, 'contact message');
    }

    /**
     * @template T of DemoRequest|ContactMessage
     *
     * @param  T  $lead
     * @param  array<string, mixed>  $data
     * @return T
     */
    private function store(Request $request, DemoRequest|ContactMessage $lead, array $data, string $kind): DemoRequest|ContactMessage
    {
        $spamReason = $this->spam->reason($request, isset($data['message']) ? (string) $data['message'] : null);

        $lead->forceFill([
            ...$data,
            'email' => mb_strtolower(trim((string) $data['email'])),
            'country_code' => isset($data['country_code']) ? strtoupper((string) $data['country_code']) : null,
            'status' => $spamReason === null ? 'new' : 'spam',
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 1000, ''),
        ])->save();

        if ($spamReason !== null) {
            Log::info('Public form submission flagged as spam', ['kind' => $kind, 'reason' => $spamReason, 'id' => $lead->id]);

            return $lead;
        }

        $recipient = config('platform.marketing.support_email') ?: config('mail.from.address');

        if (is_string($recipient) && $recipient !== '') {
            Notification::route('mail', $recipient)->notify(new LeadReceived(
                $kind,
                $lead->id,
                (string) $lead->name,
                (string) $lead->email,
                $lead->getAttribute('company'),
                $lead->getAttribute('phone'),
                $lead->getAttribute('subject'),
                $lead->getAttribute('message'),
            ));
        }

        return $lead;
    }
}
