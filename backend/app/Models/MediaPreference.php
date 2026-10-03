<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MediaPreference extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'tags' => 'array'];
    }
}
