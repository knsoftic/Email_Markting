<?php

namespace Tests\Feature\Search;

use App\Models\Automation;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Mailbox;
use App\Models\Permission;
use App\Models\Role;
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
 * The one search box.
 *
 * Two things can go wrong here that cannot go wrong on a single-module screen.
 * A search that reaches across ten tables at once is ten chances to forget the
 * tenant scope, and ten chances to tell somebody how many matches sit behind a
 * door their role cannot open. Both get an explicit test.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = $this->provision('Searchers Ltd', 'owner@search.test');

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = Mailbox::create([
            'account_id' => $this->owner->account_id, 'user_id' => $this->owner->id,
            'name' => 'Support', 'email' => 'support@search.test',
            'imap_host' => 'imap.search.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@search.test', 'imap_password' => 'secret',
        ]);

        $this->seedRecords($this->owner, 'quokka');
    }

    protected function provision(string $company, string $email): User
    {
        $user = app(AccountProvisioner::class)->provision([
            'company_name' => $company, 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();

        $subscription = $user->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_inbox' => true, 'max_mailboxes' => 5,
            'allow_automation' => true, 'max_automations' => 20,
            'allow_segments' => true, 'max_segments' => 20,
            'max_contacts' => 10000, 'max_lists' => 100, 'max_templates' => 100,
        ])]);
        $user->account->refresh();

        return $user;
    }

    /** One record of every searchable kind, all carrying the same word. */
    protected function seedRecords(User $owner, string $word): void
    {
        $accountId = $owner->account_id;

        Subscriber::factory()->forAccount($owner->account)
            ->create(['email' => "{$word}@example.com", 'first_name' => 'Sam']);

        Campaign::factory()->forAccount($owner->account)
            ->create(['name' => "The {$word} campaign", 'subject' => 'Hello']);

        EmailTemplate::create([
            'account_id' => $accountId, 'user_id' => $owner->id,
            'name' => "{$word} template", 'subject' => 'Hi', 'html' => '<p>Hi</p>',
        ]);

        Automation::create([
            'account_id' => $accountId, 'user_id' => $owner->id,
            'name' => "{$word} sequence", 'trigger_type' => 'subscriber_added', 'status' => 'draft',
        ]);

        SubscriberList::factory()->forAccount($owner->account)->create(['name' => "{$word} list"]);
        Tag::factory()->forAccount($owner->account)->create(['name' => "{$word} tag"]);
        Segment::factory()->forAccount($owner->account)->create(['name' => "{$word} segment"]);
        Suppression::factory()->forAccount($owner->account)->create(['email' => "blocked-{$word}@example.com"]);

        CampaignLog::create([
            'account_id' => $accountId, 'type' => 'campaign', 'status' => 'sent',
            'recipient_email' => "{$word}@example.com", 'subject' => 'Delivered',
        ]);

        $mailbox = Mailbox::withoutGlobalScopes()->where('account_id', $accountId)->first();

        if ($mailbox) {
            Email::create([
                'account_id' => $accountId, 'mailbox_id' => $mailbox->id,
                'direction' => 'incoming', 'folder_type' => 'inbox',
                'message_id' => "<{$word}@example.test>", 'subject' => "About the {$word}",
                'from_email' => 'someone@example.com', 'to' => ['support@search.test'],
                'body_text' => 'Hello', 'received_at' => now()->subDay(),
            ]);
        }
    }

    // ------------------------------------------------------------- the basics

    public function test_one_term_finds_every_kind_of_record(): void
    {
        $response = $this->get('/search?q=quokka')->assertOk();

        foreach ([
            'Contacts', 'Campaigns', 'Inbox messages', 'Email log',
            'Templates', 'Automations', 'Lists', 'Segments', 'Tags', 'Do-not-send list',
        ] as $heading) {
            $response->assertSee($heading);
        }

        $response->assertSee('quokka@example.com')
            ->assertSee('The quokka campaign')
            ->assertSee('quokka sequence');
    }

    public function test_a_term_that_matches_nothing_says_so_and_says_what_was_looked_at(): void
    {
        $this->get('/search?q=wildebeest')
            ->assertOk()
            ->assertSee('Nothing matched')
            ->assertSee('What was searched')
            // The honesty that matters: it does not silently imply message
            // bodies were read.
            ->assertSee('does not read message bodies');
    }

    /**
     * A one-letter search would read every record the account has, to return a
     * list nobody could use. It is refused rather than run.
     */
    public function test_a_term_shorter_than_the_minimum_is_not_run(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) { $queries++; });

        $this->get('/search?q=q')->assertOk()->assertSee('at least 2 characters');

        $this->assertLessThan(12, $queries,
            'A refused search must not still query the ten tables it declined to search.');
    }

    public function test_the_screen_renders_before_anything_has_been_typed(): void
    {
        $this->get('/search')->assertOk()->assertSee('Look across contacts');
    }

    // ------------------------------------------------------------ hostile input

    public function test_hostile_query_strings_do_not_crash_it(): void
    {
        foreach ([
            '/search?q[]=x',
            '/search?q=quokka&in[]=contacts',
            '/search?q=quokka&in=nonsense',
            '/search?q=quokka&from=notadate',
            '/search?q=quokka&from=2026-01-01&to=notadate',
            '/search?q=quokka&from[]=2026-01-01',
            '/search?q='.str_repeat('a', 500),
            '/search?q=%25',
            '/search?q=_',
        ] as $url) {
            $this->get($url)->assertOk("Crashed on {$url}");
        }
    }

    public function test_a_term_made_of_markup_is_escaped(): void
    {
        Campaign::factory()->forAccount($this->owner->account)
            ->create(['name' => '<script>alert(1)</script>', 'subject' => 'Hi']);

        $this->get('/search?q='.urlencode('<script>alert(1)</script>'))
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------- date range

    public function test_the_date_range_narrows_the_results(): void
    {
        // The inbox message arrived yesterday; everything else was created now.
        $this->get('/search?q=quokka&from='.now()->addDay()->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Nothing matched');

        $this->get('/search?q=quokka&to='.now()->subWeek()->format('Y-m-d'))
            ->assertOk()
            ->assertSee('Nothing matched');
    }

    /**
     * A range typed backwards is the commonest way to get an empty result that
     * looks like a bug. Swapping it is what the person meant.
     */
    public function test_a_backwards_date_range_is_swapped_rather_than_returning_nothing(): void
    {
        $this->get('/search?q=quokka&from='.now()->addDay()->format('Y-m-d')
            .'&to='.now()->subWeek()->format('Y-m-d'))
            ->assertOk()
            ->assertSee('quokka@example.com');
    }

    // ------------------------------------------------------------ permissions

    /**
     * A group the role cannot open is never queried — not queried and then
     * filtered. A count of matches behind a locked door is still a leak.
     */
    public function test_a_role_without_a_permission_never_sees_that_group(): void
    {
        $staff = $this->staffWithout(['inbox.view', 'logs.view']);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $response = $this->get('/search?q=quokka')->assertOk();

        // Asserted on the records themselves and on the group's own heading
        // markup, not on its label alone: the label also appears in the form's
        // help text, which is static copy shown to everyone.
        $response->assertDontSee('About the quokka')
            ->assertDontSee('>Inbox messages<', false)
            ->assertDontSee('>Email log<', false)
            // and it says plainly that something was skipped, rather than
            // pretending the search covered everything.
            ->assertSee('your role cannot open');

        // What they may see, they still see.
        $response->assertSee('quokka@example.com');
    }

    public function test_asking_for_a_forbidden_group_directly_returns_nothing_from_it(): void
    {
        $staff = $this->staffWithout(['inbox.view']);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/search?q=quokka&in=messages')
            ->assertOk()
            ->assertDontSee('About the quokka');
    }

    // --------------------------------------------------------------- tenancy

    public function test_another_accounts_records_are_never_returned(): void
    {
        $rival = $this->provision('Rivals Ltd', 'owner@rival.test');

        app(TenantManager::class)->runAs($rival->account_id, function () use ($rival) {
            Mailbox::create([
                'account_id' => $rival->account_id, 'user_id' => $rival->id,
                'name' => 'Theirs', 'email' => 'support@rival.test',
                'imap_host' => 'imap.rival.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
                'imap_username' => 'support@rival.test', 'imap_password' => 'secret',
            ]);

            $this->seedRecords($rival, 'platypus');
        });

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/search?q=platypus')
            ->assertOk()
            ->assertSee('Nothing matched')
            ->assertDontSee('platypus@example.com');
    }

    // -------------------------------------------------------------- shape

    public function test_the_search_does_not_cost_a_query_per_matching_row(): void
    {
        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->get('/search?q=quokka')->assertOk();

            return $n;
        };

        $small = $count();

        for ($i = 0; $i < 20; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "quokka{$i}@example.com"]);
            Campaign::factory()->forAccount($this->owner->account)
                ->create(['name' => "quokka campaign {$i}", 'subject' => 'Hi']);
        }

        $large = $count();

        $this->assertLessThanOrEqual($small + 2, $large,
            "The search cost {$small} queries with a handful of matches and {$large} with forty — "
            .'it must run one query per group, not one per row.');
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param  array<int, string>  $slugs
     */
    protected function staffWithout(array $slugs): User
    {
        $role = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();

        $role->permissions()->detach(
            Permission::whereIn('slug', $slugs)->pluck('id')->all()
        );

        return User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => $role->id,
            'email_verified_at' => now(),
        ]);
    }

    /**
     * `%` and `_` are LIKE's own operators. Before they were escaped, searching
     * for a single `%` returned every record in every table, and an address
     * containing an underscore returned a list with nothing to do with it —
     * with nothing on the screen able to explain why.
     */
    public function test_like_wildcards_are_treated_as_text_not_as_operators(): void
    {
        $this->get('/search?q=%25%25')
            ->assertOk()
            ->assertSee('Nothing matched')
            ->assertDontSee('quokka@example.com');

        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'a_b@example.com']);
        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'axb@example.com']);

        $this->get('/search?q=a_b')
            ->assertOk()
            ->assertSee('a_b@example.com')
            ->assertDontSee('axb@example.com');
    }
}
