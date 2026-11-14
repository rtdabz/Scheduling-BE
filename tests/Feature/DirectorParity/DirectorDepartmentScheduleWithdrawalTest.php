<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\DepartmentScheduleWithdrawalTest;

/**
 * Reruns {@see DepartmentScheduleWithdrawalTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorDepartmentScheduleWithdrawalTest extends DepartmentScheduleWithdrawalTest
{
    protected string $officeRole = 'director';
}
