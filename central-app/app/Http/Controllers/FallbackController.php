<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Answers every address no route matches, inside the web middleware so the
 * error page knows who is signed in. An address that exists for other
 * methods still answers 405, as it would without this route.
 */
class FallbackController extends Controller
{
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function __invoke(Request $request, Router $router): never
    {
        $allowed = [];

        foreach (array_diff(self::METHODS, [$request->getMethod()]) as $method) {
            try {
                $route = $router->getRoutes()->match(Request::create($request->getUri(), $method));
            } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
                continue;
            }

            if (! $route->isFallback) {
                $allowed[] = $method;
            }
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedHttpException($allowed);
        }

        abort(404);
    }
}
