<?php

use Illuminate\Support\Facades\Broadcast;

// One user's notification bell.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// System-wide "something changed" signals. The payload is topic names only;
// every client refetches through its own authorised API calls, so any signed-in
// active account (enforced by the auth route's middleware) may listen.
Broadcast::channel('wicars.live', function ($user) {
    return $user !== null;
});
