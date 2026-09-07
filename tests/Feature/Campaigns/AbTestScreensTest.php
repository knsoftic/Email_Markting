<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignVariant;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The split-test screens (spec 10.4).
 *
 * The service underneath is already covered by AbTestTest. What is tested here
 * is the promise the screens make: that a version with recipients cannot be
 * deleted, that a test which has not been decided never shows a winner, and
 * that the blockers the dispatcher raises actually reach the send screen.
 */
class AbTestScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SubscriberList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Split Ltd', 'name' => 'Owner', 'email' => 'owner@split.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'max_emails_per_month' => 100000, 'max_contacts' => 100000,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Relay']);

        $this->list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Main list']);

        for ($i = 1; $i <= 6; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "reader{$i}@example.com", 'status' => 'active'])
                ->lists()->attach($this->list->id, ['subscribed_at' => now()]);
        }
    }

    protected function make(array $overrides = []): Campaign
    {
        $document = ['settings' => [], 'blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
            ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Split Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        return Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Spring push',
            'subject' => 'Control subject',
            'from_name' => 'Split Ltd',
            'from_email' => 'hello@split.test',
            'blocks' => $document,
            'html' => $compiler->compile($document),
            'plain_text' => $compiler->compileText($document),
            'audience' => ['lists' => [$this->list->id]],
            'timezone' => 'UTC',
            'status' => 'draft',
        ], $overrides));
    }

    protected function variant(Campaign $campaign, string $label, array $overrides = []): CampaignVariant
    {
        return CampaignVariant::create(array_merge([
            'campaign_id' => $campaign->id,
            'label' => $label,
            'subject' => "Version {$label} subject",
            'share_percent' => 50,
        ], $overrides));
    }

    /**
     * @param  array<int, array<string, mixed>>  $variants
     * @return array<string, mixed>
     */
    protected function payload(array $variants, array $overrides = []): array
    {
        return array_merge([
            'is_ab_test' => '1',
            'ab_test_type' => 'subject',
            'ab_sample_percent' => 20,
            'ab_winner_metric' => 'opens',
            'ab_decide_after_minutes' => 240,
            'variants' => $variants,
        ], $overrides);
    }

    // ------------------------------------------------------------- the editor

    public function test_the_editor_shows_the_split_test_section_and_explains_the_holdback(): void
    {
        $campaign = $this->make();

        $this->get(route('campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('Split test')
            ->assertSee('held back', false)
            ->assertSee('Share of the audience used for the test');
    }

    public function test_the_editor_seeds_the_saved_versions_into_the_form(): void
    {
        $campaign = $this->make(['is_ab_test' => true, 'ab_test_type' => 'subject']);
        $this->variant($campaign, 'A', ['subject' => 'Seeded subject A']);
        $this->variant($campaign, 'B', ['subject' => 'Seeded subject B']);

        $this->get(route('campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('Seeded subject A', false)
            ->assertSee('Seeded subject B', false)
            ->assertSee('enabled: true', false);
    }

    public function test_a_campaign_that_does_not_exist_yet_says_to_save_the_draft_first(): void
    {
        $this->get(route('campaigns.create'))
            ->assertOk()
            ->assertSee('Save this campaign as a draft first');
    }

    public function test_the_editor_hands_the_screen_the_real_audience_size(): void
    {
        $campaign = $this->make();

        // Six mailable contacts are on the list, so the arithmetic the screen
        // does for the sample and the holdback has a real number behind it.
        $this->get(route('campaigns.edit', $campaign))
            ->assertOk()
            ->assertSee('reach: 6', false);
    }

    public function test_the_editor_survives_scrambled_old_input_after_a_rejected_save(): void
    {
        $campaign = $this->make();

        // variants[0][subject][] = x is a text field posted as an array. It must
        // be rejected, and the redisplay must not be a 500 on the one response
        // carrying the user's work.
        $response = $this->from(route('campaigns.edit', $campaign))
            ->put(route('campaigns.ab.update', $campaign), $this->payload([
                ['label' => 'A', 'subject' => ['nested'], 'share_percent' => 50],
            ]));

        $response->assertRedirect(route('campaigns.edit', $campaign));

        $this->followingRedirects()
            ->from(route('campaigns.edit', $campaign))
            ->put(route('campaigns.ab.update', $campaign), $this->payload([
                ['label' => 'A', 'subject' => ['nested'], 'share_percent' => 50],
            ]))
            ->assertOk()
            ->assertSee('Split test');
    }

    public function test_a_non_array_variants_payload_does_not_500(): void
    {
        $campaign = $this->make();

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([], ['variants' => 'nonsense']))
            ->assertSessionHasErrors('variants');

        $this->assertSame(0, CampaignVariant::where('campaign_id', $campaign->id)->count());
    }

    // -------------------------------------------------------------- saving

    public function test_it_saves_the_settings_and_creates_the_versions(): void
    {
        $campaign = $this->make();

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'subject' => 'Open me', 'share_percent' => 60],
            ['label' => 'B', 'subject' => 'Or open me', 'share_percent' => 40],
        ], ['ab_sample_percent' => 30, 'ab_winner_metric' => 'clicks', 'ab_decide_after_minutes' => 120]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $campaign->refresh();

        $this->assertTrue($campaign->is_ab_test);
        $this->assertSame('subject', $campaign->ab_test_type);
        $this->assertSame(30, (int) $campaign->ab_sample_percent);
        $this->assertSame('clicks', $campaign->ab_winner_metric);
        $this->assertSame(120, (int) $campaign->ab_decide_after_minutes);

        $variants = CampaignVariant::where('campaign_id', $campaign->id)->orderBy('label')->get();

        $this->assertCount(2, $variants);
        $this->assertSame('Open me', $variants[0]->subject);
        $this->assertSame(60, (int) $variants[0]->share_percent);
        $this->assertSame(40, (int) $variants[1]->share_percent);
    }

    public function test_switching_the_test_off_keeps_the_versions(): void
    {
        $campaign = $this->make(['is_ab_test' => true, 'ab_test_type' => 'subject']);
        $a = $this->variant($campaign, 'A');
        $b = $this->variant($campaign, 'B');

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['id' => $a->id, 'label' => 'A', 'subject' => 'A', 'share_percent' => 50],
            ['id' => $b->id, 'label' => 'B', 'subject' => 'B', 'share_percent' => 50],
        ], ['is_ab_test' => '0']))->assertSessionHasNoErrors();

        $this->assertFalse($campaign->fresh()->is_ab_test);
        $this->assertSame(2, CampaignVariant::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_version_left_out_of_the_post_is_removed(): void
    {
        $campaign = $this->make(['is_ab_test' => true, 'ab_test_type' => 'subject']);
        $a = $this->variant($campaign, 'A');
        $b = $this->variant($campaign, 'B');
        $c = $this->variant($campaign, 'C');

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['id' => $a->id, 'label' => 'A', 'subject' => 'A', 'share_percent' => 50],
            ['id' => $b->id, 'label' => 'B', 'subject' => 'B', 'share_percent' => 50],
        ]))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('campaign_variants', ['id' => $c->id]);
        $this->assertSame(2, CampaignVariant::where('campaign_id', $campaign->id)->count());
    }

    public function test_a_version_with_recipients_assigned_cannot_be_removed(): void
    {
        $campaign = $this->make(['status' => 'paused', 'is_ab_test' => true, 'ab_test_type' => 'subject']);
        $a = $this->variant($campaign, 'A');
        $b = $this->variant($campaign, 'B');

        CampaignRecipient::factory()->for($campaign)->create([
            'subscriber_id' => Subscriber::query()->first()->id,
            'campaign_variant_id' => $b->id,
            'email' => 'reader1@example.com',
            'status' => 'sent',
        ]);

        $this->from(route('campaigns.edit', $campaign))
            ->put(route('campaigns.ab.update', $campaign), $this->payload([
                ['id' => $a->id, 'label' => 'A', 'subject' => 'A', 'share_percent' => 100],
            ]))
            ->assertSessionHasErrors('variants');

        $this->assertDatabaseHas('campaign_variants', ['id' => $b->id]);

        $message = session('errors')->get('variants')[0];

        $this->assertStringContainsString('Version B cannot be removed', $message);
        $this->assertStringContainsString('1 recipient(s)', $message);
    }

    public function test_a_split_test_with_one_version_is_refused(): void
    {
        $campaign = $this->make();

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'subject' => 'Only me', 'share_percent' => 100],
        ]))->assertSessionHasErrors('variants');

        $this->assertFalse($campaign->fresh()->is_ab_test);
    }

    public function test_a_version_carrying_its_own_content_needs_its_own_unsubscribe_link(): void
    {
        $campaign = $this->make();

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'html' => '<p>No way out of here</p>', 'share_percent' => 50],
            ['label' => 'B', 'share_percent' => 50],
        ], ['ab_test_type' => 'content']))->assertSessionHasErrors('variants.0.html');

        $this->assertSame(0, CampaignVariant::where('campaign_id', $campaign->id)->count());

        // The same post with an opt-out in it is accepted.
        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'html' => '<p>Hello</p><a href="{{unsubscribe_link}}">Out</a>', 'share_percent' => 50],
            ['label' => 'B', 'share_percent' => 50],
        ], ['ab_test_type' => 'content']))->assertSessionHasNoErrors();

        $this->assertSame(2, CampaignVariant::where('campaign_id', $campaign->id)->count());
    }

    public function test_all_zero_shares_are_refused_rather_than_silently_scaled(): void
    {
        $campaign = $this->make();

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'subject' => 'A', 'share_percent' => 0],
            ['label' => 'B', 'subject' => 'B', 'share_percent' => 0],
        ]))->assertSessionHasErrors('variants');
    }

    public function test_a_window_beyond_the_cap_is_refused(): void
    {
        $campaign = $this->make();

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'subject' => 'A', 'share_percent' => 50],
            ['label' => 'B', 'subject' => 'B', 'share_percent' => 50],
        ], ['ab_decide_after_minutes' => 100000]))->assertSessionHasErrors('ab_decide_after_minutes');
    }

    public function test_a_sending_campaign_refuses_split_test_edits(): void
    {
        $campaign = $this->make(['status' => 'sending', 'is_ab_test' => true, 'ab_test_type' => 'subject']);
        $this->variant($campaign, 'A');
        $this->variant($campaign, 'B');

        $this->put(route('campaigns.ab.update', $campaign), $this->payload([
            ['label' => 'A', 'subject' => 'A', 'share_percent' => 50],
        ]))->assertForbidden();
    }

    public function test_a_variant_belonging_to_another_campaign_cannot_be_edited_through_this_one(): void
    {
        $mine = $this->make();
        $other = $this->make(['name' => 'Someone else']);
        $stranger = $this->variant($other, 'A', ['subject' => 'Not yours']);

        $this->put(route('campaigns.ab.update', $mine), $this->payload([
            ['id' => $stranger->id, 'label' => 'A', 'subject' => 'Stolen', 'share_percent' => 50],
            ['label' => 'B', 'subject' => 'B', 'share_percent' => 50],
        ]))->assertSessionHasErrors('variants.0.id');

        $this->assertSame('Not yours', $stranger->fresh()->subject);
    }

    // -------------------------------------------------------------- the report

    /**
     * @return array{0: Campaign, 1: CampaignVariant, 2: CampaignVariant}
     */
    protected function sentSplitTest(array $campaignOverrides = [], bool $holdback = true): array
    {
        $campaign = $this->make(array_merge([
            'status' => 'sending',
            'is_ab_test' => true,
            'ab_test_type' => 'subject',
            'ab_sample_percent' => 40,
            'ab_winner_metric' => 'opens',
            'ab_decide_after_minutes' => 240,
            'started_at' => now()->subHour(),
            'total_recipients' => 6,
            'sent_count' => 4,
        ], $campaignOverrides));

        $a = $this->variant($campaign, 'A');
        $b = $this->variant($campaign, 'B');

        $subscribers = Subscriber::query()->orderBy('id')->get();

        // Two recipients on each version, both delivered. B is opened twice, A
        // once, so B is ahead on open rate.
        foreach ([[$a, 0, 1], [$b, 2, 3]] as [$variant, $first, $second]) {
            foreach ([$first, $second] as $index) {
                CampaignRecipient::factory()->for($campaign)->create([
                    'subscriber_id' => $subscribers[$index]->id,
                    'campaign_variant_id' => $variant->id,
                    'email' => $subscribers[$index]->email,
                    'status' => 'sent',
                    'sent_at' => now()->subMinutes(10),
                ]);
            }
        }

        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('campaign_variant_id', $b->id)
            ->update(['first_opened_at' => now(), 'open_count' => 1]);

        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('campaign_variant_id', $a->id)
            ->limit(1)
            ->update(['first_opened_at' => now(), 'open_count' => 1]);

        if ($holdback) {
            foreach ([4, 5] as $index) {
                CampaignRecipient::factory()->for($campaign)->create([
                    'subscriber_id' => $subscribers[$index]->id,
                    'campaign_variant_id' => null,
                    'email' => $subscribers[$index]->email,
                    'status' => 'pending',
                    'sent_at' => null,
                ]);
            }
        }

        return [$campaign->refresh(), $a, $b];
    }

    public function test_the_report_shows_each_version_and_no_winner_while_the_test_is_open(): void
    {
        [$campaign] = $this->sentSplitTest();

        $this->get(route('analytics.campaign', $campaign))
            ->assertOk()
            ->assertSee('Split test')
            ->assertSee('Version A')
            ->assertSee('Version B')
            ->assertSee('Waiting')
            // The winner line only ever appears once a decision has been taken.
            ->assertDontSee('Version A won')
            ->assertDontSee('Version B won')
            // Two of six are held back and have been sent nothing.
            ->assertSee('2 contact(s) are held back')
            // A decision is genuinely still open here, so the control is offered.
            ->assertSee('Choose this');
    }

    public function test_the_report_says_when_nothing_in_the_sample_has_been_delivered(): void
    {
        $campaign = $this->make([
            'status' => 'queued',
            'is_ab_test' => true,
            'ab_test_type' => 'subject',
        ]);

        $a = $this->variant($campaign, 'A');
        $this->variant($campaign, 'B');

        CampaignRecipient::factory()->for($campaign)->create([
            'subscriber_id' => Subscriber::query()->first()->id,
            'campaign_variant_id' => $a->id,
            'email' => 'reader1@example.com',
            'status' => 'pending',
            'sent_at' => null,
        ]);

        $this->get(route('analytics.campaign', $campaign))
            ->assertOk()
            ->assertSee('Nothing in the sample has been delivered yet')
            ->assertSee('Nothing delivered');
    }

    public function test_the_report_says_when_a_small_audience_left_no_holdback(): void
    {
        [$campaign] = $this->sentSplitTest(holdback: false);

        $this->get(route('analytics.campaign', $campaign))
            ->assertOk()
            ->assertSee('There is no holdback');
    }

    public function test_a_campaign_that_is_not_a_split_test_gets_no_split_test_block(): void
    {
        $campaign = $this->make(['status' => 'completed', 'sent_count' => 3, 'total_recipients' => 3]);

        $this->get(route('analytics.campaign', $campaign))
            ->assertOk()
            ->assertDontSee('Split test');
    }

    // ------------------------------------------------------------- deciding

    public function test_choosing_a_version_releases_the_holdback_to_it(): void
    {
        Queue::fake();

        [$campaign, $a, $b] = $this->sentSplitTest();

        $this->post(route('campaigns.ab.decide', $campaign), ['variant_id' => $b->id])
            ->assertRedirect();

        $campaign->refresh();

        $this->assertNotNull($campaign->ab_decided_at);
        $this->assertSame((int) $b->id, (int) $campaign->ab_winner_variant_id);
        $this->assertTrue($b->fresh()->is_winner);
        $this->assertFalse($a->fresh()->is_winner);

        // Both held-back rows now carry the winner and can be sent.
        $this->assertSame(0, DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->whereNull('campaign_variant_id')
            ->count());

        $this->assertSame(4, DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('campaign_variant_id', $b->id)
            ->count());

        Queue::assertPushed(SendCampaignChunk::class);
    }

    public function test_a_decided_test_cannot_be_decided_again(): void
    {
        Queue::fake();

        [$campaign, $a, $b] = $this->sentSplitTest();

        $this->post(route('campaigns.ab.decide', $campaign), ['variant_id' => $b->id])->assertRedirect();

        $this->post(route('campaigns.ab.decide', $campaign), ['variant_id' => $a->id])
            ->assertStatus(422);

        $this->assertSame((int) $b->id, (int) $campaign->fresh()->ab_winner_variant_id);
    }

    public function test_a_test_whose_sample_is_still_going_out_cannot_be_decided(): void
    {
        Queue::fake();

        [$campaign, , $b] = $this->sentSplitTest();

        DB::table('campaign_recipients')
            ->where('campaign_id', $campaign->id)
            ->where('campaign_variant_id', $b->id)
            ->limit(1)
            ->update(['status' => 'sending', 'sent_at' => null]);

        $this->post(route('campaigns.ab.decide', $campaign), ['variant_id' => $b->id])
            ->assertStatus(422);

        $this->assertNull($campaign->fresh()->ab_decided_at);

        // And the screen does not offer a control the controller would refuse.
        $this->get(route('analytics.campaign', $campaign))
            ->assertOk()
            ->assertSee('The sample is still going out')
            ->assertDontSee('Choose this');
    }

    public function test_a_variant_from_another_campaign_cannot_be_declared_the_winner(): void
    {
        Queue::fake();

        [$campaign] = $this->sentSplitTest();
        $other = $this->make(['name' => 'Elsewhere']);
        $stranger = $this->variant($other, 'A');

        $this->post(route('campaigns.ab.decide', $campaign), ['variant_id' => $stranger->id])
            ->assertNotFound();

        $this->assertNull($campaign->fresh()->ab_decided_at);
    }

    // -------------------------------------------------------------- blockers

    public function test_the_send_screen_refuses_a_split_test_with_one_version(): void
    {
        $campaign = $this->make(['is_ab_test' => true, 'ab_test_type' => 'subject']);
        $this->variant($campaign, 'A');

        $this->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('This campaign cannot be sent yet')
            ->assertSee('fewer than two versions to compare');
    }

    public function test_the_send_screen_refuses_a_split_test_whose_versions_are_identical(): void
    {
        $campaign = $this->make(['is_ab_test' => true, 'ab_test_type' => 'subject']);
        $this->variant($campaign, 'A', ['subject' => 'Same thing']);
        $this->variant($campaign, 'B', ['subject' => 'Same thing']);

        $this->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertSee('identical, so there is nothing to compare');
    }

    public function test_a_well_formed_split_test_does_not_block_the_send_screen(): void
    {
        $campaign = $this->make(['is_ab_test' => true, 'ab_test_type' => 'subject']);
        $this->variant($campaign, 'A', ['subject' => 'One way']);
        $this->variant($campaign, 'B', ['subject' => 'Another way']);

        $this->get(route('campaigns.confirm', $campaign))
            ->assertOk()
            ->assertDontSee('fewer than two versions to compare')
            ->assertDontSee('identical, so there is nothing to compare');
    }
}
