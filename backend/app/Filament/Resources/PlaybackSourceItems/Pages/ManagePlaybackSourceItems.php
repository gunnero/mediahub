<?php

namespace App\Filament\Resources\PlaybackSourceItems\Pages;

use App\Filament\Resources\PlaybackSourceItems\PlaybackSourceItemResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePlaybackSourceItems extends ManageRecords
{
    protected static string $resource = PlaybackSourceItemResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
