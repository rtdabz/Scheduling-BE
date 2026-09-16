<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Makes a write that carries an `Idempotency-Key` header safe to send twice.
 *
 * On a slow connection the browser can give up on a save the server went on
 * to complete. When the user presses Save again, the client resends the same
 * key (see wicars-ui/src/lib/idempotency.ts), and this middleware replays the
 * first outcome instead of creating a duplicate class, submission or approval.
 *
 * - Only successful (2xx) outcomes are remembered. A 409 or 422 depends on
 *   data other users may change in the meantime, so a retry re-runs it.
 * - A retry that arrives while the original is still running gets a 409 and
 *   can try again; it never runs the write a second time in parallel.
 * - Reusing a key for a different request is a client bug and gets a 422.
 *
 * Keys are scoped to the bearer token, so one user can never replay another
 * user's response. Requests without the header are untouched.
 */
class IdempotentRequests
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAY_HEADER = 'Idempotent-Replayed';

    /** How long a completed outcome can be replayed, in seconds. */
    private const TTL_SECONDS = 600;

    /** Upper bound on how long one write may hold the in-flight lock. */
    private const LOCK_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->headers->get(self::HEADER, ''));

        if ($key === '' || $request->isMethodSafe()) {
            return $next($request);
        }

        if (strlen($key) > 255) {
            return response()->json(['message' => 'The Idempotency-Key header is too long.'], 400);
        }

        $cacheKey = 'idempotency:'.hash('sha256', $this->scope($request).'|'.$key);
        $fingerprint = $this->fingerprint($request);

        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($cacheKey.':lock', self::LOCK_SECONDS);

        try {
            if (! $lock->get()) {
                return response()->json([
                    'message' => 'This change is still being saved. Wait a moment, then try again.',
                ], 409);
            }

            // The original may have finished between the read above and the lock.
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            if ($this->isStorable($response)) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'content_type' => $response->headers->get('Content-Type'),
                    'content' => $response->getContent(),
                ], self::TTL_SECONDS);
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{fingerprint: string, status: int, content_type: ?string, content: string}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            return response()->json([
                'message' => 'This Idempotency-Key was already used for a different request.',
            ], 422);
        }

        $response = response($stored['content'], $stored['status']);
        if ($stored['content_type']) {
            $response->headers->set('Content-Type', $stored['content_type']);
        }
        $response->headers->set(self::REPLAY_HEADER, 'true');

        return $response;
    }

    private function scope(Request $request): string
    {
        $token = $request->bearerToken();

        return $token !== null && $token !== ''
            ? 'token:'.hash('sha256', $token)
            : 'ip:'.$request->ip();
    }

    private function fingerprint(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }

    private function isStorable(Response $response): bool
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $status = $response->getStatusCode();

        return $status >= 200 && $status < 300;
    }
}
