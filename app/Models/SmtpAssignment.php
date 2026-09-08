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

    /**
     * Soft-deleted plans included, because the assignment still matches one.
     *
     * SmtpSelector::assignmentSubquery() compares smtp_assignments.plan_id
     * against the subscription's plan_id and does not care whether that plan
     * has been soft-deleted — which is right: deleting a plan takes it off the
     * list for new accounts, and the accounts already on it keep the limits
     * they had. Without withTrashed() this relation resolves to null while the
     * assignment goes on working, so anything reading it reports a live
     * arrangement as a dangling row.
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /** Same reasoning as plan(): the row is still there, so report it. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withTrashed();
    }
}
