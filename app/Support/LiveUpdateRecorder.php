<?php

namespace App\Support;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\Departments;
use App\Models\Designation;
use App\Models\Faculty;
use App\Models\FacultyAvailability;
use App\Models\InstitutionSetting;
use App\Models\Program;
use App\Models\RoomRequest;
use App\Models\RoomRequestWindow;
use App\Models\Rooms;
use App\Models\Schedule;
use App\Models\ScheduleSplit;
use App\Models\ScheduleSubmission;
use App\Models\ScheduleSubmissionSection;
use App\Models\Sections;
use App\Models\Semester;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;

final class LiveUpdateRecorder
{
    private const MODEL_TOPICS = [
        Schedule::class => ['schedules', 'assignments', 'faculty', 'rooms'],
        ScheduleSplit::class => ['schedules'],
        ScheduleSubmission::class => ['approvals', 'schedules'],
        ScheduleSubmissionSection::class => ['approvals', 'schedules'],
        Sections::class => ['sections', 'schedules'],
        Rooms::class => ['rooms'],
        RoomRequest::class => ['rooms'],
        RoomRequestWindow::class => ['rooms'],
        Faculty::class => ['faculty', 'assignments'],
        FacultyAvailability::class => ['faculty'],
        Designation::class => ['faculty'],
        Course::class => ['courses', 'curriculum', 'assignments'],
        Curriculum::class => ['curriculum'],
        Departments::class => ['departments'],
        Program::class => ['departments'],
        User::class => ['users'],
        InstitutionSetting::class => ['settings'],
        Semester::class => ['settings'],
    ];

    private const QUIET_USER_COLUMNS = ['last_login_at', 'remember_token', 'updated_at'];

    private const SOCKET_ID_PATTERN = '/^\d+\.\d+$/';

    public static function register(Application $app): void
    {
        foreach (self::MODEL_TOPICS as $model => $topics) {
            $touch = static function () use ($app, $topics): void {
                $app->make(LiveUpdates::class)->touch(...$topics);
            };

            $model::created($touch);
            $model::deleted($touch);
            $model::updated(static function (Model $record) use ($touch): void {
                if (! self::onlyQuietColumnsChanged($record)) {
                    $touch();
                }
            });
        }

        SystemNotification::created(static function (SystemNotification $notification) use ($app): void {
            $app->make(LiveUpdates::class)->notifyUser((int) $notification->user_id);
        });

        $app->terminating(static function () use ($app): void {
            if (! $app->resolved(LiveUpdates::class)) {
                return;
            }

            $socketId = $app->runningInConsole() ? null : $app->make('request')->header('X-Socket-ID');
            $socketId = is_string($socketId) && preg_match(self::SOCKET_ID_PATTERN, $socketId) ? $socketId : null;

            $app->make(LiveUpdates::class)->flush($socketId);
        });

        $flushJob = static function () use ($app): void {
            if ($app->resolved(LiveUpdates::class)) {
                $app->make(LiveUpdates::class)->flush();
            }
        };
        Event::listen(JobProcessed::class, $flushJob);
        Event::listen(JobFailed::class, $flushJob);
    }

    private static function onlyQuietColumnsChanged(Model $record): bool
    {
        return $record instanceof User
            && array_diff(array_keys($record->getChanges()), self::QUIET_USER_COLUMNS) === [];
    }
}
