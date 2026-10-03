<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WatchRevision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'undone_at' => 'datetime'];
    }
}
