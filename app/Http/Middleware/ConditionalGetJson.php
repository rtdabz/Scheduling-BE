<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Adds conditional-GET (ETag / 304) support to read-only JSON API responses.
 *
 * The server-side cache already makes a repeat /initial-data cheap to produce,
 * but the payload was still serialised over the wire in full every time — and
 * the client re-parsed it — even when nothing had changed. Navigating between
 * modules refetches the same data repeatedly, so revalidation is where the
 * remaining wall-clock time goes.
 *
 * Responses are marked `private` and vary on Authorization: this is per-user
 * data and must never be held by a shared cache. `no-cache` does not mean "do
 * not store" — it means "revalidate before reuse", which is exactly the
 * behaviour we want: the browser always asks, and usually gets a bodyless 304.
 */
class ConditionalGetJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isCacheable($request, $response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
        // Two users' responses for the same URL differ, and the token is what
        // distinguishes them.
        $response->setVary('Authorization', false);

        $etag = '"'.md5($response->getContent()).'"';
        $response->headers->set('ETag', $etag);

        if ($this->matches($request->headers->get('If-None-Match'), $etag)) {
            $response->setNotModified();
        }

        return $response;
    }

    private function isCacheable(Request $request, Response $response): bool
    {
        if (! $request->isMethodCacheable()) {
            return false;
        }

        // getContent() on these either returns false or would consume the
        // stream before it reaches the client.
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        // A response that already carries validators was deliberately configured
        // by its controller; leave it alone.
        if ($response->headers->has('ETag') || $response->headers->has('Last-Modified')) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    /**
     * If-None-Match is a comma-separated list and may be "*". Weak validators
     * (W/"...") compare equal to their strong form for our purposes.
     */
    private function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }

        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        $normalise = static fn (string $tag): string => ltrim(trim($tag), 'W/');

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if ($normalise($candidate) === $normalise($etag)) {
                return true;
            }
        }

        return false;
    }
}
