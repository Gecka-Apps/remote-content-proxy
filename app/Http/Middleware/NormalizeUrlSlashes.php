<?php

declare(strict_types=1);

/**
 * @author Laurent Dinclaux <laurent@gecka.nc>
 * @copyright 2026 Gecka
 * @license AGPL-3.0-or-later
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Normalize consecutive slashes in request URI paths.
 *
 * Some clients (e.g. Thunderbird extension) may produce double slashes
 * when concatenating base URL and path. This middleware collapses them
 * to prevent 404 errors.
 */
class NormalizeUrlSlashes
{
    /**
     * @param Request $request
     * @param Closure(Request): Response $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $uri = $request->getRequestUri();
        $normalized = preg_replace('#/{2,}#', '/', $uri);

        if ($normalized !== $uri) {
            $request->server->set('REQUEST_URI', $normalized);
            $request = Request::createFromBase($request);
        }

        return $next($request);
    }
}
