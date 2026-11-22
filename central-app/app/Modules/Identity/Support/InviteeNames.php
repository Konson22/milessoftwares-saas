<?php

namespace App\Modules\Identity\Support;

use Illuminate\Support\Str;

/**
 * Who can join from an invitation with nothing more than "Accept": Gmail
 * addresses. Holding the link proves the inbox, and they come back later
 * with "Continue with Google", so they need no password. Until Google tells
 * us their name, the account is named after the address.
 */
final class InviteeNames
{
    /** @var list<string> */
    private const GOOGLE_MAIL_DOMAINS = ['gmail.com', 'googlemail.com'];

    public static function isGmail(string $email): bool
    {
        $domain = Str::lower(Str::afterLast(trim($email), '@'));

        return in_array($domain, self::GOOGLE_MAIL_DOMAINS, true);
    }

    /** "nyandeng.akol+work@gmail.com" → "Nyandeng Akol". */
    public static function fromEmail(string $email): string
    {
        $local = Str::before(Str::before(trim($email), '@'), '+');
        $words = preg_split('/[._\-]+/', $local, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $name = trim(implode(' ', array_map(fn (string $w): string => Str::ucfirst(Str::lower(preg_replace('/\d+/', '', $w) ?? '')), $words)));

        return Str::limit($name !== '' ? preg_replace('/\s+/', ' ', $name) ?? $local : $local, 255, '');
    }
}
