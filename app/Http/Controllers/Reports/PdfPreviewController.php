<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\TemporaryPdfPreview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;

final class PdfPreviewController extends Controller
{
    public function __construct(private readonly TemporaryPdfPreview $previews) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['bail', 'required', 'string', 'max:184', 'ends_with:.pdf', function ($attribute, $value, $fail) {
                if (preg_match('/[<>:"\/\\\\|?*\p{C}]/u', $value)) {
                    $fail('The filename contains unsupported characters.');
                }
            }],
        ]);
        abort_unless($request->bearerToken(), 401);
        abort_unless(str_starts_with((string) $request->header('Content-Type'), 'application/pdf'), 415);
        abort_if((int) $request->header('Content-Length') > 10 * 1024 * 1024, 413, 'The PDF exceeds the 10 MB preview limit.');
        $pdf = $request->getContent();
        abort_if(strlen($pdf) > 10 * 1024 * 1024, 413, 'The PDF exceeds the 10 MB preview limit.');
        abort_unless(str_starts_with($pdf, '%PDF-'), 422, 'The preview must contain a PDF.');

        $id = $this->previews->store((int) $request->user()->id, $request->bearerToken(), $validated['filename'], $pdf);

        return response()->json([
            'id' => $id,
            'url' => URL::temporarySignedRoute('pdf-previews.show', now()->addMinutes(TemporaryPdfPreview::MINUTES), [
                'preview' => $id,
                'filename' => $validated['filename'],
            ], absolute: false),
        ], 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request): Response
    {
        $preview = $request->attributes->get('pdf_preview');
        abort_unless((int) $request->user()->id === $preview['user_id'], 403);
        $fallback = str_replace('%', '-', Str::ascii($preview['filename']));

        return response(base64_decode($preview['pdf'], true), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition('inline', $preview['filename'], $fallback),
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, string $preview): Response
    {
        $entry = $this->previews->get($preview);
        if ($entry) {
            abort_unless($entry['user_id'] === (int) $request->user()->id, 403);
            $this->previews->forget($preview);
        }

        return response()->noContent();
    }
}
