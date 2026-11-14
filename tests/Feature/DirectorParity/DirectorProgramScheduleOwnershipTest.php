<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\ProgramScheduleOwnershipTest;

/**
 * Reruns {@see ProgramScheduleOwnershipTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorProgramScheduleOwnershipTest extends ProgramScheduleOwnershipTest
{
    protected string $officeRole = 'director';
}
