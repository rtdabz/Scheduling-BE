<?php

namespace App\Services\Curriculum;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\CurriculumImport;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use App\Support\ApiCache;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * All-or-nothing import of a reviewed draft into a new, deactivated curriculum.
 */
final class CurriculumImportService
{
    public const SNAPSHOT_VERSION = 1;

    public function __construct(
        private readonly CurriculumImportValidator $validator,
        private readonly CurriculumImportSettings $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  shape-validated input
     * @return array{status: int, body: array<string, mixed>}
     *
     * @throws CurriculumImportConflict
     * @throws CurriculumImportInvalid
     */
    public function commit(array $payload, Departments $department, User $user): array
    {
        $payloadHash = self::payloadHash($payload);

        if (($replay = $this->replay($department, (string) $payload['request_key'], $payloadHash)) !== null) {
            return $replay;
        }

        try {
            $summary = DB::transaction(fn (): array => $this->write($payload, $payloadHash, $department, $user));
        } catch (QueryException $exception) {
            // A concurrent retry with the same key won the unique index; answer with its recorded outcome.
            if (($replay = $this->replay($department, (string) $payload['request_key'], $payloadHash)) !== null) {
                return $replay;
            }
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new CurriculumImportConflict('Another import was saved at the same moment. Validate the draft again and import.');
            }

            throw $exception;
        }

        Course::syncPlacementFromCurricula(array_column($summary['rows'], 'course_id'));
        ApiCache::forgetGroups(['curriculum.index', 'courses.index', 'initial.data']);

        return ['status' => 201, 'body' => $summary + ['replayed' => false]];
    }

    /** @param  array<string, mixed>  $payload */
    public static function payloadHash(array $payload): string
    {
        unset($payload['request_key'], $payload['fingerprint'], $payload['expected_settings_revision']);

        return CurriculumImportValidator::fingerprint($payload, 0, []);
    }

    /** @return array{status: int, body: array<string, mixed>}|null */
    private function replay(Departments $department, string $requestKey, string $payloadHash): ?array
    {
        $existing = CurriculumImport::query()
            ->where('department_id', $department->id)
            ->where('request_key', $requestKey)
            ->first();

        if ($existing === null) {
            return null;
        }
        if (! hash_equals($existing->payload_hash, $payloadHash)) {
            throw new CurriculumImportConflict('This import request key was already used for a different draft. Start a new import attempt.');
        }

        return ['status' => 200, 'body' => $existing->summary + ['replayed' => true]];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function write(array $payload, string $payloadHash, Departments $department, User $user): array
    {
        $locked = Departments::query()->lockForUpdate()->findOrFail($department->id);
        if ((int) $locked->curriculum_import_settings_revision !== (int) $payload['expected_settings_revision']) {
            throw new CurriculumImportConflict('Import settings changed after this draft was validated. Validate the draft again before importing.');
        }

        $validation = $this->validator->validate($payload, $locked, $user);
        if (! $validation['valid']) {
            throw new CurriculumImportInvalid($validation);
        }
        if (! hash_equals($validation['fingerprint'], (string) $payload['fingerprint'])) {
            throw new CurriculumImportConflict('The draft or the course catalog changed after validation. Validate the draft again before importing.');
        }

        $metadata = $validation['metadata'];
        $metadata['code'] = CurriculumCodeGenerator::next(CurriculumCodeGenerator::base(
            $locked,
            $metadata['program_id'] === null ? null : Program::query()->find($metadata['program_id']),
            $metadata['effective_school_year'],
        ));
        $curriculum = Curriculum::create([
            'name' => $metadata['name'],
            'code' => $metadata['code'],
            'cmo_number' => $metadata['cmo_number'],
            'cmo_series' => $metadata['cmo_series'],
            'department_id' => $locked->id,
            'program_id' => $metadata['program_id'],
            'effective_school_year' => $metadata['effective_school_year'],
            'status' => 'deactivated',
            'description' => $metadata['description'],
        ]);

        $courseIds = [];
        $created = 0;
        $reused = 0;
        foreach ($validation['rows'] as $row) {
            if ($row['catalog']['action'] === 'reuse') {
                $courseIds[$row['row_id']] = (int) $row['catalog']['course_id'];
                $reused++;

                continue;
            }

            $course = Course::create([
                'course_code' => $row['code'],
                'course_name' => $row['title'],
                // Legacy fields keep holding credits, so existing scheduling derives durations exactly as for manual encoding.
                'lecture_hours' => $row['lecture_units'],
                'lab_hours' => $row['lab_units'],
                'units' => $row['total_credits'],
                'course_category' => $row['category'],
                'room_type_required' => $row['lab_units'] > 0 ? 'laboratory' : 'lecture',
                'year_level' => (string) $row['year_level'],
                'semester' => match ($row['semester']) {
                    1 => '1st',
                    2 => '2nd',
                    default => 'summer',
                },
                'department_id' => $locked->id,
                'status' => 'active',
            ]);
            $courseIds[$row['row_id']] = (int) $course->id;
            $created++;
        }

        $draftRows = collect($payload['rows'])->keyBy('row_id');
        $attach = [];
        foreach ($validation['rows'] as $row) {
            $prerequisites = trim((string) ($draftRows[$row['row_id']]['prerequisites'] ?? ''));

            $attach[$courseIds[$row['row_id']]] = [
                'year_level' => $row['year_level'],
                'semester' => $row['semester'],
                'lecture_units' => $row['lecture_units'],
                'lab_units' => $row['lab_units'],
                'lecture_minutes' => $row['lecture_minutes'],
                'laboratory_minutes' => $row['laboratory_minutes'],
                'conversion_snapshot' => json_encode([
                    'version' => self::SNAPSHOT_VERSION,
                    'basis' => $row['scheduling_basis'],
                    'total_hours' => $row['total_hours'],
                    'lecture_minutes_per_unit' => $validation['settings']['lecture_minutes_per_unit'],
                    'laboratory_minutes_per_unit' => $validation['settings']['laboratory_minutes_per_unit'],
                    'settings_revision' => $validation['settings_revision'],
                    'offering_department_id' => (int) $locked->id,
                ]),
                'prerequisites_text' => $prerequisites === '' ? null : $prerequisites,
            ];
        }
        $curriculum->courses()->attach($attach);
        SchedulingPolicy::clearFieldCourseCache();

        $mapping = array_map(fn (array $row): array => [
            'row_id' => $row['row_id'],
            'course_id' => $courseIds[$row['row_id']],
            'code' => $row['code'],
            'action' => $row['catalog']['action'] === 'reuse' ? 'reused' : 'created',
        ], $validation['rows']);

        $summary = [
            'curriculum' => [
                'id' => (int) $curriculum->id,
                'name' => $curriculum->name,
                'code' => $curriculum->code,
                'cmo_number' => $curriculum->cmo_number,
                'cmo_series' => $curriculum->cmo_series,
                'department_id' => (int) $curriculum->department_id,
                'program_id' => $curriculum->program_id === null ? null : (int) $curriculum->program_id,
                'effective_school_year' => $curriculum->effective_school_year,
                'status' => $curriculum->status,
            ],
            'created_count' => $created,
            'reused_count' => $reused,
            'rows' => $mapping,
            'groups' => $validation['groups'],
            'totals' => $validation['totals'],
            'acknowledged' => $validation['acknowledged'],
            'warnings' => array_values(array_filter($validation['issues'], fn (array $issue): bool => $issue['severity'] === CurriculumImportValidator::SEVERITY_WARNING)),
        ];

        $import = CurriculumImport::create([
            'curriculum_id' => $curriculum->id,
            'department_id' => $locked->id,
            'user_id' => $user->id,
            'request_key' => (string) $payload['request_key'],
            'payload_hash' => $payloadHash,
            'source_format' => $payload['source']['format'],
            'source_filename' => $payload['source']['filename'],
            'source_sha256' => $payload['source']['sha256'],
            'extractor_version' => $payload['source']['extractor_version'],
            'record' => [
                // The server receives extracted JSON and a client-declared hash, not the file bytes.
                'source' => $payload['source'] + ['hash_declared_by_client' => true],
                'metadata' => $metadata,
                'draft_rows' => $payload['rows'],
                'resolved_rows' => $validation['rows'],
                'groups' => $validation['groups'],
                'totals' => $validation['totals'],
                'acknowledged' => $validation['acknowledged'],
                'settings' => $validation['settings'] + [
                    'revision' => $validation['settings_revision'],
                    'rules' => $this->settings->rules((int) $locked->id)
                        ->map(fn ($rule): array => $rule->only(['id', 'match_kind', 'match_value', 'category', 'enabled']))->values()->all(),
                ],
            ],
            'summary' => $summary,
        ]);

        $summary['import_id'] = (int) $import->id;
        $import->update(['summary' => $summary]);

        return $summary;
    }
}
