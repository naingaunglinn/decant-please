<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\StudioPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    StudioPanelProvider::class,
];
