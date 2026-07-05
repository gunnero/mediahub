<?php

namespace App\Filament\Resources\MediaEvents;

use App\Filament\Resources\MediaEvents\Pages\ManageMediaEvents;
use App\Models\MediaEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class MediaEventResource extends Resource
{
    protected static ?string $model = MediaEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable()->sortable(),
                TextColumn::make('event_type')->badge()->searchable()->sortable(),
                TextColumn::make('source')->badge()->sortable(),
                TextColumn::make('subject_type')->label('Subject')->formatStateUsing(fn (?string $state): string => class_basename($state ?: ''))->toggleable(),
                TextColumn::make('subject_id')->label('Subject ID')->sortable()->toggleable(),
                TextColumn::make('metadata_summary')
                    ->label('Metadata')
                    ->getStateUsing(fn (MediaEvent $record): string => str(json_encode($record->metadata ?? [], JSON_THROW_ON_ERROR))->limit(120)->toString())
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('event_type'),
                SelectFilter::make('source'),
            ])
            ->recordActions([
            ])
            ->toolbarActions([
            ]);
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
            'index' => ManageMediaEvents::route('/'),
        ];
    }
}
