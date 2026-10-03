<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['payload', 'backup', 'created_rows', 'before_hash', 'after_hash', 'backup_hash'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'backup' => 'encrypted:array', 'created_rows' => 'array', 'summary' => 'array'];
    }
}
