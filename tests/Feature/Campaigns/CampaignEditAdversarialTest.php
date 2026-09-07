<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\Segment;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignEditAdversarialTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SubscriberList $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@adv.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Main list']);

        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 's1@example.com'])
            ->lists()->attach($this->list->id, ['subscribed_at' => now()]);
    }

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'March newsletter',
            'subject' => 'Hello',
            'preview_text' => 'The short line',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'reply_to' => null,
            'timezone' => 'Asia/Karachi',
            'track_opens' => '1',
            'track_clicks' => '1',
            'audience' => ['lists' => [$this->list->id]],
            'blocks' => [
                ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
                ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
            ],
            'settings' => [],
        ], $overrides);
    }

    /** The browser posts blocks/settings as JSON strings, which is what the view decodes. */
    protected function jsonPayload(array $overrides = []): array
    {
        $p = $this->payload();

        $p['blocks'] = json_encode($p['blocks']);
        $p['settings'] = json_encode((object) []);

        return array_merge($p, $overrides);
    }

    protected function campaign(array $overrides = []): Campaign
    {
        $document = ['settings' => [], 'blocks' => [
            ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
            ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(EmailCompiler::class);

        return Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'March newsletter',
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'draft',
            'blocks' => $document,
            'html' => $compiler->compile($document),
            'plain_text' => $compiler->compileText($document),
            'audience' => ['lists' => [$this->list->id]],
            'timezone' => 'Asia/Karachi',
        ], $overrides));
    }

    // ------------------------------------------------------------------ 500s

    public function test_edit_renders_for_every_editable_status(): void
    {
        foreach (['draft', 'scheduled', 'paused'] as $status) {
            $campaign = $this->campaign(['status' => $status, 'scheduled_at' => now()->addDay()]);

            $this->get("/campaigns/{$campaign->id}/edit")->assertOk();
        }

        foreach (['queued', 'sending', 'completed', 'failed', 'cancelled'] as $status) {
            $campaign = $this->campaign(['status' => $status]);

            $this->get("/campaigns/{$campaign->id}/edit")->assertForbidden();
        }
    }

    public function test_create_renders_on_a_brand_new_account_with_nothing_in_it(): void
    {
        $fresh = app(AccountProvisioner::class)->provision([
            'company_name' => 'Empty Ltd', 'name' => 'New', 'email' => 'new@adv.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $fresh->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($fresh);
        app(TenantManager::class)->set($fresh->account_id);

        $this->get('/campaigns/create')
            ->assertOk()
            ->assertSee('No lists yet')
            ->assertSee('No tags yet')
            ->assertSee('No segments yet');
    }

    public function test_a_block_level_validation_error_redisplays_without_a_500(): void
    {
        $this->post('/campaigns', $this->jsonPayload([
            'subject' => '',
            'blocks' => json_encode([['id' => 'b1', 'type' => 'not-a-real-block', 'settings' => []]]),
        ]))->assertRedirect()->assertSessionHasErrors('blocks.0.type');

        // Not merely a 200: the per-block messages have to actually print.
        // $errors->get('blocks.*') hands back messages grouped by key, and
        // echoing that nesting is what used to be the white screen.
        $this->get('/campaigns/create')
            ->assertOk()
            ->assertSee('The email content was rejected');
    }

    public function test_a_validation_error_after_an_array_shaped_post_redisplays_without_a_500(): void
    {
        // Not every client is the browser form: an integration (or a test)
        // posts blocks as a real array rather than a JSON string.
        $this->post('/campaigns', $this->payload(['subject' => '']))->assertRedirect();

        $this->get('/campaigns/create')->assertOk();
    }

    public function test_the_edit_screen_survives_an_orphaned_template_and_smtp_account(): void
    {
        $template = EmailTemplate::create([
            'account_id' => $this->owner->account_id,
            'user_id' => $this->owner->id,
            'name' => 'Retired layout',
            'is_active' => true,
        ]);

        // An account this campaign was pinned to, which then stops being
        // sendable (paused, over a limit, unassigned).
        $pinned = SmtpAccount::factory()->forAccount($this->owner->account)->create(['name' => 'Retired relay']);

        $campaign = $this->campaign([
            'email_template_id' => $template->id,
            'smtp_account_id' => $pinned->id,
        ]);

        $template->update(['is_active' => false]);
        $pinned->update(['is_active' => false]);

        $this->get("/campaigns/{$campaign->id}/edit")
            ->assertOk()
            ->assertSee('no longer available')
            ->assertSee('not available right now');
    }

    public function test_a_stored_timezone_outside_the_modern_list_is_not_silently_replaced(): void
    {
        // Asia/Calcutta is what Chrome still reports for India, and Laravel's
        // `timezone` rule accepts it — but listIdentifiers() does not offer it.
        $campaign = $this->campaign(['timezone' => 'Asia/Calcutta']);

        $this->assertNotContains('Asia/Calcutta', \DateTimeZone::listIdentifiers());

        $html = $this->get("/campaigns/{$campaign->id}/edit")->assertOk()->getContent();

        $this->assertStringContainsString(
            '<option value="Asia/Calcutta" selected>',
            $html,
            'The stored zone matched no option, so the browser would submit the first one in the list.'
        );

        // Held exactly once, and no zone from the list is selected alongside it.
        $this->assertSame(1, substr_count($html, 'value="Asia/Calcutta" selected'));
    }

    public function test_a_crafted_array_timezone_does_not_break_the_screen(): void
    {
        $campaign = $this->campaign();

        $this->put("/campaigns/{$campaign->id}", $this->jsonPayload([
            'subject' => '',
            'timezone' => ['Asia/Karachi'],
        ]))->assertRedirect();

        $this->get("/campaigns/{$campaign->id}/edit")->assertOk();
    }

    // -------------------------------------------------------- round tripping

    public function test_cleared_audience_boxes_stay_clear_after_a_rejected_save(): void
    {
        $campaign = $this->campaign();

        $this->put("/campaigns/{$campaign->id}", $this->jsonPayload([
            'subject' => '',
            'audience' => null,
        ]))->assertRedirect();

        $html = $this->get("/campaigns/{$campaign->id}/edit")->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'value="'.$this->list->id.'" checked',
            $html,
            'A list the user had just unticked came back ticked.'
        );
    }

    public function test_typed_blocks_survive_a_rejected_save(): void
    {
        $this->post('/campaigns', $this->jsonPayload([
            'subject' => '',
            'blocks' => json_encode([
                ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'A HEADLINE THE USER TYPED']],
            ]),
        ]))->assertRedirect();

        $this->get('/campaigns/create')->assertOk()->assertSee('A HEADLINE THE USER TYPED', false);
    }

    // ------------------------------------------------------------- behaviour

    public function test_clearing_every_audience_box_actually_clears_the_audience(): void
    {
        $campaign = $this->campaign();

        $this->assertSame([$this->list->id], $campaign->audience['lists']);

        // Unchecked boxes post nothing at all — this is exactly what the
        // browser sends when the user unticks the last list.
        $payload = $this->jsonPayload();
        unset($payload['audience']);

        $this->put("/campaigns/{$campaign->id}", $payload)->assertRedirect()->assertSessionHasNoErrors();

        $campaign->refresh();

        $this->assertSame(
            ['lists' => [], 'tags' => [], 'segments' => []],
            (array) $campaign->audience,
            'Unticking every audience box and saving left the old audience in place.'
        );

        // And the campaign genuinely reaches nobody now, rather than quietly
        // still reaching the list the user removed.
        $this->assertSame(0, app(\App\Services\Campaigns\RecipientGenerator::class)->count($campaign));

        // The screen agrees: the box comes back unticked.
        $html = $this->get("/campaigns/{$campaign->id}/edit")->assertOk()->getContent();

        $this->assertStringNotContainsString('value="'.$this->list->id.'" checked', $html);
    }

    public function test_a_tag_and_segment_audience_round_trips(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'VIP']);
        $segment = Segment::factory()->forAccount($this->owner->account)->create(['name' => 'Recent']);

        $this->post('/campaigns', $this->jsonPayload([
            'audience' => ['lists' => [], 'tags' => [$tag->id], 'segments' => [$segment->id]],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $campaign = Campaign::where('name', 'March newsletter')->firstOrFail();

        $this->assertSame([$tag->id], $campaign->audience['tags']);
        $this->assertSame([$segment->id], $campaign->audience['segments']);
    }
}
