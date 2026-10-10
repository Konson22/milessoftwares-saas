<?php

namespace App\Modules\Marketing\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Low-friction bot checks for public forms, invisible to people:
 *
 *  - a honeypot field that humans never see or fill;
 *  - an encrypted "form issued at" token: submissions faster than a person
 *    can type, or older than a day, are treated as automated;
 *  - more links than a genuine enquiry would contain.
 *
 * Failing a check never shows an error. The submission is stored as spam
 * and nobody is notified, so bots learn nothing. Rate limits sit in front.
 */
final class SpamGuard
{
    public const HONEYPOT_FIELD = 'website';

    public const TOKEN_FIELD = 'form_token';

    private const MIN_SECONDS = 3;

    private const MAX_SECONDS = 86_400;

    private const MAX_LINKS = 3;

    public static function issueToken(): string
    {
        return Crypt::encryptString((string) now()->getTimestamp());
    }

    /**
     * Why the submission looks automated, or null when it looks human.
     */
    public function reason(Request $request, ?string $text = null): ?string
    {
        if (filled($request->input(self::HONEYPOT_FIELD))) {
            return 'honeypot';
        }

        try {
            $issuedAt = (int) Crypt::decryptString((string) $request->input(self::TOKEN_FIELD));
        } catch (DecryptException) {
            return 'invalid_token';
        }

        $age = now()->getTimestamp() - $issuedAt;

        if ($age < self::MIN_SECONDS) {
            return 'too_fast';
        }

        if ($age > self::MAX_SECONDS) {
            return 'stale_token';
        }

        if ($text !== null && preg_match_all('~https?://~i', $text) > self::MAX_LINKS) {
            return 'too_many_links';
        }

        return null;
    }
}
