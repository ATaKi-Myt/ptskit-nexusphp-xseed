<?php

namespace NexusPlugin\Xseed\Filament\XseedSiteResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;
use NexusPlugin\Xseed\Filament\XseedSiteResource;
use NexusPlugin\Xseed\XseedRepository;

class ManageXseedSites extends ManageRecords
{
    // 与后台其他列表页一致：内容区占满整个宽度（Filament 的 ManageRecords 默认是 7xl，会缩在中间）
    protected Width|string|null $maxContentWidth = 'full';

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
