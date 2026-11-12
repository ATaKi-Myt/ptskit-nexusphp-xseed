<?php

namespace NexusPlugin\Xseed;

use App\Support\StaticMake;
use Filament\Contracts\Plugin;
use Filament\Panel;
use NexusPlugin\Xseed\Filament\XseedSiteResource;

class Xseed implements Plugin
{
    use StaticMake;

    const ID = 'xseed';

    public function getId(): string
    {
        return self::ID;
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            XseedSiteResource::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
    }
}
