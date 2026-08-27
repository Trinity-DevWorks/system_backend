<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Tenant isolation for private channels comes from InitializeTenancyByDomain
| on /broadcasting/auth (tenant DB only). User IDs are UUIDs, so the default
| App.Models.User.{id} channel name does not collide across tenants.
|
*/

Broadcast::channel('App.Models.User.{id}', function ($user, string $id) {
    return (string) $user->id === (string) $id;
});
