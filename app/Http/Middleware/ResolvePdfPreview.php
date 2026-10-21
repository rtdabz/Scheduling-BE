<?php

namespace App\Http\Middleware;

use App\Services\TemporaryPdfPreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ResolvePdfPreview
{
    public function __construct(private readonly TemporaryPdfPreview $previews) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Native PDF viewers cannot attach our SPA's Authorization header.
        // A signed, expiring URL delegates access to exactly one cached PDF;
        // Sanctum, active-account, and capability middleware still run afterward.
        abort_unless($request->hasValidRelativeSignature(), 403);
        $preview = $this->previews->get((string) $request->route('preview'));
        abort_unless($preview && $preview['filename'] === $request->route('filename'), 404);
        $request->headers->set('Authorization', 'Bearer '.$preview['token']);
        $request->attributes->set('pdf_preview', $preview);

        return $next($request);
    }
}
