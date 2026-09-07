<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToAccount;

    public const SUPER_ADMIN = 'super-admin';
    public const OWNER = 'account-owner';
    public const STAFF = 'staff';

    protected $fillable = ['account_id', 'name', 'slug', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /** System roles (account_id = null) are visible to every account. */
    public function accountScopeIncludesGlobal(): bool
    {
        return true;
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->slug === self::SUPER_ADMIN || $this->slug === self::OWNER) {
            return true;
        }

        return $this->permissions->contains('slug', $slug);
    }
}
