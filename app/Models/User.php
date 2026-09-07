<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'account_id',
        'role_id',
        'is_super_admin',
        'name',
        'email',
        'phone',
        'designation',
        'avatar_path',
        'timezone',
        'status',
        'password',
    ];

    /**
     * Two-factor columns are hidden as well: they must never reach a view,
     * an API response or a log line.
     */
    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function ownedAccounts(): HasMany
    {
        return $this->hasMany(Account::class, 'owner_id');
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    // ------------------------------------------------------------------ helpers

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    public function isAccountOwner(): bool
    {
        return $this->account !== null && $this->account->owner_id === $this->id;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Super admins bypass the permission table entirely; account owners hold
     * every permission inside their own account; staff are checked against
     * their role.
     */
    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if ($this->isAccountOwner()) {
            return true;
        }

        return $this->relationLoaded('role') || $this->role_id
            ? (bool) $this->role?->hasPermission($slug)
            : false;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function avatarUrl(): string
    {
        if ($this->avatar_path && Storage::disk('public')->exists($this->avatar_path)) {
            return Storage::disk('public')->url($this->avatar_path);
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&background=1d4ed8&color=fff';
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $letters = array_map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)), array_slice($parts, 0, 2));

        return implode('', $letters) ?: 'U';
    }
}
