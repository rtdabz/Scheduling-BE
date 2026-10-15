<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Program;
use App\Models\User;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CourseValidationParityTest extends TestCase
{
    use RefreshDatabase;

    private Curriculum $curriculum;

    protected function setUp(): void
    {
        parent::setUp();
        $department = Departments::create(['department_name' => 'College A', 'department_code' => 'A']);
        Program::create(['department_id' => $department->id, 'code' => 'A1']);
        $this->curriculum = Curriculum::create(['department_id' => $department->id, 'code' => 'CUR-A', 'name' => 'Curriculum A', 'effective_school_year' => '2026-2027', 'status' => 'active']);
        $this->actingAs(User::factory()->create(['role' => 'secretary', 'department_id' => $department->id]));
    }

    #[DataProvider('invalidFields')]
    public function test_single_update_and_batch_reject_the_same_invalid_fields(string $field, mixed $value): void
    {
        $course = Course::create([...$this->payload(), 'department_id' => $this->curriculum->department_id, 'course_code' => 'EXISTING']);
        $original = $course->fresh()->getAttributes();
        $this->postJson('/api/courses', [...$this->payload(), $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->putJson('/api/courses/'.$course->id, [$field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->postJson($this->batchUrl(), ['courses' => [$this->row(), [...$this->row(), 'row_id' => 'invalid', $field => $value]]])
            ->assertUnprocessable()->assertJsonValidationErrors('courses.1.'.$field);
        $this->assertDatabaseCount('courses', 1);
        $this->assertDatabaseCount('curriculum_course', 0);
        $this->assertSame($original, $course->fresh()->getAttributes());
    }

    public static function invalidFields(): array
    {
        return [
            'lecture limit' => ['lecture_hours', 100],
            'lab limit' => ['lab_hours', 100],
            'negative units' => ['units', -1],
            'fractional lecture' => ['lecture_hours', 1.5],
            'long code' => ['course_code', str_repeat('A', 256)],
            'long name' => ['course_name', str_repeat('A', 256)],
            'blank code' => ['course_code', '   '],
            'malformed code' => ['course_code', ['A']],
        ];
    }

    public function test_boundary_values_normalization_and_numeric_batch_placement_are_preserved(): void
    {
        $values = ['lecture_hours' => SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LECTURE_SLOTS_PER_UNIT), 'lab_hours' => SchedulingPolicy::maxUnitsPerComponent(SchedulingPolicy::LABORATORY_SLOTS_PER_UNIT), 'course_name' => str_repeat('N', 255)];
        $this->postJson('/api/courses', [...$this->payload(), ...$values, 'course_code' => '  single   101  '])
            ->assertCreated()->assertJsonPath('course_code', 'SINGLE 101');
        $response = $this->postJson($this->batchUrl(), ['courses' => [[...$this->row(), ...$values, 'course_code' => '  batch   101  ', 'semester' => 3]]])
            ->assertOk()->assertJsonPath('results.0.status', 'success')->assertJsonPath('results.0.course.course_code', 'BATCH 101');
        $course = Course::findOrFail($response->json('results.0.course.id'));
        $this->assertSame('summer', $course->semester);
        $this->assertDatabaseHas('curriculum_course', ['curriculum_id' => $this->curriculum->id, 'course_id' => $course->id, 'semester' => 3]);
    }

    public function test_batch_keeps_per_row_success_and_failure_results(): void
    {
        $this->postJson($this->batchUrl(), ['courses' => [$this->row()]])->assertOk();
        $this->postJson($this->batchUrl(), ['courses' => [
            [...$this->row(), 'semester' => 2],
            [...$this->row(), 'row_id' => 'new', 'course_code' => 'NEW'],
        ]])->assertOk()->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.message', 'Course code is already used in another semester of this curriculum.')
            ->assertJsonPath('results.1.status', 'success');
        $this->assertDatabaseCount('courses', 2);
        $this->assertDatabaseCount('curriculum_course', 2);
    }

    public function test_archived_duplicate_returns_restoration_guidance_and_keeps_other_rows(): void
    {
        $archived = Course::create([...$this->payload(), 'department_id' => $this->curriculum->department_id]);
        $archived->delete();
        $single = $this->postJson('/api/courses', $this->payload())->assertUnprocessable();
        Exceptions::fake();
        $response = $this->postJson($this->batchUrl(), ['courses' => [
            $this->row(), [...$this->row(), 'row_id' => 'new', 'course_code' => 'NEW'],
        ]])->assertOk()->assertJsonCount(2, 'results')
            ->assertJsonPath('results.0.row_id', 'row-1')
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.message', $single->json('errors.course_code.0'))
            ->assertJsonPath('results.1.status', 'success');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSoftDeleted($archived);
        $this->assertDatabaseCount('courses', 2);
        $this->assertDatabaseCount('curriculum_course', 1);
        Exceptions::assertNothingReported();
    }

    public function test_unexpected_row_failure_is_reported_hidden_and_rolled_back(): void
    {
        Exceptions::fake();
        $failure = new \RuntimeException('SQLSTATE internal insert into courses diagnostic');
        Event::listen('eloquent.created: '.Course::class, function (Course $course) use ($failure): void {
            if ($course->course_code === 'FAIL') {
                throw $failure;
            }
        });
        $response = $this->postJson($this->batchUrl(), ['courses' => [
            [...$this->row(), 'row_id' => 'before', 'course_code' => 'BEFORE'],
            [...$this->row(), 'row_id' => 'failed', 'course_code' => 'FAIL'],
            [...$this->row(), 'row_id' => 'after', 'course_code' => 'AFTER'],
        ]])->assertOk()->assertJsonCount(3, 'results')
            ->assertJsonPath('results.0.status', 'success')
            ->assertJsonPath('results.1.row_id', 'failed')
            ->assertJsonPath('results.1.status', 'error')
            ->assertJsonPath('results.1.message', 'Unable to save this course. Please retry this row. If the problem continues, contact your administrator.')
            ->assertJsonPath('results.2.status', 'success');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('insert into', $response->getContent());
        $this->assertDatabaseMissing('courses', ['course_code' => 'FAIL']);
        $this->assertDatabaseCount('courses', 2);
        $this->assertDatabaseCount('curriculum_course', 2);
        Exceptions::assertReported(fn (\RuntimeException $reported) => $reported === $failure);
    }

    private function batchUrl(): string
    {
        return '/api/curriculum/'.$this->curriculum->id.'/courses/batch-create';
    }

    private function row(): array
    {
        return [...$this->payload(), 'row_id' => 'row-1', 'year_level' => 1, 'semester' => 1];
    }

    private function payload(): array
    {
        return ['course_code' => 'A101', 'course_name' => 'Course A', 'lecture_hours' => 3, 'lab_hours' => 0, 'units' => 3, 'course_category' => 'major', 'room_type_required' => 'lecture', 'year_level' => '1', 'semester' => '1st'];
    }
}
