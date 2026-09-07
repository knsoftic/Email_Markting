<?php

/*
|--------------------------------------------------------------------------
| KN Softic platform configuration
|--------------------------------------------------------------------------
| Deployment-level values that can differ per server. Anything a super admin
| should be able to change at runtime lives in the settings table instead.
*/

return [
    // How many recipients are pushed per campaign batch chunk.
    'campaign_chunk' => (int) env('KNS_CAMPAIGN_CHUNK', 500),

    // Global throttle guard on outgoing mail, per minute.
    'send_rate_per_minute' => (int) env('KNS_SEND_RATE_PER_MINUTE', 120),

    // Minutes between automatic IMAP syncs for an active mailbox.
    'mailbox_sync_minutes' => (int) env('KNS_MAILBOX_SYNC_MINUTES', 5),

    // Max messages pulled from a mailbox in one sync pass.
    'mailbox_sync_limit' => (int) env('KNS_MAILBOX_SYNC_LIMIT', 100),

    // Max accepted attachment size, in kilobytes.
    'max_attachment_kb' => (int) env('KNS_MAX_ATTACHMENT_KB', 10240),

    // Consecutive SMTP failures before an account is put in cooldown.
    'smtp_failure_threshold' => (int) env('KNS_SMTP_FAILURE_THRESHOLD', 5),

    // Cooldown length in minutes once the failure threshold is hit.
    'smtp_cooldown_minutes' => (int) env('KNS_SMTP_COOLDOWN_MINUTES', 30),

    // Hard bounces tolerated for an address before auto-suppression.
    'hard_bounce_limit' => (int) env('KNS_HARD_BOUNCE_LIMIT', 1),

    // Master switch for open/click tracking; per-campaign toggles still apply.
    'tracking_enabled' => filter_var(env('KNS_TRACKING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
];
