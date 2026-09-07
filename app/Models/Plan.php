<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plan extends Model
{
    use SoftDeletes;

    /** Limit columns, used by PlanLimit checks and the admin plan form. */
    public const LIMIT_KEYS = [
        'max_contacts',
        'max_emails_per_month',
        'max_emails_per_day',
        'max_emails_received_per_month',
        'max_campaigns_per_month',
        'max_smtp_accounts',
        'max_mailboxes',
        'max_lists',
        'max_templates',
        'max_automations',
        'max_team_members',
        'max_storage_mb',
    ];

    /** Boolean capability columns. */
    public const FEATURE_KEYS = [
        'allow_custom_smtp',
        'allow_smtp_rotation',
        'allow_admin_smtp',
        'allow_imap',
        'allow_automation',
        'allow_ab_testing',
        'allow_advanced_analytics',
        'allow_segments',
        'allow_custom_fields',
        'allow_attachments',
        'allow_scheduling',
        'allow_template_builder',
        'allow_api',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return array_merge(
            array_fill_keys(self::FEATURE_KEYS, 'boolean'),
            [
                'price' => 'decimal:2',
                'features' => 'array',
                'is_active' => 'boolean',
                'is_default' => 'boolean',
                'is_public' => 'boolean',
            ]
        );
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function smtpAssignments(): HasMany
    {
        return $this->hasMany(SmtpAssignment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('price');
    }

    /** NULL means unlimited for every limit column. */
    public function isUnlimited(string $key): bool
    {
        return $this->{$key} === null;
    }

    public function allows(string $feature): bool
    {
        return (bool) ($this->{$feature} ?? false);
    }
}
