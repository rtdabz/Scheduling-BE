<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\CurriculumImportTest;

/**
 * Reruns {@see CurriculumImportTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorCurriculumImportTest extends CurriculumImportTest
{
    protected string $officeRole = 'director';
}
