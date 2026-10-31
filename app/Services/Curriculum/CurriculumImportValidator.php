<?php

namespace App\Services\Curriculum;

use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Validation\Rule;

/**
 * Canonical server validation of a reviewed import draft. It never writes; the commit reruns it inside its transaction.
 */
final class CurriculumImportValidator
{
    public const MAX_ROWS = 2000;

    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_REVIEW = 'review';

    public const SEVERITY_WARNING = 'warning';

    public const FORMATS = ['pdf', 'csv', 'xlsx', 'docx'];

    private const TERM_LABELS = [1 => 'First Semester', 2 => 'Second Semester', 3 => 'Summer'];

    public function __construct(private readonly CurriculumImportSettings $settings) {}

    /** @return array<string, mixed> */
    public static function rules(User $user, bool $commit): array
    {
        $maxLecture = SchedulingPolicy::maxHoursPerComponent();
        $maxLab = SchedulingPolicy::maxHoursPerComponent();

        return [
            'request_key' => [$commit ? 'required' : 'nullable', 'string', 'min:8', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/'],
            'fingerprint' => [$commit ? 'required' : 'nullable', 'string', 'size:64'],
            'expected_settings_revision' => [$commit ? 'required' : 'nullable', 'integer', 'min:1'],

            'source' => 'required|array',
            'source.filename' => 'required|string|max:255',
            'source.format' => ['required', 'string', Rule::in(self::FORMATS)],
            'source.size_bytes' => 'required|integer|min:0|max:'.(10 * 1024 * 1024),
            'source.sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'source.extractor_version' => 'required|string|max:64',

            'metadata' => 'required|array',
            'metadata.name' => 'required|string|max:255',
            'metadata.code' => 'prohibited',
            'metadata.program_id' => [
                'nullable', 'integer',
                Rule::exists('programs', 'id')->whereNull('deleted_at')->where(fn ($query) => $query->where('department_id', $user->department_id)),
            ],
            'metadata.effective_school_year' => 'required|string|max:9',
            'metadata.cmo_number' => ['nullable', 'string', 'max:32', 'regex:/^[0-9A-Za-z-]+$/'],
            'metadata.cmo_series' => 'nullable|integer|between:1900,2100',
            'metadata.description' => 'nullable|string|max:2000',
            'metadata.notes' => 'nullable|array|max:20',
            'metadata.notes.*' => 'string|max:1000',

            'groups' => 'present|array|max:12',
            'groups.*.year_level' => 'required|integer|between:1,4',
            'groups.*.semester' => 'required|integer|between:1,3',
            'groups.*.reported' => 'nullable|array',
            'groups.*.reported.lec' => 'nullable|numeric|min:0|max:500',
            'groups.*.reported.lab' => 'nullable|numeric|min:0|max:500',
            'groups.*.reported.tu' => 'nullable|numeric|min:0|max:500',

            'rows' => 'required|array|min:1|max:'.self::MAX_ROWS,
            'rows.*.row_id' => 'required|string|max:64|distinct',
            'rows.*.year_level' => 'required|integer|between:1,4',
            'rows.*.semester' => 'required|integer|between:1,3',
            'rows.*.code' => ['required', 'string', 'max:255', 'not_regex:/\p{Cc}/u'],
            'rows.*.title' => ['required', 'string', 'max:255', 'not_regex:/\p{Cc}/u'],
            'rows.*.lecture_units' => 'required|integer|min:0|max:'.$maxLecture,
            'rows.*.lab_units' => 'required|integer|min:0|max:'.$maxLab,
            'rows.*.total_units' => 'required|integer|min:0|max:'.($maxLecture + $maxLab),
            'rows.*.category' => 'nullable|string|in:major,minor',
            'rows.*.scheduling_basis' => 'nullable|string|in:weekly,total_hours',
            'rows.*.total_hours' => 'nullable|integer|min:1|max:5000',
            // Reference text only, stored as written; it never feeds scheduling or validation.
            'rows.*.prerequisites' => 'nullable|string|max:1000',
            'rows.*.source' => 'nullable|array',
            'rows.*.source.code' => 'nullable|string|max:255',
            'rows.*.source.title' => 'nullable|string|max:255',
            'rows.*.source.lec' => 'nullable|numeric',
            'rows.*.source.lab' => 'nullable|numeric',
            'rows.*.source.tu' => 'nullable|numeric',
            'rows.*.source.prerequisites' => 'nullable|string|max:1000',
            'rows.*.source.flags' => 'nullable|array|max:20',
            'rows.*.source.flags.*' => 'string|max:64',
            'rows.*.source.provenance' => 'nullable|array',

            'acknowledgements' => 'nullable|array|max:'.(self::MAX_ROWS * 4),
            'acknowledgements.*' => 'nullable|string|max:500',
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'rows.*.lecture_units.integer' => 'Fractional credits are not supported by the current course schema. Enter whole lecture units.',
            'rows.*.lab_units.integer' => 'Fractional credits are not supported by the current course schema. Enter whole laboratory units.',
            'rows.*.total_units.integer' => 'Fractional credits are not supported by the current course schema. Enter whole total units.',
            'rows.*.row_id.distinct' => 'Each draft row needs its own row ID.',
            'rows.*.code.not_regex' => 'The course code contains control characters.',
            'metadata.code.prohibited' => 'The curriculum code is assigned automatically on import.',
            'rows.*.title.not_regex' => 'The course title contains control characters.',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload  shape-validated input
     * @return array<string, mixed>
     */
    public function validate(array $payload, Departments $department, User $user): array
    {
        $issues = new IssueList($payload['acknowledgements'] ?? []);
        $rules = $this->settings->rules((int) $department->id);
        $lectureRate = (int) $department->lecture_minutes_per_unit;
        $labRate = (int) $department->laboratory_minutes_per_unit;

        $metadata = $this->validateMetadata($payload['metadata'], $department, $user, $issues);

        $byCode = [];
        foreach ($payload['rows'] as $row) {
            $byCode[CurriculumImportSettings::normalizeCode($row['code'])][] = $row['row_id'];
        }

        $catalog = Course::withTrashed()
            ->where('department_id', $department->id)
            ->get()
            ->groupBy(fn (Course $course): string => CurriculumImportSettings::normalizeCode($course->course_code));

        $resolved = [];
        foreach ($payload['rows'] as $row) {
            $resolved[$row['row_id']] = $this->validateRow($row, $byCode, $catalog, $rules, $lectureRate, $labRate, $issues);
        }

        [$groups, $totals] = $this->validateGroups($payload['groups'], $payload['rows'], $issues);

        $catalogDecisions = array_map(
            fn (array $row): array => [$row['row_id'], $row['catalog']['action'], $row['catalog']['course_id']],
            array_values($resolved),
        );

        return [
            'valid' => $issues->blockingCount() === 0,
            'fingerprint' => self::fingerprint($payload, (int) $department->curriculum_import_settings_revision, $catalogDecisions),
            'settings_revision' => (int) $department->curriculum_import_settings_revision,
            'settings' => [
                'lecture_minutes_per_unit' => $lectureRate,
                'laboratory_minutes_per_unit' => $labRate,
            ],
            'metadata' => $metadata,
            'counts' => $issues->counts(),
            'issues' => $issues->all(),
            'acknowledged' => $issues->acknowledged(),
            'rows' => array_values($resolved),
            'groups' => $groups,
            'totals' => $totals,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array{0: string, 1: string, 2: int|null}>  $catalogDecisions
     */
    public static function fingerprint(array $payload, int $settingsRevision, array $catalogDecisions): string
    {
        unset($payload['request_key'], $payload['fingerprint'], $payload['expected_settings_revision']);

        return hash('sha256', json_encode([
            'payload' => self::canonical($payload),
            'settings_revision' => $settingsRevision,
            'catalog' => $catalogDecisions,
        ], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::canonical(...), $value);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function validateMetadata(array $metadata, Departments $department, User $user, IssueList $issues): array
    {
        $programId = $metadata['program_id'] ?? null;
        if ($user->role === 'program_head') {
            $programId = $user->program_id;
            if ($programId === null) {
                $issues->add(self::SEVERITY_ERROR, 'program_head_without_program', 'program_head_without_program', 'Your account is not linked to a program yet.', field: 'metadata.program_id');
            }
        }

        $year = (string) $metadata['effective_school_year'];
        if (! preg_match('/^(\d{4})-(\d{4})$/', $year, $years) || (int) $years[2] !== (int) $years[1] + 1) {
            $issues->add(self::SEVERITY_ERROR, 'effective_school_year_invalid', 'effective_school_year_invalid', 'The effective school year must be two consecutive years, e.g. 2025-2026.', field: 'metadata.effective_school_year');
        }

        $program = $programId === null ? null : Program::query()->find($programId);
        // Preview only; the commit assigns the code again inside its transaction.
        $code = CurriculumCodeGenerator::next(CurriculumCodeGenerator::base($department, $program, $year));
        if ($programId === null && $user->role !== 'program_head') {
            $issues->add(self::SEVERITY_WARNING, 'program_not_selected', 'program_not_selected', 'No program is selected, so the curriculum will be available to every program in the department.', field: 'metadata.program_id');
        }

        return [
            'name' => trim((string) $metadata['name']),
            'code' => $code,
            'department_id' => (int) $department->id,
            'program_id' => $programId === null ? null : (int) $programId,
            'program' => $program === null ? null : ['id' => (int) $program->id, 'code' => $program->code, 'name' => $program->name, 'major' => $program->major],
            'effective_school_year' => $year,
            'cmo_number' => $metadata['cmo_number'] ?? null,
            'cmo_series' => isset($metadata['cmo_series']) ? (int) $metadata['cmo_series'] : null,
            'description' => $metadata['description'] ?? null,
            'notes' => array_values($metadata['notes'] ?? []),
            'status' => 'deactivated',
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, list<string>>  $byCode
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Course>>  $catalog
     * @return array<string, mixed>
     */
    private function validateRow(array $row, array $byCode, $catalog, $rules, int $lectureRate, int $labRate, IssueList $issues): array
    {
        $id = $row['row_id'];
        $code = CurriculumImportSettings::normalizeCode($row['code']);
        $title = trim((string) preg_replace('/\s+/u', ' ', (string) $row['title']));
        $lec = (int) $row['lecture_units'];
        $lab = (int) $row['lab_units'];
        $tu = (int) $row['total_units'];
        $source = $row['source'] ?? [];
        $label = $code !== '' ? $code : $id;

        if (count($byCode[$code] ?? []) > 1) {
            $issues->add(self::SEVERITY_ERROR, 'duplicate_code', 'duplicate_code:'.$id, sprintf('%s appears %d times in this draft. A curriculum can place a course only once; resolve the repeated rows.', $label, count($byCode[$code])), $id, 'code');
        }

        if ($lec + $lab !== $tu) {
            $issues->add(self::SEVERITY_ERROR, 'total_units_mismatch', 'total_units_mismatch:'.$id, sprintf('%s: lecture %d + laboratory %d = %d, but total units are %d.', $label, $lec, $lab, $lec + $lab, $tu), $id, 'total_units');
        } elseif ($tu === 0) {
            $issues->add(self::SEVERITY_REVIEW, 'zero_credits', 'zero_credits:'.$id, sprintf('%s has zero credits. Confirm this is intended.', $label), $id, 'total_units');
        }

        $flags = array_values(array_unique($source['flags'] ?? []));
        if ($flags !== []) {
            $issues->add(self::SEVERITY_REVIEW, 'extraction_flag', 'extraction_flag:'.$id, sprintf('%s was extracted with uncertainty (%s). Compare it with the source and confirm the reviewed values.', $label, implode(', ', $flags)), $id);
        }

        $changes = $this->sourceChanges($row, $code, $title);
        if ($changes !== []) {
            $issues->add(self::SEVERITY_REVIEW, 'source_correction', 'source_correction:'.$id, sprintf('%s differs from the source document (%s). Record why the corrected value is authoritative.', $label, implode('; ', $changes)), $id);
        }

        $match = CurriculumImportSettings::classify($code, $rules);
        $category = $row['category'] ?? $match['category'] ?? null;
        if ($category === null) {
            $issues->add(self::SEVERITY_ERROR, 'unclassified', 'unclassified:'.$id, sprintf('%s matches no classification rule. Choose Major or Minor.', $label), $id, 'category');
        } elseif ($match !== null && $category !== $match['category']) {
            $issues->add(self::SEVERITY_REVIEW, 'category_override', 'category_override:'.$id, sprintf('%s is set to %s, overriding the %s rule "%s" (%s).', $label, ucfirst($category), $match['match_kind'], $match['match_value'], ucfirst($match['category'])), $id, 'category');
        }

        $catalogDecision = $this->catalogDecision($id, $label, $code, $title, $lec, $lab, $tu, $category, $catalog->get($code), $issues);

        $sourceTitle = (string) ($source['title'] ?? '');
        $describedHours = preg_match('/\(\s*(\d{2,5})\s*(?:hours?|hrs?)\s*\)/i', $sourceTitle.' '.$title, $hours) ? (int) $hours[1] : null;
        $basis = $row['scheduling_basis'] ?? null;
        if ($basis === null && $describedHours !== null) {
            $issues->add(self::SEVERITY_ERROR, 'scheduling_basis_required', 'scheduling_basis_required:'.$id, sprintf('%s describes %d total hours. Choose whether it is scheduled weekly from its credits or recorded as a total-hours placement; weekly hours are not inferred.', $label, $describedHours), $id, 'scheduling_basis');
        }
        $basis ??= 'weekly';
        $totalHours = $basis === 'total_hours' ? ($row['total_hours'] ?? $describedHours) : null;
        if ($basis === 'total_hours' && $totalHours === null) {
            $issues->add(self::SEVERITY_ERROR, 'total_hours_required', 'total_hours_required:'.$id, sprintf('%s uses a total-hours basis; enter the total hours.', $label), $id, 'total_hours');
        }
        if ($basis === 'total_hours') {
            $issues->add(self::SEVERITY_REVIEW, 'nonweekly_basis', 'nonweekly_basis:'.$id, sprintf('%s is recorded as %s total hours with no weekly minutes. The scheduler does not yet read this basis and would still treat the course by its %d credits, so do not schedule it until a supported basis exists.', $label, $totalHours ?? '?', $tu), $id, 'scheduling_basis');
        } elseif ($describedHours !== null) {
            $issues->add(self::SEVERITY_REVIEW, 'nonweekly_basis', 'nonweekly_basis:'.$id, sprintf('%s describes %d total hours but will be scheduled weekly from its %d credits. Confirm this treatment.', $label, $describedHours, $tu), $id, 'scheduling_basis');
        }

        $lectureMinutes = $basis === 'weekly' ? $lec * $lectureRate : null;
        $labMinutes = $basis === 'weekly' ? $lab * $labRate : null;
        foreach (['lecture' => $lectureMinutes, 'laboratory' => $labMinutes] as $component => $minutes) {
            if ($minutes !== null && $minutes % SchedulingPolicy::SLOT_MINUTES !== 0) {
                $issues->add(self::SEVERITY_ERROR, 'unrepresentable_minutes', 'unrepresentable_minutes:'.$id.':'.$component, sprintf('%s: %d %s minutes do not fit the %d-minute scheduling grid.', $label, $minutes, $component, SchedulingPolicy::SLOT_MINUTES), $id);
            }
        }

        return [
            'row_id' => $id,
            'code' => $code,
            'title' => $title,
            'year_level' => (int) $row['year_level'],
            'semester' => (int) $row['semester'],
            'lecture_units' => $lec,
            'lab_units' => $lab,
            'total_credits' => $lec + $lab,
            'category' => $category,
            'rule' => $match,
            'catalog' => $catalogDecision,
            'scheduling_basis' => $basis,
            'total_hours' => $totalHours,
            'lecture_minutes' => $lectureMinutes,
            'laboratory_minutes' => $labMinutes,
            'weekly_contact_minutes' => $basis === 'weekly' ? $lectureMinutes + $labMinutes : null,
        ];
    }

    /** @return list<string> */
    private function sourceChanges(array $row, string $code, string $title): array
    {
        $source = $row['source'] ?? null;
        if (! is_array($source) || $source === []) {
            return [];
        }

        $changes = [];
        if (array_key_exists('code', $source) && CurriculumImportSettings::normalizeCode($source['code']) !== $code) {
            $changes[] = sprintf('code "%s" → "%s"', (string) $source['code'], $code);
        }
        if (array_key_exists('title', $source) && trim((string) preg_replace('/\s+/u', ' ', (string) $source['title'])) !== $title) {
            $changes[] = sprintf('title "%s" → "%s"', (string) $source['title'], $title);
        }
        foreach (['lec' => 'lecture_units', 'lab' => 'lab_units', 'tu' => 'total_units'] as $sourceField => $field) {
            if (! array_key_exists($sourceField, $source)) {
                continue;
            }
            $original = $source[$sourceField];
            if ($original === null || (float) $original !== (float) $row[$field]) {
                $changes[] = sprintf('%s %s → %d', strtoupper($sourceField),$original === null ? 'blank' : (string) $original, (int) $row[$field]);
            }
        }

        return $changes;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Course>|null  $matches
     * @return array{action: string, course_id: int|null, course: array<string, mixed>|null}
     */
    private function catalogDecision(string $id, string $label, string $code, string $title, int $lec, int $lab, int $tu, ?string $category, $matches, IssueList $issues): array
    {
        if ($matches === null || $matches->isEmpty()) {
            return ['action' => 'create', 'course_id' => null, 'course' => null];
        }

        $live = $matches->first(fn (Course $course): bool => ! $course->trashed());
        if ($live === null) {
            $issues->add(self::SEVERITY_ERROR, 'catalog_archived', 'catalog_archived:'.$id, sprintf('%s matches an archived course in your department. Ask the VPAA to restore it from Archives, or use a different code.', $label), $id, 'code');

            return ['action' => 'blocked', 'course_id' => (int) $matches->first()->id, 'course' => null];
        }

        $course = [
            'id' => (int) $live->id,
            'code' => $live->course_code,
            'title' => $live->course_name,
            'lecture_units' => (int) $live->lecture_hours,
            'lab_units' => (int) $live->lab_hours,
            'total_units' => (int) $live->units,
            'category' => $live->course_category,
            'status' => $live->status,
        ];

        $blocked = false;
        if ($category !== null && $live->course_category !== $category) {
            $blocked = true;
            $issues->add(self::SEVERITY_ERROR, 'catalog_category_mismatch', 'catalog_category_mismatch:'.$id, sprintf('%s already exists as a %s course. Import does not reclassify existing courses; match its category or use a different code.', $label, ucfirst((string) $live->course_category)), $id, 'category');
        }
        if ((int) $live->lecture_hours !== $lec || (int) $live->lab_hours !== $lab || (int) $live->units !== $tu) {
            $blocked = true;
            $issues->add(self::SEVERITY_ERROR, 'catalog_credit_mismatch', 'catalog_credit_mismatch:'.$id, sprintf('%s already exists with %d/%d/%d credits; the draft has %d/%d/%d. Import does not change existing course credits.', $label, $live->lecture_hours, $live->lab_hours, $live->units, $lec, $lab, $tu), $id, 'total_units');
        }
        if (mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $live->course_name))) !== mb_strtolower($title)) {
            $issues->add(self::SEVERITY_REVIEW, 'catalog_title_mismatch', 'catalog_title_mismatch:'.$id, sprintf('%s already exists as "%s". The existing course will be reused unchanged; the draft title "%s" stays in the import record only.', $label, $live->course_name, $title), $id, 'title');
        }
        if ($live->status !== 'active') {
            $issues->add(self::SEVERITY_REVIEW, 'catalog_inactive', 'catalog_inactive:'.$id, sprintf('%s matches an inactive course. It will be reused as-is.', $label), $id, 'code');
        }

        return ['action' => $blocked ? 'blocked' : 'reuse', 'course_id' => (int) $live->id, 'course' => $course];
    }

    /**
     * @param  list<array<string, mixed>>  $declared
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: array<string, mixed>}
     */
    private function validateGroups(array $declared, array $rows, IssueList $issues): array
    {
        $groups = [];
        foreach ($declared as $group) {
            $key = $group['year_level'].'-'.$group['semester'];
            if (isset($groups[$key])) {
                $issues->add(self::SEVERITY_ERROR, 'group_duplicate', 'group_duplicate:'.$key, sprintf('Year %d, %s is declared more than once.', $group['year_level'], self::TERM_LABELS[$group['semester']]));

                continue;
            }
            $groups[$key] = [
                'year_level' => (int) $group['year_level'],
                'semester' => (int) $group['semester'],
                'reported' => isset($group['reported']) && is_array($group['reported']) && array_filter($group['reported'], fn ($value): bool => $value !== null) !== []
                    ? array_map(fn ($value) => $value === null ? null : (float) $value + 0, $group['reported'] + ['lec' => null, 'lab' => null, 'tu' => null])
                    : null,
                'calculated' => ['lec' => 0, 'lab' => 0, 'tu' => 0],
                'row_count' => 0,
                'declared' => true,
            ];
        }

        foreach ($rows as $row) {
            $key = $row['year_level'].'-'.$row['semester'];
            $groups[$key] ??= [
                'year_level' => (int) $row['year_level'],
                'semester' => (int) $row['semester'],
                'reported' => null,
                'calculated' => ['lec' => 0, 'lab' => 0, 'tu' => 0],
                'row_count' => 0,
                'declared' => false,
            ];
            $groups[$key]['calculated']['lec'] += (int) $row['lecture_units'];
            $groups[$key]['calculated']['lab'] += (int) $row['lab_units'];
            $groups[$key]['calculated']['tu'] += (int) $row['total_units'];
            $groups[$key]['row_count']++;
        }

        uasort($groups, fn (array $a, array $b): int => [$a['year_level'], $a['semester']] <=> [$b['year_level'], $b['semester']]);

        $calculated = ['lec' => 0, 'lab' => 0, 'tu' => 0];
        $reportedSum = ['lec' => 0, 'lab' => 0, 'tu' => 0];
        $anyReported = false;
        foreach ($groups as $key => &$group) {
            $name = sprintf('Year %d, %s', $group['year_level'], self::TERM_LABELS[$group['semester']]);
            foreach (['lec', 'lab', 'tu'] as $field) {
                $calculated[$field] += $group['calculated'][$field];
            }

            if ($group['row_count'] === 0) {
                $group['matches'] = null;
                $issues->add(self::SEVERITY_WARNING, 'group_empty', 'group_empty:'.$key, sprintf('%s is present in the source but has no courses; no placements are created for it.', $name));

                continue;
            }

            if ($group['reported'] === null) {
                $group['matches'] = null;
                $issues->add(self::SEVERITY_REVIEW, 'group_total_unreported', 'group_total_unreported:'.$key, sprintf('%s has no printed total to cross-check; its calculated %d/%d/%d is labelled as calculated. Confirm the group is complete.', $name, ...array_values($group['calculated'])));

                continue;
            }

            $anyReported = true;
            $differences = [];
            foreach (['lec' => 'Lec', 'lab' => 'Lab', 'tu' => 'TU'] as $field => $fieldLabel) {
                $reported = $group['reported'][$field];
                $reportedSum[$field] += (float) ($reported ?? 0);
                if ($reported === null || (float) $reported !== (float) $group['calculated'][$field]) {
                    $differences[] = sprintf('%s printed %s vs calculated %d', $fieldLabel, $reported === null ? 'blank' : (string) $reported, $group['calculated'][$field]);
                }
            }
            $group['matches'] = $differences === [];
            if ($differences !== []) {
                $issues->add(self::SEVERITY_REVIEW, 'group_total_mismatch', 'group_total_mismatch:'.$key, sprintf('%s: %s. Both values are kept; record which source value or course list is authoritative.', $name, implode(', ', $differences)));
            }
        }
        unset($group);

        return [array_values($groups), [
            'calculated' => $calculated,
            'reported_sum' => $anyReported ? $reportedSum : null,
            'row_count' => count($rows),
            'populated_groups' => count(array_filter($groups, fn (array $group): bool => $group['row_count'] > 0)),
        ]];
    }
}
