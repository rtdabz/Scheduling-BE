<?php

namespace App\Services\Curriculum;

/**
 * Validation issues keyed by a stable identity, so the client can match them to rows across re-validation.
 * Errors block the import until the draft is corrected. Notices and warnings are facts shown in the formal
 * review; they need no explanation, and nothing a reviewer types can turn an error into a pass.
 */
final class IssueList
{
    /** @var array<string, array<string, mixed>> */
    private array $issues = [];

    public function add(string $severity, string $code, string $key, string $message, ?string $rowId = null, ?string $field = null): void
    {
        if (isset($this->issues[$key])) {
            return;
        }

        $this->issues[$key] = [
            'key' => $key,
            'code' => $code,
            'severity' => $severity,
            'row_id' => $rowId,
            'field' => $field,
            'message' => $message,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_values($this->issues);
    }

    public function blockingCount(): int
    {
        return $this->counts()['errors'];
    }

    /** @return array{errors: int, notices: int, warnings: int} */
    public function counts(): array
    {
        $count = fn (string $severity): int => count(array_filter($this->issues, fn (array $issue): bool => $issue['severity'] === $severity));

        return [
            'errors' => $count(CurriculumImportValidator::SEVERITY_ERROR),
            'notices' => $count(CurriculumImportValidator::SEVERITY_NOTICE),
            'warnings' => $count(CurriculumImportValidator::SEVERITY_WARNING),
        ];
    }
}
