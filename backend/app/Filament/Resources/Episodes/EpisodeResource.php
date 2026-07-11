<?php

namespace App\Filament\Resources\Episodes;

use App\Enums\MetadataReviewStatus;
use App\Filament\Resources\Episodes\Pages\ManageEpisodes;
use App\Models\Episode;
use App\Services\KalveriAIMediaMatcherService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EpisodeResource extends Resource
{
    protected static ?string $model = Episode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.email')->label('User')->searchable()->sortable(),
                TextColumn::make('show.title')->label('Show')->searchable()->sortable(),
                TextColumn::make('season_number')->label('Season')->numeric()->sortable(),
                TextColumn::make('episode_number')->label('Episode')->numeric()->sortable(),
                TextColumn::make('title')->searchable()->toggleable(),
                TextColumn::make('tmdb_id')->label('TMDB')->sortable()->toggleable(),
                TextColumn::make('metadata_review_status')->label('Review')->badge()->sortable(),
                TextColumn::make('last_metadata_failure_reason')->label('Failure')->badge()->placeholder('none'),
                TextColumn::make('metadata_failure_count')->label('Failures')->numeric()->sortable(),
                TextColumn::make('metadata.ai_review_suggestion.status')->label('AI suggestion')->badge()->placeholder('none'),
                TextColumn::make('metadata.ai_review_suggestion.tmdbSeason')->label('AI season')->placeholder('none')->toggleable(),
                TextColumn::make('metadata.ai_review_suggestion.tmdbEpisode')->label('AI episode')->placeholder('none')->toggleable(),
                TextColumn::make('metadata_refreshed_at')->label('Refreshed')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('metadata_review_status')->options([
                    MetadataReviewStatus::Pending->value => 'Pending',
                    MetadataReviewStatus::Ignored->value => 'Ignored',
                    MetadataReviewStatus::ManuallyMatched->value => 'Manually matched',
                ]),
                SelectFilter::make('last_metadata_failure_reason'),
            ])
            ->recordActions([
                Action::make('askAI')
                    ->label('Ask Kalveri AI')
                    ->requiresConfirmation()
                    ->action(fn (Episode $record): array => app(KalveriAIMediaMatcherService::class)->matchMetadataReviewEpisode($record->user, $record)),
                Action::make('applyAISuggestion')
                    ->label('Apply AI suggestion')
                    ->requiresConfirmation()
                    ->visible(fn (Episode $record): bool => filled($record->metadata['ai_review_suggestion']['tmdbSeason'] ?? null)
                        && filled($record->metadata['ai_review_suggestion']['tmdbEpisode'] ?? null))
                    ->action(fn (Episode $record): array => app(KalveriAIMediaMatcherService::class)->applyReviewMatch(
                        $record->user,
                        $record,
                        (int) $record->metadata['ai_review_suggestion']['tmdbSeason'],
                        (int) $record->metadata['ai_review_suggestion']['tmdbEpisode'],
                    )),
                Action::make('ignore')
                    ->label('Ignore')
                    ->requiresConfirmation()
                    ->action(function (Episode $record): void {
                        $record->forceFill([
                            'metadata_review_status' => MetadataReviewStatus::Ignored->value,
                            'metadata_failed_at' => $record->metadata_failed_at ?: now(),
                        ])->save();
                    }),
            ])
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
            'index' => ManageEpisodes::route('/'),
        ];
    }
}
