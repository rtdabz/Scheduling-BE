<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\SchedulingSettingsControllerTest;

/**
 * Reruns {@see SchedulingSettingsControllerTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorSchedulingSettingsControllerTest extends SchedulingSettingsControllerTest
{
    protected string $officeRole = 'director';
}
