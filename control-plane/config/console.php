<?php

return [
    // Where the browser reaches the websocket server (Reverb) for the terminal; empty means the local one.
    'socket_url' => env('CONSOLE_SOCKET_URL', env('UNSCRIPTED_SOCKET_URL')),
];
