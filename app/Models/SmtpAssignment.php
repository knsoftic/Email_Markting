<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmtpAssignment extends Model
{
    protected $guarded = ['id'];

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
