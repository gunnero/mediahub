<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['subscription', 'endpoint_hash'];

    protected function casts(): array
    {
        return ['subscription' => 'encrypted:array'];
    }
}
