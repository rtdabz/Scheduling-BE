<?php

/**
 * Real MySQL/MariaDB verification of the department workflow lock in DepartmentScheduleController.
 *
 * Runs ONLY against an isolated scratch database (LOCKCHECK_DATABASE, default wicars_lock_verify, MySQL
 * root@127.0.0.1); refuses to run if the connection points anywhere else. Not part of the PHPUnit suite:
 * SQLite ignores FOR UPDATE, so this drives the real controller from separate PHP processes while this
 * process holds the department row lock, and checks the serial outcome. Each scenario builds its own
 * department, so scenarios never share state. Set LOCKCHECK_TRACE=1 to include worker SQL traces.
 *
 *   php tests/Concurrency/department_workflow_lock_check.php setup                 # create + migrate the scratch database
 *   php tests/Concurrency/department_workflow_lock_check.php scenario <name>       # run one scenario (orchestrator; spawns worker processes)
 *   php tests/Concurrency/department_workflow_lock_check.php all                   # run every scenario
 *   php tests/Concurrency/department_workflow_lock_check.php act <json>            # worker: run one controller action, print JSON result
 */

use App\Http\Controllers\Scheduling\DepartmentScheduleController;
use App\Models\Course;
use App\Models\Departments;
use App\Models\Program;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSubmission;
use App\Models\SchedulingAuditLog;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

define('BACKEND', dirname(__DIR__, 2));
define('SCRATCH_DB', getenv('LOCKCHECK_DATABASE') ?: 'wicars_lock_verify');

// Only a dedicated scratch database: setup runs migrate:fresh against it.
if (! preg_match('/_lock_verify$/', SCRATCH_DB)) {
    fwrite(STDERR, 'Refusing to run: LOCKCHECK_DATABASE must end in _lock_verify.
');
    exit(2);
}

foreach (['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => SCRATCH_DB, 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
    'NIGHTWATCH_ENABLED' => 'false', 'MAIL_MAILER' => 'array'] as $key => $value) {
    putenv("{$key}={$value}");
    $_ENV[$key] = $_SERVER[$key] = $value;
}

if (($argv[1] ?? '') === 'setup') {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.SCRATCH_DB.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
}

require BACKEND.'/vendor/autoload.php';
$app = require BACKEND.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config([
    'database.default' => 'mysql',
    'database.connections.mysql.database' => SCRATCH_DB,
    'cache.default' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
]);
DB::purge('mysql');
$current = DB::selectOne('select database() as name')->name;
if ($current !== SCRATCH_DB) {
    fwrite(STDERR, "Refusing to run: connected to '{$current}', expected '".SCRATCH_DB."'.\n");
    exit(2);
}

function out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES), "\n";
}

/** Run one workflow action through the real controller, as $userId. */
function act(array $spec): array
{
    $user = User::query()->findOrFail($spec['user']);
    $request = Request::create('/api/x', 'POST', $spec['payload'] ?? []);
    $request->setUserResolver(fn () => $user);
    auth()->setUser($user);
    app()->instance('request', $request);
    if (getenv('LOCKCHECK_TRACE')) {
        DB::listen(function ($q) {
            fwrite(STDERR, sprintf('[%.3f] (%.0fms) %s %s
', microtime(true), $q->time, substr($q->sql, 0, 140), json_encode($q->bindings)));
        });
    }
    if (isset($spec['cid_file'])) {
        file_put_contents($spec['cid_file'], (string) DB::selectOne('select connection_id() as id')->id);
    }
    $controller = app(DepartmentScheduleController::class);
    $method = $spec['method'];
    try {
        $response = $controller->{$method}((int) $spec['department'], $request);

        return ['action' => $spec['label'] ?? $method, 'status' => $response->getStatusCode(), 'body' => $response->getData(true)];
    } catch (ValidationException $exception) {
        return ['action' => $spec['label'] ?? $method, 'status' => 422, 'body' => $exception->errors()];
    }
}

function spawn(array $spec)
{
    static $worker = 0;
    $spec['cid_file'] = sys_get_temp_dir().'/worker-'.getmypid().'-'.(++$worker).'.cid';
    @unlink($spec['cid_file']);
    $process = proc_open([PHP_BINARY, __FILE__, 'act', json_encode($spec)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    return [$process, $pipes, $spec['cid_file']];
}

function collectWorker(array $handle): array
{
    [$process, $pipes, $cidFile] = $handle;
    @unlink($cidFile);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($process);
    $lines = array_values(array_filter(explode("\n", trim($stdout))));
    $decoded = json_decode((string) end($lines), true);

    if ($decoded !== null && getenv('LOCKCHECK_TRACE')) {
        $decoded['trace'] = $stderr;
    }

    return $decoded ?? ['status' => 'worker-error', 'stdout' => $stdout, 'stderr' => substr($stderr, 0, 2000)];
}

/** Wait until $count transactions are blocked; return the tables they wait on (proves they wait on the department row). */
function waitForLockWaiters(int $count, array $handles = []): array
{
    $deadline = microtime(true) + 40;
    while (microtime(true) < $deadline) {
        // MariaDB 10.4 waits on a primary-key FOR UPDATE during the optimizer's const-row read ("Statistics"),
        // before the transaction is listed as LOCK WAIT, so read the blocked statements from the process list.
        $ids = array_values(array_filter(array_map(fn ($h) => is_file($h[2]) ? (int) file_get_contents($h[2]) : 0, $handles)));
        $blocked = $ids === [] ? [] : DB::select(
            'select id, state, time, info from information_schema.processlist
             where id in ('.implode(',', $ids).") and command in ('Query', 'Execute')
               and info like '%from `departments`%for update%'",
        );
        if (count($blocked) >= $count) {
            return array_map(fn ($row) => "thread {$row->id} [{$row->state}]: {$row->info}", $blocked);
        }
        foreach ($handles as $handle) {
            if (! proc_get_status($handle[0])['running']) {
                DB::rollBack();
                throw new RuntimeException('A worker exited before blocking: '.json_encode(collectWorker($handle)));
            }
        }
        usleep(50_000);
    }
    throw new RuntimeException("Timed out waiting for {$count} lock waiters.");
}

/** A department with sections keyed A, B, ... whose single meetings have the given statuses. */
function fixture(array $meetingStatuses): array
{
    static $sequence = 0;
    $sequence++;
    $tag = substr(uniqid(), -6).$sequence;
    $department = Departments::create(['department_name' => "Lock Dept {$tag}", 'department_code' => "L{$tag}"]);
    $program = Program::create(['department_id' => $department->id, 'code' => "P{$tag}", 'name' => "Program {$tag}"]);
    $semester = Semester::query()->where('is_active', true)->first()
        ?? Semester::create(['academic_year' => '2026-2027', 'semester' => '1st', 'is_active' => true, 'is_enabled' => true]);
    $room = Rooms::create(['room_code' => "R{$tag}", 'room_type' => 'lecture', 'status' => 'available', 'department_id' => $department->id]);
    $course = Course::create([
        'course_code' => "C{$tag}", 'course_name' => 'Programming', 'lecture_hours' => 1, 'lab_hours' => 0, 'units' => 1,
        'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st',
        'department_id' => $department->id, 'status' => 'active',
    ]);
    $users = [
        'secretary' => User::factory()->create(['role' => 'secretary', 'department_id' => $department->id, 'is_active' => true]),
        'dean' => User::factory()->create(['role' => 'dean', 'department_id' => $department->id, 'is_active' => true]),
        'vpaa' => User::query()->where('role', 'vpaa')->first() ?? User::factory()->create(['role' => 'vpaa', 'department_id' => null, 'is_active' => true]),
    ];
    $sections = [];
    $meetings = [];
    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday'];
    foreach (array_keys($meetingStatuses) as $index => $key) {
        $sections[$key] = Sections::create([
            'section_name' => "S{$tag}{$key}", 'year_level' => '1', 'semester' => '1st', 'department_id' => $department->id,
            'program_id' => $program->id, 'semester_id' => $semester->id, 'status' => 'active',
        ])->id;
        $meetings[$key] = Schedule::create([
            'semester_id' => $semester->id, 'section_id' => $sections[$key], 'course_id' => $course->id, 'room_id' => $room->id,
            'department_id' => $department->id, 'day' => $days[$index], 'start_time' => '08:00', 'end_time' => '09:00',
            'mode' => 'on-site', 'status' => $meetingStatuses[$key],
        ])->id;
    }

    return ['department' => $department->id, 'semester' => $semester->id, 'sections' => $sections, 'meetings' => $meetings,
        'users' => array_map(fn (User $user) => $user->id, $users)];
}

function submission(array $f, array $included, string $status, array $withdrawn = []): int
{
    $submission = ScheduleSubmission::create([
        'department_id' => $f['department'], 'semester_id' => $f['semester'],
        'revision_number' => ((int) ScheduleSubmission::query()->where('department_id', $f['department'])->max('revision_number')) + 1,
        'status' => $status, 'submitted_at' => now(),
    ]);
    foreach ($included as $key) {
        $submission->sections()->attach($f['sections'][$key], ['state' => 'included']);
    }
    foreach ($withdrawn as $key) {
        $submission->sections()->attach($f['sections'][$key], ['state' => 'withdrawn']);
    }

    return $submission->id;
}

function spec(array $f, string $role, string $method, array $payload = [], ?string $label = null): array
{
    return ['user' => $f['users'][$role], 'department' => $f['department'], 'method' => $method, 'payload' => $payload, 'label' => $label ?? "{$role}:{$method}"];
}

function state(array $f): array
{
    return [
        'meetings' => array_map(fn ($id) => Schedule::query()->whereKey($id)->value('status'), $f['meetings']),
        'submissions' => ScheduleSubmission::query()->where('department_id', $f['department'])->orderBy('id')->get()
            ->map(fn ($s) => [
                'id' => $s->id, 'rev' => $s->revision_number, 'status' => $s->status, 'dean_by' => $s->dean_reviewed_by,
                'vpaa_by' => $s->vpaa_reviewed_by, 'rejection' => $s->rejection_reason,
                'included' => DB::table('schedule_submission_sections')->where('schedule_submission_id', $s->id)->where('state', 'included')->orderBy('section_id')->pluck('section_id')->all(),
            ])->all(),
        'audit' => SchedulingAuditLog::query()->where('department_id', $f['department'])->orderBy('id')->pluck('action')->all(),
    ];
}

/** Hold the department lock in this process, start $workers, wait until they block, run $whileHeld, then commit. */
function underHeldLock(array $f, array $workers, ?Closure $whileHeld = null, bool $staggered = false): array
{
    DB::beginTransaction();
    Departments::query()->whereKey($f['department'])->lockForUpdate()->value('id');
    $handles = [];
    $locks = [];
    foreach ($workers as $index => $worker) {
        $handles[] = spawn($worker);
        if ($staggered || $index === count($workers) - 1) {
            $locks = waitForLockWaiters($index + 1, $handles);
        }
    }
    $heldResults = $whileHeld ? $whileHeld() : [];
    DB::commit();

    return ['waited_on' => $locks, 'held' => $heldResults, 'workers' => array_map('collectWorker', $handles)];
}

function check(string $name, bool $condition, array &$failures): void
{
    echo ($condition ? '  PASS ' : '  FAIL ')."{$name}\n";
    if (! $condition) {
        $failures[] = $name;
    }
}

$scenarios = [
    // Review preflights, then waits on the lock while recall + resubmission commit. Proves the post-wait
    // reads see the committed state under REPEATABLE READ and the old revision cannot approve section A.
    'recall_resubmit_before_review' => function (array &$failures): array {
        $results = [];
        foreach (['dean' => ['submitted', 'approveByDean'], 'vpaa' => ['approved_by_dean', 'approveByVpaa'], 'dean-return' => ['submitted', 'returnByDean'], 'vpaa-return' => ['approved_by_dean', 'returnByVpaa']] as $case => [$status, $method]) {
            $f = fixture(['A' => $status, 'B' => 'revision', 'C' => $status]);
            $old = submission($f, ['A', 'C'], 'partially_withdrawn', ['B']);
            $role = str_starts_with($case, 'vpaa') ? 'vpaa' : 'dean';
            $payload = ['schedule_submission_id' => $old] + (str_contains($case, 'return') ? ['rejection_reason' => 'Revise.'] : []);
            $run = underHeldLock($f, [spec($f, $role, $method, $payload)], function () use ($f, $role) {
                $held = [act(spec($f, 'secretary', 'withdrawSubmission', ['section_ids' => [$f['sections']['A']]])), act(spec($f, 'secretary', 'submitSchedules'))];
                if ($role === 'vpaa') {
                    $new = ScheduleSubmission::query()->where('department_id', $f['department'])->max('id');
                    $held[] = act(spec($f, 'dean', 'approveByDean', ['schedule_submission_id' => $new]));
                }

                return $held;
            });
            $after = state($f);
            $worker = $run['workers'][0];
            check("{$case}: old review blocked on the department row", in_array(true, array_map(fn ($l) => str_contains($l, 'from `departments`'), $run['waited_on']), true), $failures);
            check("{$case}: held recall/resubmit succeeded", array_unique(array_column($run['held'], 'status')) === [200], $failures);
            check("{$case}: old review got 409 submission_stage_changed", $worker['status'] === 409 && ($worker['body']['error_code'] ?? null) === 'submission_stage_changed', $failures);
            check("{$case}: section A still at the new revision's stage", $after['meetings']['A'] === ($role === 'dean' ? 'submitted' : 'approved_by_dean'), $failures);
            check("{$case}: section C unchanged", $after['meetings']['C'] === $status, $failures);
            check("{$case}: old revision has no reviewer stamped", $after['submissions'][0][$role === 'dean' ? 'dean_by' : 'vpaa_by'] === null, $failures);
            $results[$case] = ['worker' => $worker, 'waited_on' => $run['waited_on'], 'after' => $after];
        }

        return $results;
    },

    // The review gets the lock first; the recall queued behind it then recalls the resulting stage.
    'review_before_recall' => function (array &$failures): array {
        $f = fixture(['A' => 'submitted', 'C' => 'submitted']);
        $sub = submission($f, ['A', 'C'], 'pending_dean');
        $run = underHeldLock($f, [
            spec($f, 'dean', 'approveByDean', ['schedule_submission_id' => $sub]),
            spec($f, 'secretary', 'withdrawSubmission', ['section_ids' => [$f['sections']['A']]]),
        ], null, staggered: true);
        [$review, $recall] = $run['workers'];
        $after = state($f);
        $reviewFirst = $review['status'] === 200 && $recall['status'] === 200 && ($recall['body']['withdrawal_stage'] ?? null) === 'vpaa_review'
            && $after['meetings'] === ['A' => 'revision', 'C' => 'approved_by_dean'];
        $recallFirst = $recall['status'] === 200 && $review['status'] === 409 && $after['meetings'] === ['A' => 'revision', 'C' => 'submitted'];
        check('review_before_recall: outcome is one valid serial order', $reviewFirst || $recallFirst, $failures);
        check('review_before_recall: ran in queue order (review, then recall)', $reviewFirst, $failures);

        return ['workers' => $run['workers'], 'waited_on' => $run['waited_on'], 'after' => $after];
    },

    'competing_decisions' => function (array &$failures): array {
        $f = fixture(['A' => 'submitted']);
        $sub = submission($f, ['A'], 'pending_dean');
        $run = underHeldLock($f, [
            spec($f, 'dean', 'approveByDean', ['schedule_submission_id' => $sub]),
            spec($f, 'vpaa', 'returnByDean', ['schedule_submission_id' => $sub, 'rejection_reason' => 'Competing return.'], 'vpaa:returnByDean'),
            spec($f, 'dean', 'approveByDean', ['schedule_submission_id' => $sub], 'dean:approveByDean(duplicate)'),
        ]);
        $statuses = array_column($run['workers'], 'status');
        sort($statuses);
        $after = state($f);
        check('competing_decisions: exactly one success, the rest 409', $statuses === [200, 409, 409], $failures);
        check('competing_decisions: one dean-stage audit entry', count(array_filter($after['audit'], fn ($a) => str_ends_with($a, '_by_dean'))) === 1, $failures);
        $winner = array_values(array_filter($run['workers'], fn ($w) => $w['status'] === 200))[0]['action'] ?? '';
        check('competing_decisions: meeting matches the winner', $after['meetings']['A'] === (str_contains($winner, 'return') ? 'rejected_by_dean' : 'approved_by_dean'), $failures);

        return ['workers' => $run['workers'], 'after' => $after];
    },

    'duplicate_submission' => function (array &$failures): array {
        $f = fixture(['A' => 'completed', 'C' => 'completed']);
        $run = underHeldLock($f, [spec($f, 'secretary', 'submitSchedules', [], 'submit#1'), spec($f, 'secretary', 'submitSchedules', [], 'submit#2')]);
        $statuses = array_column($run['workers'], 'status');
        sort($statuses);
        $after = state($f);
        check('duplicate_submission: one 200 and one 422', $statuses === [200, 422], $failures);
        check('duplicate_submission: exactly one submission with both sections', count($after['submissions']) === 1 && count($after['submissions'][0]['included']) === 2, $failures);
        check('duplicate_submission: one schedule_submitted audit', $after['audit'] === ['schedule_submitted'], $failures);

        return ['workers' => $run['workers'], 'after' => $after];
    },

    // A recall resolved A -> S1, then waits while A is recalled and resubmitted (S2). It must not recall S2.
    'stale_recall' => function (array &$failures): array {
        $f = fixture(['A' => 'submitted', 'C' => 'submitted']);
        submission($f, ['A', 'C'], 'pending_dean');
        $run = underHeldLock($f, [spec($f, 'secretary', 'withdrawSubmission', ['section_ids' => [$f['sections']['A']]], 'stale recall')], fn () => [
            act(spec($f, 'secretary', 'withdrawSubmission', ['section_ids' => [$f['sections']['A']]])),
            act(spec($f, 'secretary', 'submitSchedules')),
        ]);
        $after = state($f);
        $worker = $run['workers'][0];
        check('stale_recall: stale recall got 409', $worker['status'] === 409 && ($worker['body']['error_code'] ?? null) === 'submission_stage_changed', $failures);
        check('stale_recall: A stays submitted in the new revision', $after['meetings']['A'] === 'submitted' && end($after['submissions'])['status'] === 'pending_dean', $failures);
        check('stale_recall: one recall recorded', count(array_keys($after['audit'], 'schedule_withdrawn')) === 1, $failures);

        return ['worker' => $worker, 'after' => $after];
    },
];

$mode = $argv[1] ?? 'all';
if ($mode === 'act') {
    out(act(json_decode($argv[2], true)));
    exit(0);
}
if ($mode === 'setup') {
    Artisan::call('migrate:fresh', ['--force' => true, '--database' => 'mysql']);
    Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\RoleSeeder', '--force' => true]);
    echo 'Migrated ', SCRATCH_DB, "\n";
    exit(0);
}

$failures = [];
$selected = $mode === 'scenario' ? [$argv[2] => $scenarios[$argv[2]]] : $scenarios;
$evidence = [];
foreach ($selected as $name => $scenario) {
    echo "== {$name}\n";
    $evidence[$name] = $scenario($failures);
}
file_put_contents(sys_get_temp_dir().'/lockcheck-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo $failures === [] ? "ALL CHECKS PASSED\n" : 'FAILED: '.implode('; ', $failures)."\n";
exit($failures === [] ? 0 : 1);
