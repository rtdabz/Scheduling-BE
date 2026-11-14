<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\ProgramRoomAssignmentTest;

/**
 * Reruns {@see ProgramRoomAssignmentTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorProgramRoomAssignmentTest extends ProgramRoomAssignmentTest
{
    protected string $officeRole = 'director';
}
