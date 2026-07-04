<?php

namespace App\Filament\Resources\PlaybackSources;

use App\Filament\Resources\PlaybackSources\Pages\ManagePlaybackSources;
use App\Models\PlaybackSource;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PlaybackSourceResource extends Resource
{
    protected static ?string $model = PlaybackSource::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlayCircle;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('provider_type')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('items_count')->counts('items')->label('Items')->sortable(),
                TextColumn::make('last_synced_at')->dateTime()->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    'active' => 'Active',
                    'disabled' => 'Disabled',
                ]),
                SelectFilter::make('provider_type'),
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
            'index' => ManagePlaybackSources::route('/'),
        ];
    }
}
