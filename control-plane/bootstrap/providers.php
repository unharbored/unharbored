<?php

use App\Providers\AppServiceProvider;
use App\Providers\ControlPlaneServiceProvider;
use App\Providers\CustomServiceProvider;
use App\Providers\VaultServiceProvider;

return [
    AppServiceProvider::class,
    VaultServiceProvider::class,
    ControlPlaneServiceProvider::class,
    CustomServiceProvider::class,
];
