<?php

namespace App\Filament\Resources\PlaybackSources\Pages;

use App\Filament\Resources\PlaybackSources\PlaybackSourceResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePlaybackSources extends ManageRecords
{
    protected static string $resource = PlaybackSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
