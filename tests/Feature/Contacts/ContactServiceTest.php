<?php

namespace Tests\Feature\Contacts;

use App\Exceptions\PlanLimitException;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Contacts\ListService;
use App\Services\Contacts\SubscriberService;
use App\Services\Contacts\SuppressionService;
use App\Support\PlanLimits;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the contact-writing rules that must hold no matter which screen or
 * job triggers them.
 */
class ContactServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerA;

    protected User $ownerB;

    protected SubscriberService $service;

    protected SuppressionService $suppressions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $provisioner = app(AccountProvisioner::class);

        $this->ownerA = $provisioner->provision([
            'company_name' => 'Contacts A', 'name' => 'Owner A', 'email' => 'a@contacts.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->ownerB = $provisioner->provision([
            'company_name' => 'Contacts B', 'name' => 'Owner B', 'email' => 'b@contacts.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        foreach ([$this->ownerA, $this->ownerB] as $owner) {
            $owner->forceFill(['email_verified_at' => now()])->save();
        }

        $this->actAsOwnerA();

        $this->service = app(SubscriberService::class);
        $this->suppressions = app(SuppressionService::class);
    }

    protected function actAsOwnerA(): void
    {
        $this->actingAs($this->ownerA);
        app(TenantManager::class)->set($this->ownerA->account_id);
    }

    protected function actAsOwnerB(): void
    {
        $this->actingAs($this->ownerB);
        app(TenantManager::class)->set($this->ownerB->account_id);
    }

    // --------------------------------------------------------------- create

    public function test_creating_a_contact_stamps_the_account_and_consent_trail(): void
    {
        $subscriber = $this->service->create([
            'email' => '  Jane.Doe@Example.COM ',
            'name' => 'Jane Doe',
            'status' => 'active',
            'consent_status' => 'explicit',
        ]);

        $this->assertSame($this->ownerA->account_id, $subscriber->account_id);
        $this->assertSame('jane.doe@example.com', $subscriber->email, 'Email must be normalised.');
        $this->assertSame('Jane', $subscriber->first_name);
        $this->assertSame('Doe', $subscriber->last_name);
        $this->assertNotNull($subscriber->consent_at, 'Explicit consent must be timestamped.');
        $this->assertNotNull($subscriber->subscribed_at);
        $this->assertSame('manual', $subscriber->source);
    }

    public function test_a_suppressed_address_can_never_be_created_as_active(): void
    {
        $this->suppressions->suppress('blocked@example.com', 'unsubscribed');

        $subscriber = $this->service->create([
            'email' => 'blocked@example.com',
            'name' => 'Should Not Be Active',
            'status' => 'active',
            'consent_status' => 'explicit',
        ]);

        $this->assertSame(
            'unsubscribed',
            $subscriber->status,
            'Adding a name must never undo an existing opt-out.'
        );
    }

    public function test_the_contact_plan_limit_is_enforced(): void
    {
        $this->ownerA->account->subscription->update(['overrides' => ['max_contacts' => 2]]);

        $this->service->create(['email' => 'one@example.com', 'status' => 'active', 'consent_status' => 'unknown']);
        $this->service->create(['email' => 'two@example.com', 'status' => 'active', 'consent_status' => 'unknown']);

        $this->expectException(PlanLimitException::class);

        $this->service->create(['email' => 'three@example.com', 'status' => 'active', 'consent_status' => 'unknown']);
    }

    public function test_creating_a_contact_increments_the_monthly_usage_counter(): void
    {
        $before = PlanLimits::for($this->ownerA->account)->counter()->contacts_added;

        $this->service->create(['email' => 'counted@example.com', 'status' => 'active', 'consent_status' => 'unknown']);

        $after = PlanLimits::for($this->ownerA->account)->counter()->contacts_added;

        $this->assertSame($before + 1, $after);
    }

    // ---------------------------------------------------------------- lists

    public function test_list_counters_track_membership_and_status(): void
    {
        $list = SubscriberList::factory()->forAccount($this->ownerA->account)->create();

        $active = Subscriber::factory()->forAccount($this->ownerA->account)->count(3)->create();
        $unsub = Subscriber::factory()->forAccount($this->ownerA->account)->unsubscribed()->create();

        app(ListService::class)->attach(
            $active->pluck('id')->push($unsub->id),
            [$list->id],
            $this->ownerA->account_id
        );

        $list->refresh();

        $this->assertSame(4, $list->total_count);
        $this->assertSame(3, $list->active_count);
        $this->assertSame(1, $list->unsubscribed_count);

        // Detaching one active member must bring both counters down.
        app(ListService::class)->detach([$active->first()->id], [$list->id], $this->ownerA->account_id);

        $list->refresh();

        $this->assertSame(3, $list->total_count);
        $this->assertSame(2, $list->active_count);
    }

    public function test_attaching_the_same_contact_twice_does_not_duplicate_membership(): void
    {
        $list = SubscriberList::factory()->forAccount($this->ownerA->account)->create();
        $subscriber = Subscriber::factory()->forAccount($this->ownerA->account)->create();

        $service = app(ListService::class);
        $service->attach([$subscriber->id], [$list->id], $this->ownerA->account_id);
        $service->attach([$subscriber->id], [$list->id], $this->ownerA->account_id);

        $this->assertSame(1, DB::table('list_subscriber')
            ->where('subscriber_list_id', $list->id)
            ->where('subscriber_id', $subscriber->id)
            ->count());

        $this->assertSame(1, $list->fresh()->total_count);
    }

    // ----------------------------------------------------------------- bulk

    public function test_bulk_tagging_and_untagging_keeps_the_tag_counter_correct(): void
    {
        $tag = Tag::factory()->forAccount($this->ownerA->account)->create();
        $subscribers = Subscriber::factory()->forAccount($this->ownerA->account)->count(4)->create();

        $result = $this->service->bulk('add_tags', $subscribers->pluck('id')->all(), ['tag_ids' => [$tag->id]]);

        $this->assertSame(4, $result['affected']);
        $this->assertSame(4, $tag->fresh()->subscribers_count);

        $this->service->bulk('remove_tags', $subscribers->take(2)->pluck('id')->all(), ['tag_ids' => [$tag->id]]);

        $this->assertSame(2, $tag->fresh()->subscribers_count);
    }

    public function test_bulk_unsubscribe_also_suppresses_the_addresses(): void
    {
        $subscribers = Subscriber::factory()->forAccount($this->ownerA->account)->count(2)->create();

        $this->service->bulk('unsubscribe', $subscribers->pluck('id')->all());

        foreach ($subscribers as $subscriber) {
            $this->assertTrue(
                $this->suppressions->isSuppressed($subscriber->email),
                'Unsubscribing must add the address to the suppression list.'
            );
            $this->assertSame('unsubscribed', $subscriber->fresh()->status);
        }
    }

    public function test_a_bulk_action_cannot_reach_another_accounts_contacts(): void
    {
        $foreign = Subscriber::factory()->forAccount($this->ownerB->account)->count(3)->create();

        // Owner A posts account B's ids.
        $result = $this->service->bulk('delete', $foreign->pluck('id')->all());

        $this->assertSame(0, $result['affected']);
        $this->assertSame(3, Subscriber::withoutGlobalScopes()
            ->where('account_id', $this->ownerB->account_id)->count());
    }

    public function test_an_unknown_bulk_action_is_rejected(): void
    {
        $subscriber = Subscriber::factory()->forAccount($this->ownerA->account)->create();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->bulk('drop_table', [$subscriber->id]);
    }

    // ---------------------------------------------------------- suppression

    public function test_suppressing_marks_the_matching_contact(): void
    {
        $subscriber = Subscriber::factory()->forAccount($this->ownerA->account)->create([
            'email' => 'bouncer@example.com',
        ]);

        $this->suppressions->suppress('bouncer@example.com', 'hard_bounce');

        $this->assertSame('bounced', $subscriber->fresh()->status);
    }

    public function test_releasing_a_suppression_does_not_reactivate_the_contact(): void
    {
        $subscriber = Subscriber::factory()->forAccount($this->ownerA->account)->create([
            'email' => 'optout@example.com',
        ]);

        $this->suppressions->suppress('optout@example.com', 'unsubscribed');
        $this->assertSame('unsubscribed', $subscriber->fresh()->status);

        $this->suppressions->release('optout@example.com');

        $this->assertFalse($this->suppressions->isSuppressed('optout@example.com'));
        $this->assertSame(
            'unsubscribed',
            $subscriber->fresh()->status,
            'Re-consent is a deliberate act, never a side effect of tidying the list.'
        );
    }

    public function test_suppression_is_per_account(): void
    {
        $this->suppressions->suppress('shared@example.com', 'manual');

        $this->assertTrue($this->suppressions->isSuppressed('shared@example.com'));

        $this->actAsOwnerB();

        $this->assertFalse(
            app(SuppressionService::class)->isSuppressed('shared@example.com'),
            'One account suppressing an address must not block it for another.'
        );
    }

    public function test_the_suppressed_map_lookup_matches_single_checks(): void
    {
        $this->suppressions->suppressMany(['a@example.com', 'b@example.com'], 'import');

        $map = $this->suppressions->suppressedMap([
            'A@Example.com', 'b@example.com', 'c@example.com',
        ]);

        $this->assertArrayHasKey('a@example.com', $map);
        $this->assertArrayHasKey('b@example.com', $map);
        $this->assertArrayNotHasKey('c@example.com', $map);
    }

    public function test_mailable_scope_excludes_suppressed_and_inactive_contacts(): void
    {
        Subscriber::factory()->forAccount($this->ownerA->account)->create(['email' => 'ok@example.com']);
        Subscriber::factory()->forAccount($this->ownerA->account)->unsubscribed()->create(['email' => 'gone@example.com']);
        Subscriber::factory()->forAccount($this->ownerA->account)->create(['email' => 'suppressed@example.com']);

        Suppression::factory()->forAccount($this->ownerA->account)->create(['email' => 'suppressed@example.com']);

        $mailable = Subscriber::query()->mailable()->pluck('email');

        $this->assertContains('ok@example.com', $mailable->all());
        $this->assertNotContains('gone@example.com', $mailable->all());
        $this->assertNotContains('suppressed@example.com', $mailable->all());
    }

    // -------------------------------------------------------------- filters

    public function test_the_index_filter_narrows_by_list_and_tag_without_loading_ids(): void
    {
        $list = SubscriberList::factory()->forAccount($this->ownerA->account)->create();
        $tag = Tag::factory()->forAccount($this->ownerA->account)->create();

        $inList = Subscriber::factory()->forAccount($this->ownerA->account)->create();
        $tagged = Subscriber::factory()->forAccount($this->ownerA->account)->create();
        Subscriber::factory()->forAccount($this->ownerA->account)->create();

        app(ListService::class)->attach([$inList->id], [$list->id], $this->ownerA->account_id);
        $tagged->tags()->attach($tag->id);

        $this->assertSame([$inList->id], $this->service->filtered(['list_id' => $list->id])->pluck('id')->all());
        $this->assertSame([$tagged->id], $this->service->filtered(['tag_id' => $tag->id])->pluck('id')->all());

        $sql = $this->service->filtered(['list_id' => $list->id])->toSql();
        $this->assertStringContainsString('exists', strtolower($sql), 'List filtering must use an EXISTS subquery.');
    }

    public function test_status_counts_cover_every_status(): void
    {
        Subscriber::factory()->forAccount($this->ownerA->account)->count(2)->create();
        Subscriber::factory()->forAccount($this->ownerA->account)->unsubscribed()->create();

        $counts = $this->service->statusCounts();

        $this->assertSame(2, $counts['active']);
        $this->assertSame(1, $counts['unsubscribed']);
        $this->assertSame(0, $counts['blocked'], 'Unused statuses must still be present as zero.');
    }
}
