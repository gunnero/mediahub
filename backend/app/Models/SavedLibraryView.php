<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SavedLibraryView extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
