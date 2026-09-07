<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class EmailAttachment extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_inline' => 'boolean'];
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
