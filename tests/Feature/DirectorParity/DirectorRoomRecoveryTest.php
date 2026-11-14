<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\RoomRecoveryTest;

/**
 * Reruns {@see RoomRecoveryTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorRoomRecoveryTest extends RoomRecoveryTest
{
    protected string $officeRole = 'director';
}
