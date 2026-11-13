<?php

namespace NexusPlugin\Xseed\Filament;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use NexusPlugin\Xseed\Filament\XseedSiteResource\Pages\ManageXseedSites;
use NexusPlugin\Xseed\Models\XseedSite;
use NexusPlugin\Xseed\XseedRepository;

class XseedSiteResource extends Resource
{
    protected static ?string $model = XseedSite::class;

    protected static string|null|\UnitEnum $navigationGroup = 'Other';

    protected static ?int $navigationSort = 98;

    /** 侧栏名 = 页面标题，所以两者共用同一套文案 */
    public static function getNavigationLabel(): string
    {
        return XseedRepository::t('nav_label');
    }

    public static function getModelLabel(): string
    {
        return XseedRepository::t('model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return XseedRepository::t('model_label_plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->label(XseedRepository::t('field_name'))
                ->helperText(XseedRepository::t('field_name_help'))
                ->required()
                ->maxLength(50)
            ,
            TextInput::make('base_url')
                ->label(XseedRepository::t('field_base_url'))
                ->helperText(XseedRepository::t('field_base_url_help'))
                ->required()
                ->url()
                // url() 规则放行 javascript:// 之类，而这里的值会被拼成前台 <a href>，
                // 必须卡死只允许 http(s)
                ->rules(['regex:/^https?:\/\//i'])
                ->maxLength(255)
            ,
            TextInput::make('secret')
                ->label(XseedRepository::t('field_secret'))
                ->helperText(XseedRepository::t('field_secret_help'))
                ->password()
                ->revealable()
                ->required()
                ->maxLength(64)
            ,
            TextInput::make('icon')
                ->label(XseedRepository::t('field_icon'))
                ->helperText(XseedRepository::t('field_icon_help'))
                ->maxLength(255)
            ,
            TextInput::make('interval_minutes')
                ->label(XseedRepository::t('field_interval'))
                ->helperText(XseedRepository::t('field_interval_help'))
                ->numeric()
                ->required()
                ->default(30)
                ->minValue(1)
                ->maxValue(1440)
            ,
            Toggle::make('enabled')
                ->label(XseedRepository::t('field_enabled'))
                ->default(true)
            ,
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label(XseedRepository::t('col_id'))
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('name')
                    ->label(XseedRepository::t('col_name'))
                    ->searchable(),
                TextColumn::make('base_url')
                    ->label(XseedRepository::t('col_base_url'))
                    ->searchable()
                    ->limit(40),
                // 顺序按运营视角排：先认站点，再看节奏与结果，状态放最后
                TextColumn::make('interval_minutes')
                    ->label(XseedRepository::t('col_interval'))
                    ->alignCenter()
                    ->formatStateUsing(fn ($state) => (int) $state . ' ' . XseedRepository::t('unit_minutes')),
                TextColumn::make('last_sync_at')
                    ->label(XseedRepository::t('col_last_sync'))
                    ->formatStateUsing(fn ($state) => $state ? date('Y-m-d H:i', (int) $state) : '-')
                    // 超过 3 倍间隔没同步就标红：调度被锁挡住、cron 停了都靠这个发现
                    ->color(fn ($record) => $record && $record->isStalled() ? 'danger' : 'gray')
                    ->badge(fn ($record) => $record && $record->isStalled()),
                // 命中 / 未命中是「累计」值，直接数缓存表，不是上一轮的
                TextColumn::make('hit_total')
                    ->label(XseedRepository::t('col_hit'))
                    ->alignCenter()
                    ->color(fn ($state) => (int) $state > 0 ? 'success' : 'gray')
                    ->tooltip(fn ($record) => ($record?->last_sync_result ?? '') !== '' ? $record->last_sync_result : null),
                TextColumn::make('miss_total')
                    ->label(XseedRepository::t('col_miss'))
                    ->alignCenter()
                    ->color('gray'),
                // 不显示 1/0，直接给「启用 / 停用」
                TextColumn::make('enabled')
                    ->label(XseedRepository::t('col_enabled'))
                    ->alignCenter()
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state
                        ? XseedRepository::t('status_enabled')
                        : XseedRepository::t('status_disabled'))
                    ->color(fn ($record) => $record->enabled ? 'success' : 'gray'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageXseedSites::route('/'),
        ];
    }
}
