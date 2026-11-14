<?php

namespace Tests\Feature\DirectorParity;

use Tests\Feature\RoomRequestTest;

/**
 * Reruns {@see RoomRequestTest} with a Director as the department office account. Director shares the
 * Secretary's workflow, permissions, and department scope, so every Secretary expectation holds.
 */
final class DirectorRoomRequestTest extends RoomRequestTest
{
    protected string $officeRole = 'director';
}
