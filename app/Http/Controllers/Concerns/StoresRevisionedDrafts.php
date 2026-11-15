<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Auto-saved workflow progress. Each save carries a client-issued, increasing revision; a
 * save older than (or equal to) the stored one is refused with 409, so delayed or retried
 * requests never roll progress back. Clearing writes a null payload at a new revision.
 */
trait StoresRevisionedDrafts
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int|string>  $scope  Columns that identify the draft (user, department, semester).
     */
    protected function writeRevisionedDraft(string $modelClass, array $scope, int $revision, ?array $payload, bool $retried = false): JsonResponse
    {
        try {
            return DB::transaction(function () use ($modelClass, $scope, $revision, $payload): JsonResponse {
                $draft = $modelClass::query()->where($scope)->lockForUpdate()->first();

                if ($draft !== null && (int) $draft->revision >= $revision) {
                    return response()->json([
                        'message' => 'A newer version of this progress is already saved.',
                        'revision' => (int) $draft->revision,
                    ], 409);
                }

                $draft ??= new $modelClass($scope);
                $draft->fill(['payload' => $payload, 'revision' => $revision])->save();

                return response()->json(['revision' => (int) $draft->revision, 'updated_at' => $draft->updated_at]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two first saves raced to create the row; the second one compares against it.
            if ($retried) {
                throw $exception;
            }

            return $this->writeRevisionedDraft($modelClass, $scope, $revision, $payload, true);
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<string, int|string>  $scope
     */
    protected function readRevisionedDraft(string $modelClass, array $scope): JsonResponse
    {
        $draft = $modelClass::query()->where($scope)->first();

        return response()->json([
            'payload' => $draft?->payload,
            'revision' => (int) ($draft?->revision ?? 0),
            'updated_at' => $draft?->updated_at,
        ]);
    }
}
