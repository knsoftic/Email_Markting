<?php

namespace Tests\Feature\Contacts;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomField;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Contacts\ListService;
use App\Services\Contacts\SegmentCompiler;
use App\Services\Contacts\SegmentFieldRegistry;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The segment engine decides who receives a campaign, so a wrong result here
 * is a wrong send. These tests pin down the behaviour that is easy to get
 * subtly wrong: negative conditions, OR grouping, and injection safety.
 */
class SegmentCompilerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SegmentCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Segments Ltd', 'name' => 'Owner', 'email' => 'owner@segments.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->compiler = app(SegmentCompiler::class);
    }

    protected function sub(array $attributes = []): Subscriber
    {
        return Subscriber::factory()->forAccount($this->owner->account)->create($attributes);
    }

    protected function emails(array $rules, string $match = 'all'): array
    {
        return $this->compiler->compile($rules, $match)->pluck('email')->sort()->values()->all();
    }

    // ------------------------------------------------------ simple fields

    public function test_text_operators_behave_as_labelled(): void
    {
        $this->sub(['email' => 'alice@acme.com', 'company' => 'Acme']);
        $this->sub(['email' => 'bob@other.com', 'company' => 'Other']);
        $this->sub(['email' => 'carol@acme.com', 'company' => null]);

        $this->assertSame(
            ['alice@acme.com', 'carol@acme.com'],
            $this->emails([['field' => 'email', 'operator' => 'contains', 'value' => 'acme.com']])
        );

        $this->assertSame(
            ['alice@acme.com'],
            $this->emails([['field' => 'company', 'operator' => 'is', 'value' => 'Acme']])
        );

        // is_not must also return rows where the column is NULL — otherwise
        // "company is not Acme" silently hides every contact with no company.
        $this->assertSame(
            ['bob@other.com', 'carol@acme.com'],
            $this->emails([['field' => 'company', 'operator' => 'is_not', 'value' => 'Acme']])
        );

        $this->assertSame(
            ['carol@acme.com'],
            $this->emails([['field' => 'company', 'operator' => 'is_not_set', 'value' => null]])
        );
    }

    public function test_a_percent_sign_in_a_value_is_not_treated_as_a_wildcard(): void
    {
        $this->sub(['email' => 'literal%match@example.com']);
        $this->sub(['email' => 'anything@example.com']);

        $this->assertSame(
            ['literal%match@example.com'],
            $this->emails([['field' => 'email', 'operator' => 'contains', 'value' => 'literal%match']])
        );

        // A bare % must not match everything.
        $this->assertSame(
            [],
            $this->emails([['field' => 'email', 'operator' => 'is', 'value' => '%']])
        );
    }

    public function test_status_and_date_filters(): void
    {
        $this->sub(['email' => 'new@example.com', 'created_at' => now()->subDay()]);
        $this->sub(['email' => 'old@example.com', 'created_at' => now()->subDays(60)]);
        $this->sub(['email' => 'gone@example.com', 'status' => 'unsubscribed', 'created_at' => now()->subDay()]);

        $this->assertSame(
            ['gone@example.com'],
            $this->emails([['field' => 'status', 'operator' => 'is', 'value' => 'unsubscribed']])
        );

        $this->assertSame(
            ['new@example.com', 'old@example.com'],
            $this->emails([['field' => 'status', 'operator' => 'is_not', 'value' => 'unsubscribed']])
        );

        $this->assertSame(
            ['gone@example.com', 'new@example.com'],
            $this->emails([['field' => 'created_at', 'operator' => 'in_last_days', 'value' => 7]])
        );

        $this->assertSame(
            ['old@example.com'],
            $this->emails([['field' => 'created_at', 'operator' => 'not_in_last_days', 'value' => 7]])
        );
    }

    // ---------------------------------------------------------- relations

    public function test_list_membership_including_the_negative_case(): void
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        $inList = $this->sub(['email' => 'member@example.com']);
        $this->sub(['email' => 'outsider@example.com']);

        app(ListService::class)->attach([$inList->id], [$list->id], $this->owner->account_id);

        $this->assertSame(
            ['member@example.com'],
            $this->emails([['field' => 'list', 'operator' => 'is', 'value' => $list->id]])
        );

        $this->assertSame(
            ['outsider@example.com'],
            $this->emails([['field' => 'list', 'operator' => 'is_not', 'value' => $list->id]])
        );
    }

    public function test_tag_membership_including_the_negative_case(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create();

        $tagged = $this->sub(['email' => 'tagged@example.com']);
        $this->sub(['email' => 'untagged@example.com']);
        $tagged->tags()->attach($tag->id);

        $this->assertSame(['tagged@example.com'],
            $this->emails([['field' => 'tag', 'operator' => 'is', 'value' => $tag->id]]));

        $this->assertSame(['untagged@example.com'],
            $this->emails([['field' => 'tag', 'operator' => 'is_not', 'value' => $tag->id]]));
    }

    public function test_opened_and_not_opened_a_campaign(): void
    {
        $campaign = Campaign::factory()->forAccount($this->owner->account)->completed()->create();

        $opener = $this->sub(['email' => 'opener@example.com']);
        $silent = $this->sub(['email' => 'silent@example.com']);
        $this->sub(['email' => 'never-sent@example.com']);

        CampaignRecipient::factory()->opened()->create([
            'campaign_id' => $campaign->id, 'subscriber_id' => $opener->id, 'email' => $opener->email,
        ]);
        CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id, 'subscriber_id' => $silent->id, 'email' => $silent->email,
        ]);

        $this->assertSame(['opener@example.com'],
            $this->emails([['field' => 'campaign_opened', 'operator' => 'is', 'value' => $campaign->id]]));

        // "Did not open" must include people who were never sent it at all.
        $this->assertSame(
            ['never-sent@example.com', 'silent@example.com'],
            $this->emails([['field' => 'campaign_opened', 'operator' => 'is_not', 'value' => $campaign->id]])
        );

        $this->assertSame(
            ['opener@example.com', 'silent@example.com'],
            $this->emails([['field' => 'campaign_received', 'operator' => 'is', 'value' => $campaign->id]])
        );
    }

    public function test_clicked_a_link_in_any_campaign(): void
    {
        $campaign = Campaign::factory()->forAccount($this->owner->account)->completed()->create();

        $clicker = $this->sub(['email' => 'clicker@example.com']);
        $this->sub(['email' => 'lurker@example.com']);

        CampaignRecipient::factory()->opened()->clicked()->create([
            'campaign_id' => $campaign->id, 'subscriber_id' => $clicker->id, 'email' => $clicker->email,
        ]);

        $this->assertSame(['clicker@example.com'],
            $this->emails([['field' => 'campaign_clicked', 'operator' => 'is', 'value' => 'any']]));

        $this->assertSame(['lurker@example.com'],
            $this->emails([['field' => 'campaign_clicked', 'operator' => 'is_not', 'value' => 'any']]));
    }

    public function test_a_negative_engagement_filter_uses_not_exists_not_a_huge_id_list(): void
    {
        $campaign = Campaign::factory()->forAccount($this->owner->account)->completed()->create();

        $sql = strtolower($this->compiler->compile([
            ['field' => 'campaign_opened', 'operator' => 'is_not', 'value' => $campaign->id],
        ])->toSql());

        $this->assertStringContainsString('not exists', $sql);
        $this->assertStringNotContainsString('not in (', $sql);
    }

    public function test_the_suppression_filter(): void
    {
        $this->sub(['email' => 'clean@example.com']);
        $this->sub(['email' => 'blocked@example.com']);
        Suppression::factory()->forAccount($this->owner->account)->create(['email' => 'blocked@example.com']);

        $this->assertSame(['blocked@example.com'],
            $this->emails([['field' => 'suppressed', 'operator' => 'is', 'value' => '1']]));

        $this->assertSame(['clean@example.com'],
            $this->emails([['field' => 'suppressed', 'operator' => 'is', 'value' => '0']]));
    }

    // ------------------------------------------------------- combinations

    public function test_match_all_versus_match_any(): void
    {
        $this->sub(['email' => 'both@example.com', 'country' => 'Pakistan', 'city' => 'Lahore']);
        $this->sub(['email' => 'country-only@example.com', 'country' => 'Pakistan', 'city' => 'Karachi']);
        $this->sub(['email' => 'city-only@example.com', 'country' => 'India', 'city' => 'Lahore']);
        $this->sub(['email' => 'neither@example.com', 'country' => 'France', 'city' => 'Paris']);

        $rules = [
            ['field' => 'country', 'operator' => 'is', 'value' => 'Pakistan'],
            ['field' => 'city', 'operator' => 'is', 'value' => 'Lahore'],
        ];

        $this->assertSame(['both@example.com'], $this->emails($rules, 'all'));

        $this->assertSame(
            ['both@example.com', 'city-only@example.com', 'country-only@example.com'],
            $this->emails($rules, 'any')
        );
    }

    public function test_an_or_group_does_not_leak_past_a_base_query(): void
    {
        $this->sub(['email' => 'active-pk@example.com', 'country' => 'Pakistan']);
        $this->sub(['email' => 'unsub-pk@example.com', 'country' => 'Pakistan', 'status' => 'unsubscribed']);
        $this->sub(['email' => 'active-fr@example.com', 'country' => 'France']);

        // Base query = active only; segment = country is PK OR country is FR.
        $result = $this->compiler->compile(
            [
                ['field' => 'country', 'operator' => 'is', 'value' => 'Pakistan'],
                ['field' => 'country', 'operator' => 'is', 'value' => 'France'],
            ],
            'any',
            Subscriber::query()->where('status', 'active')
        )->pluck('email')->sort()->values()->all();

        $this->assertSame(
            ['active-fr@example.com', 'active-pk@example.com'],
            $result,
            'An OR rule set must stay wrapped so it cannot widen the base query.'
        );
    }

    // ------------------------------------------------------ custom fields

    public function test_custom_field_filtering_works_on_mariadb_json(): void
    {
        CustomField::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tier', 'key' => 'tier', 'type' => 'text',
        ]);

        $this->sub(['email' => 'gold@example.com', 'custom' => ['tier' => 'gold']]);
        $this->sub(['email' => 'silver@example.com', 'custom' => ['tier' => 'silver']]);
        $this->sub(['email' => 'none@example.com']);

        // The registry is resolved lazily; a fresh instance picks up the field.
        $compiler = new SegmentCompiler(new SegmentFieldRegistry);

        $this->assertSame(
            ['gold@example.com'],
            $compiler->compile([['field' => 'custom:tier', 'operator' => 'is', 'value' => 'gold']])
                ->pluck('email')->sort()->values()->all()
        );
    }

    // -------------------------------------------------------- safety net

    public function test_unknown_fields_and_operators_are_dropped_not_executed(): void
    {
        $this->sub(['email' => 'a@example.com']);
        $this->sub(['email' => 'b@example.com']);

        $preview = $this->compiler->preview([
            ['field' => 'subscribers.email; DROP TABLE users; --', 'operator' => 'is', 'value' => 'x'],
            ['field' => 'email', 'operator' => 'DROP', 'value' => 'x'],
            ['field' => 'email', 'operator' => 'is', 'value' => 'a@example.com'],
        ]);

        $this->assertSame(1, $preview['count'], 'Only the one valid rule may apply.');
        $this->assertSame(1, $preview['rules_applied']);
        $this->assertCount(2, $preview['rules_ignored'], 'Dropped rules must be reported, not hidden.');

        // The database is still there.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('users'));
    }

    public function test_an_empty_rule_set_matches_everyone_rather_than_erroring(): void
    {
        $this->sub();
        $this->sub();

        $this->assertSame(2, $this->compiler->count([]));
    }

    public function test_mailable_segment_excludes_suppressed_and_inactive(): void
    {
        $segment = Segment::factory()->forAccount($this->owner->account)->withRules([
            ['field' => 'country', 'operator' => 'is', 'value' => 'Pakistan'],
        ])->create();

        $this->sub(['email' => 'ok@example.com', 'country' => 'Pakistan']);
        $this->sub(['email' => 'unsub@example.com', 'country' => 'Pakistan', 'status' => 'unsubscribed']);
        $this->sub(['email' => 'suppressed@example.com', 'country' => 'Pakistan']);
        Suppression::factory()->forAccount($this->owner->account)->create(['email' => 'suppressed@example.com']);

        $this->assertSame(3, $this->compiler->forSegment($segment)->count());
        $this->assertSame(
            ['ok@example.com'],
            $this->compiler->mailableForSegment($segment)->pluck('email')->all()
        );
    }

    public function test_refresh_count_caches_the_size(): void
    {
        $segment = Segment::factory()->forAccount($this->owner->account)->withRules([
            ['field' => 'status', 'operator' => 'is', 'value' => 'active'],
        ])->create();

        $this->sub();
        $this->sub();
        $this->sub(['status' => 'bounced']);

        $count = $this->compiler->refreshCount($segment);

        $this->assertSame(2, $count);
        $this->assertSame(2, $segment->fresh()->cached_count);
        $this->assertNotNull($segment->fresh()->last_calculated_at);
    }

    public function test_a_segment_never_sees_another_accounts_contacts(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Other Ltd', 'name' => 'Other', 'email' => 'other@segments.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        Subscriber::factory()->forAccount($other->account)->count(5)
            ->create(['country' => 'Pakistan']);

        $this->sub(['email' => 'mine@example.com', 'country' => 'Pakistan']);

        $this->assertSame(
            ['mine@example.com'],
            $this->emails([['field' => 'country', 'operator' => 'is', 'value' => 'Pakistan']])
        );
    }
}
