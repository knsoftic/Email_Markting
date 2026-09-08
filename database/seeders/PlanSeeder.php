<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The starting plan ladder. Every value here is editable by the super admin
 * afterwards in Admin → Plans, and new plans can be added without code.
 *
 * ── Why every column is written for every plan ──────────────────────────────
 * This seeder is idempotent by slug, which means it also runs against a live
 * database that already has these plans. `updateOrCreate` only writes the keys
 * it is given, so any key left out keeps whatever the row happens to hold —
 * which for a live row is the last value somebody typed into the admin form,
 * not the migration default. That made the seeder's result depend on history.
 *
 * So each plan below is built from `self::BASE`, which lists every limit and
 * every feature switch. Running the seeder now produces the same four plans
 * whether the database is empty or three years old.
 *
 * ── The two rules that govern the numbers ───────────────────────────────────
 *   NULL  = unlimited.
 *   0     = the capability is switched off (and PlanLimits treats it as such).
 * They are not interchangeable: `max_campaigns_per_month => null` on Pro means
 * "send as many as you like", while `max_smtp_accounts => 0` on Free means
 * "you get no SMTP accounts of your own at all".
 *
 * ── Free sends through the platform's SMTP ──────────────────────────────────
 * Free is the only plan with `allow_custom_smtp => false`. That is deliberate
 * and it is the shape of the ladder: a free account sends only through a
 * global SMTP account the operator has assigned to it, so the operator keeps
 * control of the sending reputation they are lending out. Paying gets you your
 * own SMTP credentials. Note that `allow_admin_smtp` is true on every plan —
 * a global account still only reaches an account that an SMTP assignment
 * covers, so switching it on here grants nothing by itself.
 */
class PlanSeeder extends Seeder
{
    /**
     * Every limit and feature column, at its most restrictive. Each plan below
     * overrides what it grants, so nothing can be silently inherited from a
     * row's previous contents.
     */
    protected const BASE = [
        'currency' => 'USD',
        'billing_period' => 'monthly',
        'trial_days' => 0,
        'is_active' => true,
        'is_public' => true,
        'is_default' => false,

        'max_contacts' => 0,
        'max_emails_per_month' => 0,
        'max_emails_per_day' => 0,
        'max_emails_received_per_month' => 0,
        'max_campaigns_per_month' => 0,
        'max_smtp_accounts' => 0,
        'max_mailboxes' => 0,
        'max_lists' => 0,
        'max_templates' => 0,
        'max_automations' => 0,
        'max_team_members' => 1,
        'max_storage_mb' => 0,

        'allow_custom_smtp' => false,
        'allow_smtp_rotation' => false,
        'allow_admin_smtp' => true,
        'allow_imap' => false,
        'allow_automation' => false,
        'allow_ab_testing' => false,
        'allow_advanced_analytics' => false,
        'allow_segments' => false,
        'allow_custom_fields' => false,
        'allow_attachments' => true,
        'allow_scheduling' => true,
        // Kept on everywhere on purpose: assertMayAdd() checks this before
        // *creating* a template, so switching it off would leave a plan that
        // grants max_templates but cannot make a single one.
        'allow_template_builder' => true,
        'allow_api' => false,
    ];

    public function run(): void
    {
        $plans = [
            [
                'name' => 'Free',
                'slug' => 'free',
                'description' => 'Try it out — send through our SMTP, no card needed.',
                'price' => 0,
                'sort_order' => 1,
                // New signups land here, so the first thing anybody sees costs
                // nothing. Exactly one plan may carry this flag; the three
                // below set it false explicitly to keep that true on re-seed.
                'is_default' => true,
                'trial_days' => 0,
                'max_contacts' => 500,
                'max_emails_per_month' => 1000,
                'max_emails_per_day' => 100,
                'max_campaigns_per_month' => 5,
                'max_lists' => 2,
                'max_templates' => 5,
                'max_storage_mb' => 100,
            ],
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'Your own SMTP, your own inbox, and room to send properly.',
                'price' => 5,
                'sort_order' => 2,
                'trial_days' => 14,
                'max_contacts' => 2000,
                'max_emails_per_month' => 10000,
                'max_emails_per_day' => 1000,
                'max_emails_received_per_month' => 5000,
                'max_campaigns_per_month' => 20,
                'max_smtp_accounts' => 1,
                'max_mailboxes' => 1,
                'max_lists' => 10,
                'max_templates' => 25,
                'max_team_members' => 2,
                'max_storage_mb' => 512,
                'allow_custom_smtp' => true,
                'allow_imap' => true,
                'allow_segments' => true,
                'allow_custom_fields' => true,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'For growing teams sending regular campaigns, with automation.',
                'price' => 29,
                'sort_order' => 3,
                'trial_days' => 14,
                'max_contacts' => 20000,
                'max_emails_per_month' => 100000,
                'max_emails_per_day' => 10000,
                'max_emails_received_per_month' => 50000,
                'max_campaigns_per_month' => 100,
                'max_smtp_accounts' => 3,
                'max_mailboxes' => 3,
                'max_lists' => 50,
                'max_templates' => 100,
                'max_automations' => 5,
                'max_team_members' => 5,
                'max_storage_mb' => 5120,
                'allow_custom_smtp' => true,
                'allow_imap' => true,
                'allow_automation' => true,
                'allow_advanced_analytics' => true,
                'allow_segments' => true,
                'allow_custom_fields' => true,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Full platform: rotation, automation, A/B testing, API and advanced reports.',
                'price' => 79,
                'sort_order' => 4,
                'trial_days' => 14,
                'max_contacts' => 100000,
                'max_emails_per_month' => 500000,
                'max_emails_per_day' => 50000,
                'max_emails_received_per_month' => 200000,
                'max_campaigns_per_month' => null,
                'max_smtp_accounts' => 10,
                'max_mailboxes' => 10,
                'max_lists' => null,
                'max_templates' => null,
                'max_automations' => 50,
                'max_team_members' => 20,
                'max_storage_mb' => 20480,
                'allow_custom_smtp' => true,
                'allow_smtp_rotation' => true,
                'allow_imap' => true,
                'allow_automation' => true,
                'allow_ab_testing' => true,
                'allow_advanced_analytics' => true,
                'allow_segments' => true,
                'allow_custom_fields' => true,
                'allow_api' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(
                ['slug' => $plan['slug']],
                array_merge(self::BASE, $plan)
            );
        }
    }
}
