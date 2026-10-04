<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

class SessionReplaced implements ShouldBroadcastNow
{
    public function __construct(public int $userId, public int $activeTokenId) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->userId);
    }

    public function broadcastAs(): string
    {
        return 'session.replaced';
    }

    /** @return array{token_id: int} */
    public function broadcastWith(): array
    {
        return ['token_id' => $this->activeTokenId];
    }
}
