<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * "These areas of the system changed" -- topic names only, no records.
 *
 * Broadcast synchronously: the scheduling queue can be busy with a
 * multi-minute generation run, and an update delayed behind it is no longer
 * live. The payload is a few bytes, so sending it inline costs milliseconds.
 */
class LiveDataChanged implements ShouldBroadcastNow
{
    use InteractsWithSockets;

    /**
     * @param  list<string>  $topics
     */
    public function __construct(public array $topics) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('wicars.live');
    }

    public function broadcastAs(): string
    {
        return 'data.changed';
    }

    /** @return array{topics: list<string>} */
    public function broadcastWith(): array
    {
        return ['topics' => $this->topics];
    }
}
