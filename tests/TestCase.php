<?php

namespace Tests;

use App\Services\Scheduling\SchedulingPolicy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * SchedulingPolicy memoizes course categories, field course codes and the
     * operating-hours window in static properties. Those survive between tests in
     * the same PHPUnit process, so a course id reused by a later test inherited the
     * earlier test's categories and the suite became order-dependent. Individual
     * tests used to clear these by hand; clearing them here makes every test
     * independent by default.
     */
    protected function setUp(): void
    {
        parent::setUp();

        SchedulingPolicy::clearCourseCategoryCache();
        SchedulingPolicy::clearFieldCourseCache();
        SchedulingPolicy::clearTimeCache();
    }
}
