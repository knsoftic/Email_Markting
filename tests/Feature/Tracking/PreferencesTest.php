<?php

namespace Tests\Feature\Tracking;

use App\Models\Campaign;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The preferences page.
 *
 * It exists because the footer offers two links and, until now, both did the
 * same thing. Someone who only wants out of the weekly promo should not have
 * to choose between that and hearing from the sender ever again.
 */
class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Subscriber $contact;

    protected SubscriberList $news;

    protected SubscriberList $offers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@prefs.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        app(TenantManager::class)->set($this->owner->account_id);

        $this->news = SubscriberList::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Monthly newsletter']);
        $this->offers = SubscriberList::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Special offers']);

        $this->contact = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'reader@example.com']);

        $this->contact->lists()->attach([
            $this->news->id => ['subscribed_at' => now()],
            $this->offers->id => ['subscribed_at' => now()],
        ]);
    }

    protected function url(): string
    {
        return URL::signedRoute('preferences.show', ['subscriber' => $this->contact->id]);
    }

    // ------------------------------------------------------------ rendering

    public function test_the_page_lists_the_contacts_own_lists(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSee('Monthly newsletter')
            ->assertSee('Special offers')
            ->assertSee('Save my preferences');
    }

    public function test_it_does_not_reveal_lists_the_contact_is_not_on(): void
    {
        SubscriberList::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Internal VIP prospects']);

        $this->get($this->url())
            ->assertOk()
            ->assertDontSee('Internal VIP prospects',
                'A recipient must not learn the names of lists they were never on.');
    }

    public function test_an_unsigned_url_is_refused(): void
    {
        $this->get("/preferences/{$this->contact->id}")->assertForbidden();
    }

    public function test_it_is_a_distinct_page_from_the_unsubscribe_confirmation(): void
    {
        $this->get($this->url())->assertOk()->assertSee('Your email preferences');

        $this->get(URL::signedRoute('unsubscribe.show', ['subscriber' => $this->contact->id]))
            ->assertOk()
            ->assertSee('Unsubscribe from');
    }

    // -------------------------------------------------------------- saving

    public function test_leaving_one_list_keeps_the_other(): void
    {
        $this->post($this->url(), ['lists' => [$this->news->id]])
            ->assertOk()
            ->assertSee('Saved');

        $remaining = $this->contact->fresh()->lists()->pluck('subscriber_lists.id')->all();

        $this->assertSame([$this->news->id], $remaining);
        $this->assertSame(0, Suppression::withoutGlobalScopes()->count(),
            'Leaving one list is not unsubscribing from the sender.');
        $this->assertSame('active', $this->contact->fresh()->status);
    }

    public function test_leaving_every_list_is_recorded_as_a_full_opt_out(): void
    {
        $this->post($this->url(), ['lists' => []])
            ->assertOk()
            ->assertSee('that stops everything');

        $this->assertSame(0, $this->contact->fresh()->lists()->count());

        $suppression = Suppression::withoutGlobalScopes()->where('email', 'reader@example.com')->first();

        $this->assertNotNull($suppression,
            'On no lists but not suppressed is a trap: a campaign aimed at everyone would still reach them.');
        $this->assertSame('unsubscribed', $this->contact->fresh()->status);
    }

    public function test_a_posted_id_for_a_list_they_were_never_on_does_not_subscribe_them(): void
    {
        $other = SubscriberList::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Not theirs']);

        $this->post($this->url(), ['lists' => [$this->news->id, $other->id]])->assertOk();

        $remaining = $this->contact->fresh()->lists()->pluck('subscriber_lists.id')->all();

        $this->assertNotContains($other->id, $remaining,
            'The form is a leave-only control; it must never add a subscription.');
    }

    public function test_a_malformed_post_does_not_crash_or_opt_anybody_out_by_accident(): void
    {
        // lists[] arriving as a nested array, which no UI produces.
        $this->post($this->url(), ['lists' => [['x']]])->assertOk();

        // Nothing valid was kept, so it is the same as unticking everything —
        // and that path is the recorded opt-out, not a silent no-op.
        $this->assertSame(0, $this->contact->fresh()->lists()->count());
    }

    public function test_the_list_counts_are_updated_after_someone_leaves(): void
    {
        $this->news->refreshCounts();
        $this->assertSame(1, (int) $this->news->fresh()->active_count);

        $this->post($this->url(), ['lists' => [$this->offers->id]])->assertOk();

        $this->assertSame(0, (int) $this->news->fresh()->active_count,
            'A stale count makes every audience estimate wrong.');
    }

    // -------------------------------------------------------------- tenancy

    public function test_the_page_works_with_no_tenant_bound(): void
    {
        $url = $this->url();

        app(TenantManager::class)->forget();

        $this->get($url)->assertOk()->assertSee('Monthly newsletter');
    }

    public function test_an_already_unsubscribed_contact_is_told_so(): void
    {
        $this->post(URL::signedRoute('unsubscribe.confirm', ['subscriber' => $this->contact->id]))
            ->assertOk();

        $this->get($this->url())
            ->assertOk()
            ->assertSee('You are unsubscribed')
            ->assertDontSee('Save my preferences');
    }

    public function test_a_campaign_scoped_link_still_works(): void
    {
        $campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'March offers', 'subject' => 'Hi', 'status' => 'completed',
        ]);

        $this->get(URL::signedRoute('preferences.show', [
            'subscriber' => $this->contact->id, 'campaign' => $campaign->id,
        ]))->assertOk()->assertSee('Monthly newsletter');
    }
}
