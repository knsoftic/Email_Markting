<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class UsageCounter extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'emails_sent' => 'integer',
            'emails_failed' => 'integer',
            'emails_received' => 'integer',
            'campaigns_created' => 'integer',
            'contacts_added' => 'integer',
            'storage_bytes' => 'integer',
        ];
    }

    public static function currentPeriod(): string
    {
        return now()->format('Y-m');
    }
}
