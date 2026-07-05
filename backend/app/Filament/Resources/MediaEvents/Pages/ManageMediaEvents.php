<?php

namespace App\Filament\Resources\MediaEvents\Pages;

use App\Filament\Resources\MediaEvents\MediaEventResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMediaEvents extends ManageRecords
{
    protected static string $resource = MediaEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
