<?php

namespace NexusPlugin\Xseed\Filament;

use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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

    public static function getNavigationLabel(): string
    {
        return nexus_trans('xseed::xseed.nav_label');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            TextInput::make('name')
                ->label(nexus_trans('xseed::xseed.field_name'))
                ->helperText(nexus_trans('xseed::xseed.field_name_help'))
                ->required()
                ->maxLength(50)
            ,
            TextInput::make('base_url')
                ->label(nexus_trans('xseed::xseed.field_base_url'))
                ->helperText(nexus_trans('xseed::xseed.field_base_url_help'))
                ->required()
                ->url()
                ->maxLength(255)
            ,
            TextInput::make('secret')
                ->label(nexus_trans('xseed::xseed.field_secret'))
                ->helperText(nexus_trans('xseed::xseed.field_secret_help'))
                ->password()
                ->revealable()
                ->required()
                ->maxLength(64)
            ,
            TextInput::make('icon')
                ->label(nexus_trans('xseed::xseed.field_icon'))
                ->helperText(nexus_trans('xseed::xseed.field_icon_help'))
                ->maxLength(255)
            ,
            Toggle::make('enabled')
                ->label(nexus_trans('xseed::xseed.field_enabled'))
                ->default(true)
            ,
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('base_url')->searchable()->limit(40),
                TextColumn::make('enabled')->badge(),
                TextColumn::make('last_sync_at')
                    ->label(nexus_trans('xseed::xseed.col_last_sync'))
                    ->formatStateUsing(fn ($state) => $state ? date('Y-m-d H:i', (int) $state) : '-'),
                TextColumn::make('last_sync_result')->limit(40),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label(nexus_trans('xseed::xseed.action_sync'))
                    ->requiresConfirmation()
                    ->action(function (XseedSite $record) {
                        $result = (new XseedRepository())->pullSite($record, 5);

                        $record->update([
                            'last_sync_at' => time(),
                            'last_sync_result' => $result['error'] !== ''
                                ? mb_substr('ERR ' . $result['error'], 0, 250)
                                : sprintf('OK total=%d hit=%d', $result['total'], $result['hit']),
                        ]);

                        $result['error'] !== ''
                            ? Notification::make()->title($result['error'])->danger()->send()
                            : Notification::make()->title(sprintf('查询 %d，命中 %d', $result['total'], $result['hit']))->success()->send();
                    }),
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
