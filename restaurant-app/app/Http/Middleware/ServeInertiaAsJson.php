<?php

namespace App\Http\Middleware;

use App\Support\AppPages;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Support\Header;
use Miles\PlatformClient\PlatformConfig;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web pages, answered to the desktop app as data (routes/api.php,
 * AppPages). The page's controller runs as for the browser; what it renders
 * comes back as {component, props, url} and what it does as {message,
 * redirect}, with validation errors as usual (422). The app names the page
 * it acts from (X-App-Page, its web path); back() returns there and comes
 * back as redirect null: stay, and reload.
 *
 * The page is asked for as Inertia's own JSON for the controller only: the
 * header is gone again before HandleInertiaRequests, around this, looks at
 * the response, so it never checks the asset version of an app that has
 * none.
 */
class ServeInertiaAsJson
{
    public const FROM_HEADER = 'X-App-Page';

    public function __construct(private readonly PlatformConfig $platform) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // No cookie, so no session to keep: the store (the one redirects
        // flash into) only carries this request's flash message.
        $session = app('session.store');
        $session->flush();
        $request->setLaravelSession($session);

        // Where back() goes: the page acted from, on the web.
        $from = trim((string) $request->headers->get(self::FROM_HEADER, ''), '/');
        $request->headers->set('referer', url(trim($this->platform->path().'/'.$from, '/')));

        $request->headers->set(Header::INERTIA, 'true');
        try {
            $response = $next($request);
        } finally {
            $request->headers->remove(Header::INERTIA);
        }

        if ($response instanceof RedirectResponse) {
            $target = $this->page($response->getTargetUrl());

            return response()->json([
                'message' => $session->get('success'),
                'redirect' => $target === $from ? null : $target,
            ]);
        }

        if ($response instanceof JsonResponse && $response->headers->has(Header::INERTIA)) {
            $page = $response->getData(true);
            $response->headers->remove(Header::INERTIA);

            return $response->setData([
                'component' => $page['component'],
                'props' => $page['props'],
                'url' => $this->page((string) $page['url']),
            ]);
        }

        return $response;
    }

    /**
     * A page of this product as the app names it, its web path under the
     * product ("orders/<id>", "orders?day=2026-10-25"); null for anywhere
     * else.
     */
    private function page(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
        $path = trim((string) parse_url($url, PHP_URL_PATH), '/');
        $query = parse_url($url, PHP_URL_QUERY);
        $prefix = trim($this->platform->path(), '/');

        if ($prefix !== '') {
            if ($path !== $prefix && ! str_starts_with($path, $prefix.'/')) {
                return null;
            }
            $path = ltrim(substr($path, strlen($prefix)), '/');
        }
        // An Inertia page's url is the app route's: back to the web path.
        $path = preg_replace('~^'.preg_quote(AppPages::PREFIX, '~').'(/|$)~', '', $path) ?? $path;

        return $path.(is_string($query) && $query !== '' ? "?{$query}" : '');
    }
}
