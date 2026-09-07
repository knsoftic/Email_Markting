<?php

namespace Tests\Feature\Hardening;

use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\EmailThread;
use App\Models\Mailbox;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A list screen must cost the same whether it shows three rows or fifty.
 *
 * An N+1 is invisible in development, where every table holds a handful of
 * rows, and it is the first thing that breaks in production. It also cannot be
 * caught by asserting a fixed number of queries: that number legitimately
 * changes whenever a screen gains a feature, so the test would be rewritten to
 * match the code rather than checking it.
 *
 * So each screen is rendered twice — once with a few rows and once with five
 * times as many — and what is asserted is the SHAPE: the count must not grow
 * with the row count. A per-row query shows up immediately and a genuine extra
 * query for a new feature does not.
 */
class ListScreenQueryCountTest extends TestCase
{
    use RefreshDatabase;

    /** Rows in the small pass, and in the large one. */
    protected const FEW = 3;

    protected const MANY = 15;

    /**
     * Queries a screen may gain between the two passes without it being a
     * per-row cost. Some growth is honest — a paginator's COUNT can change
     * plan, an eager load appears once the relation is non-empty — but a
     * per-row query would add twelve.
     */
    protected const TOLERANCE = 4;

    protected User $owner;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Volume Ltd', 'name' => 'Owner', 'email' => 'owner@volume.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'allow_inbox' => true, 'max_mailboxes' => 5,
            'allow_automation' => true, 'max_automations' => 50,
            'allow_segments' => true, 'max_segments' => 50,
            'max_contacts' => 100000, 'max_lists' => 500, 'max_templates' => 500,
            'max_emails_per_month' => 100000, 'max_team_members' => 50,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = Mailbox::create([
            'account_id' => $this->owner->account_id, 'user_id' => $this->owner->id,
            'name' => 'Support', 'email' => 'support@volume.test',
            'imap_host' => 'imap.volume.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@volume.test', 'imap_password' => 'secret',
        ]);
    }

    /**
     * Every list screen, with its recipe for making one more row.
     *
     * @return array<string, array{0: string, 1: callable(int): void}>
     */
    public static function screens(): array
    {
        return [
            'contacts' => ['/subscribers', 'seedContacts'],
            'lists' => ['/lists', 'seedLists'],
            'tags' => ['/tags', 'seedTags'],
            'segments' => ['/segments', 'seedSegments'],
            'suppressions' => ['/suppressions', 'seedSuppressions'],
            'campaigns' => ['/campaigns', 'seedCampaigns'],
            'templates' => ['/templates', 'seedTemplates'],
            'inbox' => ['/inbox', 'seedInbox'],
            'campaign replies' => ['/campaign-replies', 'seedThreads'],
            'email logs' => ['/logs', 'seedLogs'],
            'automations' => ['/automations', 'seedAutomations'],
            'notifications' => ['/notifications', 'seedNotifications'],
            'team' => ['/team', 'seedTeam'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('screens')]
    public function test_a_list_screen_does_not_cost_a_query_per_row(string $url, string $seeder): void
    {
        $this->{$seeder}(self::FEW);
        $small = $this->queriesFor($url);

        $this->{$seeder}(self::MANY - self::FEW);
        $large = $this->queriesFor($url);

        $growth = $large - $small;

        $this->assertLessThanOrEqual(
            self::TOLERANCE,
            $growth,
            sprintf(
                '%s cost %d queries for %d rows and %d for %d — %d more for %d extra rows. '
                .'That is a query per row: eager-load the relation the view reads, '
                .'or batch the lookup the way the automations index does.',
                $url, $small, self::FEW, $large, self::MANY, $growth, self::MANY - self::FEW
            )
        );
    }

    /** Renders the screen and counts what it asked the database. */
    protected function queriesFor(string $url): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->get($url)->assertOk();

        $count = count(DB::getQueryLog());

        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    // ------------------------------------------------------------- fixtures

    protected function seedContacts(int $n): void
    {
        static $seq = 0;
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();
        $tag = Tag::factory()->forAccount($this->owner->account)->create();

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $contact = Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "person{$seq}@example.com"]);

            // Relations the row actually renders — the shape an N+1 hides in.
            $contact->lists()->attach($list->id, ['subscribed_at' => now()]);
            $contact->tags()->attach($tag->id);
        }
    }

    protected function seedLists(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => "List {$seq}"]);
        }
    }

    protected function seedTags(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            Tag::factory()->forAccount($this->owner->account)->create(['name' => "Tag {$seq}"]);
        }
    }

    protected function seedSegments(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            Segment::factory()->forAccount($this->owner->account)->create(['name' => "Segment {$seq}"]);
        }
    }

    protected function seedSuppressions(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            Suppression::factory()->forAccount($this->owner->account)
                ->create(['email' => "blocked{$seq}@example.com"]);
        }
    }

    protected function seedCampaigns(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            Campaign::factory()->forAccount($this->owner->account)->create([
                'name' => "Campaign {$seq}", 'subject' => "Subject {$seq}",
                'status' => 'completed', 'sent_count' => 10, 'total_recipients' => 10,
                'completed_at' => now(),
            ]);
        }
    }

    protected function seedTemplates(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            EmailTemplate::create([
                'account_id' => $this->owner->account_id, 'user_id' => $this->owner->id,
                'name' => "Template {$seq}", 'subject' => "Subject {$seq}",
                'html' => '<p>Body</p>',
            ]);
        }
    }

    protected function seedInbox(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            Email::create([
                'account_id' => $this->owner->account_id, 'mailbox_id' => $this->mailbox->id,
                'direction' => 'incoming', 'folder_type' => 'inbox',
                'message_id' => "<msg{$seq}@example.test>", 'subject' => "Message {$seq}",
                'from_email' => "sender{$seq}@example.com", 'from_name' => "Sender {$seq}",
                'to' => ['support@volume.test'], 'body_text' => 'Hello there',
                'received_at' => now()->subMinutes($seq),
            ]);
        }
    }

    protected function seedThreads(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;

            // Each thread on its OWN campaign: a shared one would be loaded
            // once and hide exactly the N+1 this is looking for.
            $campaign = Campaign::factory()->forAccount($this->owner->account)
                ->create(['name' => "Replied campaign {$seq}", 'subject' => 'Hi']);

            EmailThread::create([
                'account_id' => $this->owner->account_id, 'mailbox_id' => $this->mailbox->id,
                'thread_key' => "thread-{$seq}", 'campaign_id' => $campaign->id,
                'subject' => "Conversation {$seq}", 'reply_status' => 'new',
                'messages_count' => 1, 'last_message_at' => now()->subMinutes($seq),
            ]);
        }
    }

    protected function seedLogs(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $campaign = Campaign::factory()->forAccount($this->owner->account)
                ->create(['name' => "Logged campaign {$seq}", 'subject' => 'Hi']);

            CampaignLog::create([
                'account_id' => $this->owner->account_id, 'campaign_id' => $campaign->id,
                'type' => 'campaign', 'status' => 'sent',
                'recipient_email' => "recipient{$seq}@example.com", 'subject' => "Subject {$seq}",
            ]);
        }
    }

    protected function seedAutomations(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => "Trigger tag {$seq}"]);

            $automation = \App\Models\Automation::create([
                'account_id' => $this->owner->account_id, 'user_id' => $this->owner->id,
                'name' => "Automation {$seq}", 'trigger_type' => 'tag_added',
                'trigger_config' => ['tag_id' => $tag->id], 'status' => 'draft',
            ]);

            \App\Models\AutomationStep::create([
                'automation_id' => $automation->id, 'position' => 1, 'type' => 'wait',
                'config' => ['amount' => 1, 'unit' => 'days'],
            ]);
        }
    }

    protected function seedNotifications(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            $campaign = Campaign::factory()->forAccount($this->owner->account)
                ->create(['name' => "Notified campaign {$seq}", 'subject' => 'Hi']);

            DB::table('notifications')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'type' => \App\Notifications\AccountNotification::class,
                'notifiable_type' => User::class,
                'notifiable_id' => $this->owner->id,
                'data' => json_encode([
                    'title' => "Campaign {$seq} finished", 'body' => 'It went out.',
                    'level' => 'info', 'icon' => 'campaign', 'kind' => 'campaign',
                    'target_id' => $campaign->id, 'route' => 'campaigns.show',
                    'route_param' => 'campaign',
                ]),
                'created_at' => now()->subMinutes($seq),
                'updated_at' => now()->subMinutes($seq),
            ]);
        }
    }

    protected function seedTeam(int $n): void
    {
        static $seq = 0;

        for ($i = 0; $i < $n; $i++) {
            $seq++;
            User::factory()->create([
                'account_id' => $this->owner->account_id,
                'name' => "Colleague {$seq}",
                'email' => "colleague{$seq}@volume.test",
                'email_verified_at' => now(),
                'role_id' => \App\Models\Role::withoutGlobalScopes()
                    ->where('slug', \App\Models\Role::STAFF)->value('id'),
            ]);
        }
    }
}
