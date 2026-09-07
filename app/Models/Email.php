<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Email extends Model
{
    use HasFactory, BelongsToAccount, SoftDeletes;

    public const FOLDERS = ['inbox', 'sent', 'drafts', 'spam', 'trash', 'archive'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'has_attachments' => 'boolean',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'is_important' => 'boolean',
            'is_draft' => 'boolean',
            'is_campaign_reply' => 'boolean',
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(MailboxFolder::class, 'mailbox_folder_id');
    }

    public function thread(): BelongsTo
    {
        return $this->belongsTo(EmailThread::class, 'email_thread_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function campaignRecipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function smtpAccount(): BelongsTo
    {
        return $this->belongsTo(SmtpAccount::class);
    }

    // ------------------------------------------------------------------- scopes

    public function scopeFolder(Builder $query, string $folder): Builder
    {
        return $query->where('folder_type', $folder);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeStarred(Builder $query): Builder
    {
        return $query->where('is_starred', true);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like) {
            $q->where('subject', 'like', $like)
                ->orWhere('from_email', 'like', $like)
                ->orWhere('from_name', 'like', $like)
                ->orWhere('preview', 'like', $like);
        });
    }

    public function scopeDueForSending(Builder $query): Builder
    {
        return $query->where('send_status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    // ------------------------------------------------------------------ helpers

    public function fromDisplay(): string
    {
        return $this->from_name ?: (string) $this->from_email;
    }

    public function recipientLine(): string
    {
        return collect($this->to ?? [])
            ->map(fn ($item) => is_array($item) ? ($item['email'] ?? '') : $item)
            ->filter()
            ->implode(', ');
    }

    public function markRead(): void
    {
        if (! $this->is_read) {
            $this->forceFill(['is_read' => true])->save();
        }
    }
}
