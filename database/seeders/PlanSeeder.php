<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * The three starting plans from the brief. Every value here is editable by
 * the super admin afterwards, and new plans can be added without code.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'description' => 'For getting started with permission-based email marketing.',
                'price' => 0,
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 1,
                'is_default' => true,
                'max_contacts' => 2000,
                'max_emails_per_month' => 10000,
                'max_emails_per_day' => 1000,
                'max_emails_received_per_month' => 5000,
                'max_campaigns_per_month' => 10,
                'max_smtp_accounts' => 0,
                'max_mailboxes' => 1,
                'max_lists' => 5,
                'max_templates' => 10,
                'max_automations' => 0,
                'max_team_members' => 1,
                'max_storage_mb' => 512,
                'allow_custom_smtp' => false,
                'allow_smtp_rotation' => false,
                'allow_admin_smtp' => true,
                'allow_imap' => true,
                'allow_automation' => false,
                'allow_ab_testing' => false,
                'allow_advanced_analytics' => false,
                'allow_segments' => false,
                'allow_custom_fields' => false,
            ],
            [
                'name' => 'Business',
                'slug' => 'business',
                'description' => 'For growing teams sending regular campaigns.',
                'price' => 29,
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 2,
                'max_contacts' => 20000,
                'max_emails_per_month' => 100000,
                'max_emails_per_day' => 10000,
                'max_emails_received_per_month' => 50000,
                'max_campaigns_per_month' => 100,
                'max_smtp_accounts' => 2,
                'max_mailboxes' => 3,
                'max_lists' => 50,
                'max_templates' => 100,
                'max_automations' => 5,
                'max_team_members' => 5,
                'max_storage_mb' => 5120,
                'allow_custom_smtp' => true,
                'allow_smtp_rotation' => false,
                'allow_admin_smtp' => true,
                'allow_imap' => true,
                'allow_automation' => true,
                'allow_ab_testing' => false,
                'allow_advanced_analytics' => true,
                'allow_segments' => true,
                'allow_custom_fields' => true,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'description' => 'Full platform: rotation, automation, A/B testing and advanced reports.',
                'price' => 79,
                'billing_period' => 'monthly',
                'trial_days' => 14,
                'sort_order' => 3,
                'max_contacts' => 100000,
                'max_emails_per_month' => 500000,
                'max_emails_per_day' => 50000,
                'max_emails_received_per_month' => 200000,
                'max_campaigns_per_month' => null,
                'max_smtp_accounts' => 5,
                'max_mailboxes' => 10,
                'max_lists' => null,
                'max_templates' => null,
                'max_automations' => 50,
                'max_team_members' => 20,
                'max_storage_mb' => 20480,
                'allow_custom_smtp' => true,
                'allow_smtp_rotation' => true,
                'allow_admin_smtp' => true,
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
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
