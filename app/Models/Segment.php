<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Segment extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rules' => 'array',
            'last_calculated_at' => 'datetime',
        ];
    }
}
