<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A project's code starts every task number (WEB-1). Optional when a project
 * is set up: left blank, one is made from the name and kept unique in the
 * organization (projects are unique on organization_id + code).
 *
 *   Water Supply Project  -> WSP   (three or more words: their initials)
 *   Website Development   -> WEB   (otherwise: the first word's first letters)
 *   Education Programme   -> EDU
 *
 * Taken already: WEB2, WEB3, ... The database's unique index is the last
 * word when two are set up at once (ProjectController retries).
 */
final class ProjectCodes
{
    /** A letter, then 1 to 9 letters or digits. */
    public const PATTERN = '/^[A-Z][A-Z0-9]{1,9}$/';

    private const SKIP = ['A', 'AN', 'AND', 'FOR', 'IN', 'OF', 'ON', 'THE', 'TO', 'WITH'];

    /**
     * The first free code for a project of this name, from the $attempt-th candidate on.
     */
    public static function suggest(string $name, string $organizationId, int $attempt = 0): string
    {
        $base = self::base($name);
        $taken = DB::table('projects')->where('organization_id', $organizationId)
            ->where('code', 'like', $base.'%')->pluck('code')->flip();

        for ($n = $attempt + 1; ; $n++) {
            $code = $n === 1 ? $base : mb_substr($base, 0, 10 - strlen((string) $n)).$n;
            if (! $taken->has($code)) {
                return $code;
            }
        }
    }

    /**
     * Two to ten capitals, starting with a letter.
     */
    public static function base(string $name): string
    {
        $words = collect(preg_split('/[^A-Z0-9]+/', Str::upper(Str::ascii($name))) ?: [])
            ->filter(fn (string $word) => $word !== '')
            ->values();
        $meaningful = $words->reject(fn (string $word) => in_array($word, self::SKIP, true))->values();
        $words = $meaningful->isEmpty() ? $words : $meaningful;
        // Starts with a letter: leading digits move behind.
        $letters = $words->filter(fn (string $word) => ctype_alpha($word[0]));

        $code = $letters->count() >= 3
            ? $letters->take(10)->map(fn (string $word) => $word[0])->implode('')
            : mb_substr((string) preg_replace('/[^A-Z0-9]/', '', (string) ($letters->first() ?? '')), 0, 3);

        if (strlen($code) < 2) {
            $code = mb_substr($code.preg_replace('/[^A-Z0-9]/', '', $words->implode('')).'PRJ', 0, 3);
            if (! ctype_alpha($code[0])) {
                $code = 'P'.mb_substr($code, 0, 2);
            }
        }

        return $code;
    }
}
