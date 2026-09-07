<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory, BelongsToAccount;

    protected $guarded = ['id'];

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class);
    }

    public function refreshCount(): void
    {
        $this->forceFill(['subscribers_count' => $this->subscribers()->count()])->save();
    }
}
