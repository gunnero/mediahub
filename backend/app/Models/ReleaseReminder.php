<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReleaseReminder extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
