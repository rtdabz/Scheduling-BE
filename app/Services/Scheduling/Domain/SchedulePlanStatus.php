<?php

declare(strict_types=1);

namespace App\Services\Scheduling\Domain;

enum SchedulePlanStatus: string
{
    case ConfigurationValid = 'configuration_valid';
    case TimetableFeasible = 'timetable_feasible';
    case RoomAssignmentUnresolved = 'room_assignment_unresolved';
    case RoomAssignmentComplete = 'room_assignment_complete';
    case InstructorFeasible = 'instructor_feasible';
    case InstructorAssignmentComplete = 'instructor_assignment_complete';
    case PersistenceValid = 'persistence_valid';
    case Committed = 'committed';
    case Invalid = 'invalid';
}
