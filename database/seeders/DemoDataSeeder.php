<?php

namespace Database\Seeders;

use App\Models\CustomField;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Support\TenantManager;
use Illuminate\Database\Seeder;

/**
 * A realistic demo account for local work and screenshots.
 *
 * Idempotent: running it twice tops the data up rather than duplicating it, so
 * it can be re-run after a migrate:fresh without any tidying.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    public const EMAIL = 'demo@knsoftic.test';

    public const PASSWORD = 'Password123!';

    public function run(): void
    {
        $owner = User::withoutGlobalScopes()->firstWhere('email', self::EMAIL)
            ?? app(AccountProvisioner::class)->provision([
                'company_name' => 'KN Softic Demo',
                'name' => 'Demo Owner',
                'email' => self::EMAIL,
                'password' => self::PASSWORD,
                'timezone' => 'Asia/Karachi',
            ]);

        $owner->forceFill(['email_verified_at' => now()])->save();

        // Unlock the plan-gated features so every screen is reachable locally.
        $owner->account->subscription?->update(['overrides' => [
            'allow_custom_smtp' => true,
            'allow_smtp_rotation' => true,
            'allow_segments' => true,
            'allow_custom_fields' => true,
            'max_smtp_accounts' => 5,
            'max_contacts' => 5000,
        ]]);

        app(TenantManager::class)->runAs($owner->account_id, function () use ($owner) {
            $this->contacts($owner);
            $this->smtp($owner);
            $this->template($owner);
        });

        $this->command?->info('Demo account ready: '.self::EMAIL.' / '.self::PASSWORD);
    }

    protected function contacts(User $owner): void
    {
        if (Subscriber::count() > 0) {
            return;
        }

        $newsletter = SubscriberList::firstOrCreate(['name' => 'Newsletter'], ['description' => 'Main opt-in list']);
        $vip = SubscriberList::firstOrCreate(['name' => 'VIP Customers'], []);

        $lead = Tag::firstOrCreate(['slug' => 'lead'], ['name' => 'Lead', 'color' => '#2563eb']);
        $vipTag = Tag::firstOrCreate(['slug' => 'vip'], ['name' => 'VIP', 'color' => '#7c3aed']);

        CustomField::firstOrCreate(['key' => 'tier'], [
            'name' => 'Tier', 'type' => 'select', 'options' => ['bronze', 'silver', 'gold'],
        ]);

        $countries = ['Pakistan', 'India', 'United Kingdom', 'United States', 'Germany'];
        $cities = ['Lahore', 'Karachi', 'London', 'New York', 'Berlin'];
        $statuses = ['active', 'active', 'active', 'active', 'pending', 'unsubscribed', 'bounced'];
        $firstNames = ['Ayesha', 'Bilal', 'Carla', 'Daniyal', 'Emma', 'Farhan', 'Grace'];
        $lastNames = ['Khan', 'Ahmed', 'Smith', 'Iqbal', 'Müller', 'Rossi', 'Chen'];

        for ($i = 1; $i <= 60; $i++) {
            $first = $firstNames[$i % count($firstNames)];
            $last = $lastNames[$i % count($lastNames)];

            $subscriber = Subscriber::create([
                'email' => 'contact'.$i.'@example.com',
                'name' => $first.' '.$last,
                'first_name' => $first,
                'last_name' => $last,
                'company' => 'Company '.(($i % 7) + 1),
                'country' => $countries[$i % 5],
                'city' => $cities[$i % 5],
                'status' => $statuses[$i % 7],
                'source' => $i % 2 ? 'signup form' : 'import',
                'consent_status' => $i % 3 ? 'explicit' : 'implied',
                'consent_at' => now()->subDays($i),
                'subscribed_at' => now()->subDays($i),
                'custom' => ['tier' => ['bronze', 'silver', 'gold'][$i % 3]],
            ]);

            $subscriber->lists()->attach($i % 3 ? $newsletter->id : $vip->id, ['subscribed_at' => now()]);
            $subscriber->tags()->attach($i % 4 ? $lead->id : $vipTag->id);
        }

        $newsletter->refreshCounts();
        $vip->refreshCounts();
        $lead->refreshCount();
        $vipTag->refreshCount();

        Suppression::firstOrCreate(['email' => 'complained@example.com'], [
            'reason' => 'spam_complaint', 'source' => 'manual', 'notes' => 'Feedback loop report',
        ]);
        Suppression::firstOrCreate(['email' => 'hardbounce@example.com'], [
            'reason' => 'hard_bounce', 'source' => 'smtp',
        ]);

        Segment::firstOrCreate(['name' => 'Active in Pakistan'], [
            'match_type' => 'all',
            'rules' => [
                ['field' => 'status', 'operator' => 'is', 'value' => 'active'],
                ['field' => 'country', 'operator' => 'is', 'value' => 'Pakistan'],
            ],
        ]);
    }

    protected function smtp(User $owner): void
    {
        if (SmtpAccount::withoutGlobalScopes()->where('account_id', $owner->account_id)->count() === 0) {
            SmtpAccount::create([
                'name' => 'Primary Brevo', 'provider' => 'brevo',
                'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls',
                'username' => 'demo@knsoftic.test', 'password' => 'demo-smtp-key',
                'from_name' => 'KN Softic Demo', 'from_email' => 'demo@knsoftic.test',
                'daily_limit' => 500, 'hourly_limit' => 100,
                'sent_today' => 312, 'sent_this_hour' => 41,
                'day_reset_at' => now()->startOfDay(), 'hour_reset_at' => now()->startOfHour(),
                'total_sent' => 4821, 'test_passed' => true,
                'last_tested_at' => now()->subHours(3), 'last_success_at' => now()->subMinutes(12),
                'priority' => 0,
            ]);

            SmtpAccount::create([
                'name' => 'Backup Gmail', 'provider' => 'gmail',
                'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls',
                'username' => 'backup@example.com', 'password' => 'app-password-here',
                'from_name' => 'KN Softic Demo', 'from_email' => 'backup@example.com',
                'daily_limit' => 500, 'priority' => 1,
                'consecutive_failures' => 5, 'cooldown_until' => now()->addMinutes(18),
                'last_error' => '535 5.7.8 Username and Password not accepted',
                'last_error_at' => now()->subMinutes(6),
            ]);
        }

        if (SmtpAccount::withoutGlobalScopes()->where('is_global', true)->count() === 0) {
            $shared = SmtpAccount::withoutGlobalScopes()->create([
                'account_id' => null, 'is_global' => true,
                'name' => 'KN Softic Shared Relay', 'provider' => 'ses',
                'host' => 'email-smtp.eu-west-1.amazonaws.com', 'port' => 587, 'encryption' => 'tls',
                'username' => 'AKIAEXAMPLE', 'password' => 'ses-smtp-password',
                'from_name' => 'KN Softic', 'from_email' => 'send@knsoftic.com',
                'daily_limit' => 50000, 'sent_today' => 8412, 'day_reset_at' => now()->startOfDay(),
                'test_passed' => true, 'last_tested_at' => now()->subDay(),
            ]);

            SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);
        }
    }

    /** A worked template so the builder and preview have something real in them. */
    protected function template(User $owner): void
    {
        if (EmailTemplate::where('account_id', $owner->account_id)->count() > 0) {
            return;
        }

        $blocks = [
            'settings' => ['preheader' => '20% off everything until Sunday'],
            'blocks' => [
                ['id' => 'b1', 'type' => 'logo', 'settings' => [
                    'src' => 'https://dummyimage.com/320x80/1d4ed8/ffffff&text=KN+Softic',
                    'alt' => 'KN Softic', 'width' => 160, 'href' => 'https://knsoftic.com',
                ]],
                ['id' => 'b2', 'type' => 'heading', 'settings' => [
                    'text' => 'Spring sale, {{first_name|there}}', 'level' => 1, 'fontSize' => 30, 'align' => 'center',
                ]],
                ['id' => 'b3', 'type' => 'text', 'settings' => [
                    'html' => '<p>Everything in the store is <strong>20% off</strong> until Sunday.</p>',
                    'align' => 'center',
                ]],
                ['id' => 'b4', 'type' => 'button', 'settings' => [
                    'text' => 'Shop the sale', 'href' => 'https://example.com/sale', 'align' => 'center',
                ]],
                ['id' => 'b5', 'type' => 'divider', 'settings' => []],
                ['id' => 'b6', 'type' => 'columns', 'settings' => ['count' => 2, 'columns' => [
                    ['html' => '<p><strong>Free delivery</strong><br>On orders over 50.</p>'],
                    ['html' => '<p><strong>30-day returns</strong><br>No questions asked.</p>'],
                ]]],
                ['id' => 'b7', 'type' => 'image', 'settings' => [
                    'src' => 'https://dummyimage.com/1104x480/e2e8f0/64748b&text=Product+range',
                    'alt' => 'Product range',
                ]],
                ['id' => 'b8', 'type' => 'social', 'settings' => ['links' => [
                    ['network' => 'facebook', 'href' => 'https://facebook.com/knsoftic'],
                    ['network' => 'instagram', 'href' => 'https://instagram.com/knsoftic'],
                    ['network' => 'linkedin', 'href' => 'https://linkedin.com/company/knsoftic'],
                ]]],
                ['id' => 'b9', 'type' => 'footer', 'settings' => [
                    'companyLine' => 'KN Softic Demo',
                    'addressLine' => '12 Market Street, Lahore, Pakistan',
                ]],
            ],
        ];

        $compiler = app(EmailCompiler::class);

        EmailTemplate::create([
            'user_id' => $owner->id,
            'name' => 'Spring Sale',
            'subject' => 'Spring sale — 20% off everything',
            'description' => 'Seasonal promotion with a hero, two features and a product shot.',
            'category' => 'promotion',
            'blocks' => $blocks,
            'html' => $compiler->compile($blocks),
            'plain_text' => $compiler->compileText($blocks),
        ]);
    }
}
