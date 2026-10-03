<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExperienceSetting extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['calendar_token_hash'];

    protected function casts(): array
    {
        return ['weekly_digest' => 'boolean', 'share_activity' => 'boolean', 'activity_since' => 'datetime', 'last_digest_at' => 'datetime'];
    }
}
