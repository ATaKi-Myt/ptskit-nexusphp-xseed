<?php

namespace NexusPlugin\Xseed\Filament\XseedSiteResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use NexusPlugin\Xseed\Filament\XseedSiteResource;

class ManageXseedSites extends ManageRecords
{
    protected static string $resource = XseedSiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
