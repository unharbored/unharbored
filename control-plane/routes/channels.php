<?php

declare(strict_types=1);

// This file registers the broadcasting auth route with the web session the console uses.

// A console's output goes only to the person who opened it.
Illuminate\Support\Facades\Broadcast::channel('console.{session}', fn ($user, string $session) => App\Lib\Console\Consoles::belongsTo($session, (string) $user->id));
