<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConditionalGetJson
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $this->isCacheable($request, $response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
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

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        if ($response->getStatusCode() !== 200) {
            return false;
        }

        if ($response->headers->has('ETag') || $response->headers->has('Last-Modified')) {
            return false;
        }

        return str_contains((string) $response->headers->get('Content-Type'), 'json');
    }

    private function matches(?string $ifNoneMatch, string $etag): bool
    {
        if ($ifNoneMatch === null || $ifNoneMatch === '') {
            return false;
        }

        if (trim($ifNoneMatch) === '*') {
            return true;
        }

        $normalise = static fn (string $tag): string => (string) preg_replace(
            '/-(gzip|br)"$/',
            '"',
            ltrim(trim($tag), 'W/'),
        );

        foreach (explode(',', $ifNoneMatch) as $candidate) {
            if ($normalise($candidate) === $normalise($etag)) {
                return true;
            }
        }

        return false;
    }
}
