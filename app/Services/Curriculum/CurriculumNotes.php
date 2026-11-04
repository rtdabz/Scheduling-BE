<?php

namespace App\Services\Curriculum;

/**
 * Notes printed on the source curriculum document. They are curriculum content kept exactly as extracted:
 * never turned into courses, prerequisites or rules. Colour runs are spans over the note text
 * ({length, color}) so request trimming can never move a colour onto the wrong characters.
 *
 * Everything here is checked for consistency only. The server receives the browser's extraction, not the
 * document, so it cannot confirm a note against the original file.
 */
final class CurriculumNotes
{
    public const MAX_NOTES = 50;

    public const MAX_TEXT = 2000;

    public const SCOPES = ['curriculum', 'year', 'term', 'course'];

    public const FLAGS = ['color_unknown', 'manual'];

    /** Control characters other than tab and line breaks. */
    private const CONTROL = '/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}]/u';

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'notes' => 'nullable|array|max:'.self::MAX_NOTES,
            'notes.*.id' => ['required', 'string', 'max:64', 'distinct', 'regex:/^[A-Za-z0-9_-]+$/'],
            'notes.*.text' => ['required', 'string', 'max:'.self::MAX_TEXT, 'not_regex:'.self::CONTROL],
            'notes.*.source_text' => ['nullable', 'string', 'max:'.self::MAX_TEXT, 'not_regex:'.self::CONTROL],
            'notes.*.runs' => 'required|array|min:1|max:200',
            'notes.*.runs.*.length' => 'required|integer|min:1|max:'.self::MAX_TEXT,
            'notes.*.runs.*.color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'notes.*.scope' => 'required|array',
            'notes.*.scope.level' => ['required', 'string', 'in:'.implode(',', self::SCOPES)],
            'notes.*.scope.year_level' => 'nullable|integer|between:1,4',
            'notes.*.scope.semester' => 'nullable|integer|between:1,3',
            'notes.*.scope.row_id' => 'nullable|string|max:64',
            'notes.*.provenance' => 'nullable|array',
            'notes.*.provenance.kind' => 'required_with:notes.*.provenance|string|in:pdf,csv,xlsx,docx',
            'notes.*.provenance.page' => 'nullable|integer|min:1|max:50',
            'notes.*.provenance.bbox' => 'nullable|array|size:4',
            'notes.*.provenance.bbox.*' => 'numeric|min:0|max:10000',
            'notes.*.provenance.record' => 'nullable|integer|min:0',
            'notes.*.provenance.row' => 'nullable|integer|min:0',
            'notes.*.provenance.table' => 'nullable|integer|min:0',
            'notes.*.provenance.sheet' => 'nullable|string|max:100',
            'notes.*.flags' => 'nullable|array|max:'.count(self::FLAGS),
            'notes.*.flags.*' => ['string', 'distinct', 'in:'.implode(',', self::FLAGS)],

            // Parts of the source the extractor could not read, so they are reviewed rather than dropped.
            'source.unread' => 'nullable|array|max:20',
            'source.unread.*.reason' => 'required|string|in:image,unrecognized_text',
            'source.unread.*.page' => 'nullable|integer|min:1|max:50',
            'source.unread.*.bbox' => 'nullable|array|size:4',
            'source.unread.*.bbox.*' => 'numeric|min:0|max:10000',
            'source.unread.*.text' => ['nullable', 'string', 'max:500', 'not_regex:'.self::CONTROL],
            // What the reviewer found there: a note they typed in, or no note at all.
            'source.unread.*.resolution' => 'nullable|string|in:transcribed,no_note',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  shape-validated input
     * @return list<array<string, mixed>> notes in source order, ready to store (course scope still by row_id)
     */
    public static function validate(array $payload, IssueList $issues): array
    {
        $rowIds = array_flip(array_column($payload['rows'], 'row_id'));
        $notes = $payload['notes'] ?? null;
        if (! $notes) {
            $notes = self::fromLegacy($payload['metadata']['notes'] ?? []);
        }

        $normalized = [];
        foreach ($notes as $note) {
            $id = (string) $note['id'];
            $text = (string) $note['text'];
            $source = isset($note['source_text']) ? (string) $note['source_text'] : null;
            $flags = array_values($note['flags'] ?? []);
            $runs = array_map(fn (array $run): array => [
                'length' => (int) $run['length'],
                'color' => isset($run['color']) ? strtoupper((string) $run['color']) : null,
            ], $note['runs']);

            if (array_sum(array_column($runs, 'length')) !== mb_strlen($text)) {
                $issues->add(CurriculumImportValidator::SEVERITY_ERROR, 'note_runs_mismatch', "note_runs_mismatch:{$id}", 'A note\'s colour information does not cover its text exactly. Extract the file again.', field: "notes.{$id}");
            }
            // Only leading form blanks (underscores) may be removed for display; wording stays as printed.
            if ($source !== null && self::display($source) !== $text) {
                $issues->add(CurriculumImportValidator::SEVERITY_ERROR, 'note_text_mismatch', "note_text_mismatch:{$id}", 'A note differs from the text read from the document. Source notes are kept exactly as printed.', field: "notes.{$id}");
            }

            $scope = self::scope($note['scope'], $rowIds);
            if ($scope === null) {
                $issues->add(CurriculumImportValidator::SEVERITY_ERROR, 'note_scope_invalid', "note_scope_invalid:{$id}", 'A note is placed under a year, term, or course that is not in this draft.', field: "notes.{$id}");
                $scope = ['level' => 'curriculum'];
            }
            if (in_array('color_unknown', $flags, true)) {
                $issues->add(CurriculumImportValidator::SEVERITY_NOTICE, 'note_color_unknown', "note_color_unknown:{$id}", "The colour of the note \"".self::excerpt($text)."\" could not be read. It is saved without a colour and shown in the default text colour, not as an exact copy of the document.", field: "notes.{$id}");
            }

            $normalized[] = [
                'id' => $id,
                'text' => $text,
                'source_text' => $source,
                'runs' => self::merge($runs),
                'scope' => $scope,
                'provenance' => $note['provenance'] ?? null,
                'flags' => $flags,
            ];
        }

        // Unread parts of the document cannot be confirmed away: each needs what the reviewer found there.
        $typed = count(array_filter($normalized, fn (array $note): bool => in_array('manual', $note['flags'], true)));
        foreach (array_values($payload['source']['unread'] ?? []) as $index => $region) {
            $where = isset($region['page']) ? "Page {$region['page']}: " : '';
            $what = $region['reason'] === 'image'
                ? "{$where}an image in the course area was not read"
                : "{$where}text outside the course tables was not recognised as a course or note: \"".self::excerpt((string) ($region['text'] ?? '')).'"';
            $resolution = $region['resolution'] ?? null;
            if ($resolution === null) {
                $issues->add(CurriculumImportValidator::SEVERITY_ERROR, 'source_unread', "source_unread:{$index}", "{$what}. Check the document, then type in any note it holds or mark it as having no note.", field: 'source.unread');
            } elseif ($resolution === 'transcribed' && $typed === 0) {
                $issues->add(CurriculumImportValidator::SEVERITY_ERROR, 'source_unread', "source_unread:{$index}", "{$what}. It is marked as typed in, but no note was added; add the note or mark it as having no note.", field: 'source.unread');
            } else {
                $issues->add(CurriculumImportValidator::SEVERITY_NOTICE, 'source_unread_resolved', "source_unread:{$index}", $what.($resolution === 'transcribed' ? '; its note was typed in by the reviewer.' : '; the reviewer found no note in it.'), field: 'source.unread');
            }
        }

        return $normalized;
    }

    /**
     * Notes for the saved curriculum: a course scope now names the course instead of the draft row.
     *
     * @param  list<array<string, mixed>>  $notes
     * @param  array<string, int>  $courseIds  row_id => course id
     * @return list<array<string, mixed>>|null
     */
    public static function forCurriculum(array $notes, array $courseIds): ?array
    {
        if ($notes === []) {
            return null;
        }

        return array_map(function (array $note) use ($courseIds): array {
            if ($note['scope']['level'] === 'course') {
                $note['scope'] = ['level' => 'course', 'course_id' => $courseIds[$note['scope']['row_id']]];
            }

            return $note;
        }, $notes);
    }

    public static function display(string $source): string
    {
        return trim((string) preg_replace('/^[\s_]+/u', '', $source));
    }

    /**
     * Older drafts sent notes as plain strings: kept with no colour or position, never invented ones.
     *
     * @param  list<string>  $strings
     * @return list<array<string, mixed>>
     */
    private static function fromLegacy(array $strings): array
    {
        $notes = [];
        foreach (array_values(array_filter($strings, fn ($text): bool => is_string($text) && trim($text) !== '')) as $index => $text) {
            $notes[] = [
                'id' => 'legacy-'.($index + 1),
                'text' => $text,
                'source_text' => null,
                'runs' => [['length' => mb_strlen($text), 'color' => null]],
                'scope' => ['level' => 'curriculum'],
                'provenance' => null,
                'flags' => [],
            ];
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, int>  $rowIds
     * @return array<string, mixed>|null
     */
    private static function scope(array $scope, array $rowIds): ?array
    {
        return match ($scope['level']) {
            'curriculum' => ['level' => 'curriculum'],
            'year' => isset($scope['year_level']) ? ['level' => 'year', 'year_level' => (int) $scope['year_level']] : null,
            'term' => isset($scope['year_level'], $scope['semester'])
                ? ['level' => 'term', 'year_level' => (int) $scope['year_level'], 'semester' => (int) $scope['semester']]
                : null,
            'course' => isset($scope['row_id'], $rowIds[$scope['row_id']]) ? ['level' => 'course', 'row_id' => (string) $scope['row_id']] : null,
            default => null,
        };
    }

    /**
     * @param  list<array{length: int, color: string|null}>  $runs
     * @return list<array{length: int, color: string|null}>
     */
    private static function merge(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['color'] === $run['color']) {
                $merged[$last]['length'] += $run['length'];
            } else {
                $merged[] = $run;
            }
        }

        return $merged;
    }

    private static function excerpt(string $text): string
    {
        $flat = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($flat) > 80 ? mb_substr($flat, 0, 77).'…' : $flat;
    }
}
