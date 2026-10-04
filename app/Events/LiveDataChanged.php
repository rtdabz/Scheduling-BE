<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

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
