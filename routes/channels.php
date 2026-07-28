<?php

use App\Models\OriginServer;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
| Authorizes clients that attempt to subscribe to private/presence channels.
| Run via: php artisan install:broadcasting
*/

Broadcast::channel('dashboard', function ($user) {
    // Any authenticated user can subscribe to the global dashboard feed
    return $user !== null;
});

Broadcast::channel('origins.{origin}', function ($user, int $originId) {
    // Customers see only their own origins; admins/operators see any.
    if ($user->hasRole(['admin', 'operator'])) {
        return true;
    }
    return OriginServer::where('id', $originId)
        ->where('user_id', $user->id)
        ->exists();
});

Broadcast::channel('edges.{edge}', function ($user, int $edgeId) {
    if ($user->hasRole(['admin', 'operator'])) {
        return true;
    }
    return $user !== null;
});
