<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToAccount;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_public' => 'boolean'];
    }

    /** Platform settings (account_id = null) are readable by every tenant. */
    public function accountScopeIncludesGlobal(): bool
    {
        return true;
    }

    /** Returns the stored value cast to its declared type. */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'integer' => (int) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            default => $this->value,
        };
    }
}
