<?php

namespace Tests;

use Database\Seeders\RoleSeeder;
use App\Services\Scheduling\Support\SchedulingPolicy;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * SchedulingPolicy memoizes field course codes and the operating-hours
     * window in static properties. Those survive between tests in the same
     * PHPUnit process, so a course id reused by a later test inherited the
     * earlier test's configuration and the suite became order-dependent.
     * Individual tests used to clear these by hand; clearing them here makes
     * every test independent by default.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests exercise capability middleware directly. Keep the
        // permission catalog available without reintroducing production user
        // fixtures removed from UserSeeder.
        // Unit tests that do not boot the database have no permissions table;
        // only seed when the test schema is available.
        if (Schema::hasTable('permissions')) {
            $this->seed(RoleSeeder::class);
        }

        SchedulingPolicy::clearFieldCourseCache();
        SchedulingPolicy::clearTimeCache();
    }

    /**
     * Every capability a scheduling account can hold without being a dean or
     * the VPAA.
     *
     * Capabilities are granted per account rather than inherited from the role
     * (config/capabilities.php keeps 'secretary', 'program_head' and 'director'
     * deliberately empty), so a factory-built user holds none and is denied by
     * CapabilityMiddleware before any controller runs. Tests whose subject is
     * something else -- generation, conflicts, teaching load -- need an account
     * that is already configured for the workspace, which is what this is.
     *
     * The two approval capabilities are excluded on purpose: config restricts
     * them to dean and vpaa, so granting them here would let a secretary hold a
     * capability the application would never assign. Tests that need approval
     * pass the capability explicitly instead.
     *
     * @var list<string>
     */
    protected const SCHEDULE_WORKSPACE_CAPABILITIES = [
        'schedule.view',
        'schedule.create',
        'schedule.update',
        'schedule.delete',
        'schedule.generate',
        'schedule.assign_instructor',
        'schedule.assign_instructor_cross_department',
        'schedule.submit',
        'schedule.withdraw',
    ];

    /**
     * Grants a user its scheduling capabilities, as User Management would.
     *
     * Pass an explicit list when the test's subject is the capability check
     * itself -- granting everything would hide the denial it means to assert.
     *
     * @param  list<string>|null  $capabilities
     */
    protected function grantCapabilities(\App\Models\User $user, ?array $capabilities = null): \App\Models\User
    {
        $user->syncPermissions($capabilities ?? self::SCHEDULE_WORKSPACE_CAPABILITIES);

        return $user->fresh();
    }
}
