<?php

namespace App\Services\Curriculum;

/**
 * Validation issues keyed by a stable identity, so acknowledgements survive table sorting and re-validation.
 * A review issue blocks until the reviewer acknowledges its key with a reason.
 */
final class IssueList
{
    /** @var array<string, array<string, mixed>> */
    private array $issues = [];

    /** @param  array<string, mixed>  $acknowledgements  issue key => reason */
    public function __construct(private readonly array $acknowledgements = []) {}

    public function add(string $severity, string $code, string $key, string $message, ?string $rowId = null, ?string $field = null): void
    {
        if (isset($this->issues[$key])) {
            return;
        }

        $reason = $severity === CurriculumImportValidator::SEVERITY_REVIEW ? $this->reasonFor($key) : null;

        $this->issues[$key] = [
            'key' => $key,
            'code' => $code,
            'severity' => $severity,
            'row_id' => $rowId,
            'field' => $field,
            'message' => $message,
            'acknowledged' => $reason !== null,
            'reason' => $reason,
        ];
    }

    private function reasonFor(string $key): ?string
    {
        $reason = $this->acknowledgements[$key] ?? null;

        return is_string($reason) && mb_strlen(trim($reason)) >= 3 ? trim($reason) : null;
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->issues);
    }

    public function blockingCount(): int
    {
        return count(array_filter($this->issues, fn (array $issue): bool => $issue['severity'] === CurriculumImportValidator::SEVERITY_ERROR
            || ($issue['severity'] === CurriculumImportValidator::SEVERITY_REVIEW && ! $issue['acknowledged'])));
    }

    /** @return array{errors: int, reviews: int, reviews_open: int, warnings: int} */
    public function counts(): array
    {
        $count = fn (callable $filter): int => count(array_filter($this->issues, $filter));

        return [
            'errors' => $count(fn (array $issue): bool => $issue['severity'] === CurriculumImportValidator::SEVERITY_ERROR),
            'reviews' => $count(fn (array $issue): bool => $issue['severity'] === CurriculumImportValidator::SEVERITY_REVIEW),
            'reviews_open' => $count(fn (array $issue): bool => $issue['severity'] === CurriculumImportValidator::SEVERITY_REVIEW && ! $issue['acknowledged']),
            'warnings' => $count(fn (array $issue): bool => $issue['severity'] === CurriculumImportValidator::SEVERITY_WARNING),
        ];
    }

    /** @return array<string, array{reason: string, message: string}> */
    public function acknowledged(): array
    {
        $acknowledged = [];
        foreach ($this->issues as $key => $issue) {
            if ($issue['acknowledged']) {
                $acknowledged[$key] = ['reason' => (string) $issue['reason'], 'message' => (string) $issue['message']];
            }
        }

        return $acknowledged;
    }
}
