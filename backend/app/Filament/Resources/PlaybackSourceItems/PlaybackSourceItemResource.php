<?php

namespace App\Filament\Resources\PlaybackSourceItems;

use App\Filament\Resources\PlaybackSourceItems\Pages\ManagePlaybackSourceItems;
use App\Models\PlaybackSourceItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlaybackSourceItemResource extends Resource
{
    protected static ?string $model = PlaybackSourceItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable()->sortable(),
                TextColumn::make('source.name')->label('Source')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('kind')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                IconColumn::make('mediaLink.id')->label('Linked')->boolean(),
                TextColumn::make('stream_url_hash')->label('Stream hash')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_seen_at')->dateTime()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind'),
                SelectFilter::make('status')->options([
                    'available' => 'Available',
                    'disabled' => 'Disabled',
                ]),
            ])
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePlaybackSourceItems::route('/'),
        ];
    }
}
