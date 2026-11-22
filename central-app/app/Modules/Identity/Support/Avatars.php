<?php

namespace App\Modules\Identity\Support;

use App\Support\Hosts\PlatformHosts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Profile pictures people upload themselves (Google accounts bring their own).
 *
 * An upload is re-encoded to a small square JPEG, which also drops whatever
 * the camera wrote into the file (location, device). It is kept on the
 * private disk and served from the auth host at a URL that never changes
 * meaning (a new picture gets a new name), so users.avatar_url holds it like
 * a Google picture and every app shows it the same way.
 */
final class Avatars
{
    public const DIRECTORY = 'avatars';

    public const SIZE = 256;

    /** File names this class creates; the serving route accepts only these. */
    public const FILE_PATTERN = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.jpg';

    /** @return list<string> */
    public static function rules(): array
    {
        return ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:min_width=64,min_height=64,max_width=8000,max_height=8000'];
    }

    /**
     * Stores the picture and returns its public URL.
     *
     * @throws ValidationException when the file cannot be read as an image
     */
    public static function store(UploadedFile $file, string $field = 'avatar'): string
    {
        $jpeg = self::squareJpeg((string) file_get_contents($file->getRealPath()));

        if ($jpeg === null) {
            throw ValidationException::withMessages([$field => __('This picture could not be read. Try a JPEG or PNG.')]);
        }

        $name = Str::uuid()->toString().'.jpg';
        Storage::disk('local')->put(self::DIRECTORY.'/'.$name, $jpeg);

        return self::url($name);
    }

    public static function url(string $name): string
    {
        return PlatformHosts::url(PlatformHosts::auth(), self::DIRECTORY.'/'.$name);
    }

    /** Whether a users.avatar_url is a picture uploaded here (not a Google one). */
    public static function isUploaded(?string $url): bool
    {
        return is_string($url) && str_starts_with($url, PlatformHosts::url(PlatformHosts::auth(), self::DIRECTORY.'/'));
    }

    /** Removes an uploaded picture's file; anything else is left alone. */
    public static function delete(?string $url): void
    {
        if (! self::isUploaded($url)) {
            return;
        }

        $name = basename((string) parse_url((string) $url, PHP_URL_PATH));
        if (preg_match('/^'.self::FILE_PATTERN.'$/', $name) === 1) {
            Storage::disk('local')->delete(self::DIRECTORY.'/'.$name);
        }
    }

    /** Centre crop to a square, scaled to SIZE, as JPEG. Null when the bytes are not an image GD can read. */
    private static function squareJpeg(string $bytes): ?string
    {
        if ($bytes === '' || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = min(self::SIZE, $side);

        $square = imagecreatetruecolor($target, $target);
        // Transparent PNG areas become white rather than black.
        imagefill($square, 0, 0, (int) imagecolorallocate($square, 255, 255, 255));
        imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $target, $target, $side, $side);

        ob_start();
        imagejpeg($square, null, 85);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($square);

        return $jpeg === '' ? null : $jpeg;
    }
}
