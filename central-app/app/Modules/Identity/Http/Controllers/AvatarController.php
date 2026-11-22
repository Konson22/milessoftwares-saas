<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Support\Avatars;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves uploaded profile pictures from the auth host, to every app. A file
 * is never replaced under the same name, so it can be cached for good.
 */
class AvatarController
{
    public function __invoke(string $file): StreamedResponse
    {
        $path = Avatars::DIRECTORY.'/'.$file;
        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, $file, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
