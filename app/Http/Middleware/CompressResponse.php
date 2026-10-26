<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gzips JSON responses for clients that accept it. The app's food list is a
 * few MB of JSON and the nginx in front doesn't compress JSON, so on a slow
 * network the download was most of the wait. Dart's HTTP client sends
 * `Accept-Encoding: gzip` and unzips on its own, so every app version gains.
 */
class CompressResponse
{
    /** Below this, gzip's header costs more than it saves. */
    private const MIN_BYTES = 1024;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->shouldCompress($request, $response)) {
            return $response;
        }

        $compressed = gzencode($response->getContent(), 6);
        if ($compressed === false) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Content-Length', (string) strlen($compressed));
        $response->setVary('Accept-Encoding', false);

        return $response;
    }

    private function shouldCompress(Request $request, Response $response): bool
    {
        if (! function_exists('gzencode')
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse
            || $response->headers->has('Content-Encoding')
            || ! str_contains(strtolower((string) $request->headers->get('Accept-Encoding')), 'gzip')
            || ! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return false;
        }

        $content = $response->getContent();

        return is_string($content) && strlen($content) >= self::MIN_BYTES;
    }
}
