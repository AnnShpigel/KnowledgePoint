<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\RouteServiceProvider;
use App\Providers\Filament\AdminPanelProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    // App\Providers\BroadcastServiceProvider::class,
    EventServiceProvider::class,
    RouteServiceProvider::class,
    AdminPanelProvider::class,
];
