<?php

namespace NexusPlugin\Xseed\Filament\XseedSiteResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use NexusPlugin\Xseed\Filament\XseedSiteResource;
use NexusPlugin\Xseed\XseedRepository;

class ManageXseedSites extends ManageRecords
{
    protected static string $resource = XseedSiteResource::class;

    /** 页面大标题：不覆盖会由类名派生成「Xseed Site」 */
    public function getTitle(): string
    {
        return XseedRepository::t('nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(XseedRepository::t('action_create')),
        ];
    }
}
