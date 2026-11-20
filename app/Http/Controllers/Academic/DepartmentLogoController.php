<?php

namespace App\Http\Controllers\Academic;

use App\Http\Controllers\Controller;
use App\Models\Departments;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/departments/{id}/logo — serves a department logo as a real image so API payloads can reference
 * it by URL instead of embedding the base64 data. Public on purpose: <img> tags cannot send the bearer
 * token, and logos are institutional branding, not private data. The `?v=` query from
 * Departments::logoUrl() changes whenever the logo does, so the response is cached as immutable.
 */
class DepartmentLogoController extends Controller
{
    private const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __invoke(Request $request, int $id): Response
    {
        // toBase(): read the stored data URL, bypassing the model accessor that turns `logo` into this URL.
        $stored = Departments::withTrashed()->whereKey($id)->toBase()->value('logo');
        if (! is_string($stored) || ! preg_match('/^data:(image\/[a-z+]+);base64,(.+)$/s', $stored, $matches)
            || ! in_array($matches[1], self::ALLOWED_TYPES, true)) {
            return response()->noContent(404);
        }

        $etag = '"'.Departments::logoVersion($stored).'"';
        $headers = [
            'Content-Type' => $matches[1],
            'ETag' => $etag,
            'Cache-Control' => $request->query('v') === trim($etag, '"')
                ? 'public, max-age=31536000, immutable'
                : 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            // On every response, not only CORS ones: the browser caches the copy a plain <img> fetched (no
            // Origin header) and reuses it for later crossOrigin loads (PDF printing draws logos to a canvas).
            // Without this header that cached copy fails CORS and the logo disappears from printed schedules.
            'Access-Control-Allow-Origin' => '*',
        ];

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, $headers);
        }

        $binary = base64_decode($matches[2], true);
        if ($binary === false) {
            return response()->noContent(404);
        }

        return response($binary, 200, $headers + ['Content-Length' => (string) strlen($binary)]);
    }
}
