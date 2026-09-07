<?php

namespace Tests\Feature\Campaigns;

use App\Jobs\Campaigns\SendCampaignChunk;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\EmailCompiler;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The state machine and the checks that stand between the Send button and a
 * real send. Most of these exist because the alternative is a compliance
 * failure rather than a bug.
 */
class CampaignDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@dispatch.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();
    }

    protected function dispatcher(): CampaignDispatcher
    {
        return $this->app->make(CampaignDispatcher::class);
    }

    protected function campaign(int $contacts = 3, array $overrides = [], bool $withFooter = true): Campaign
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        for ($i = 1; $i <= $contacts; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "p{$i}@example.com"])
                ->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $blocks = [['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']]];

        if ($withFooter) {
            $blocks[] = ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']];
        }

        $doc = ['settings' => [], 'blocks' => $blocks];
        $compiler = app(EmailCompiler::class);

        return Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Newsletter',
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'draft',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'timezone' => 'Asia/Karachi',
        ], $overrides));
    }

    // ----------------------------------------------------------- blockers

    public function test_a_campaign_without_an_unsubscribe_link_cannot_be_sent(): void
    {
        $campaign = $this->campaign(withFooter: false);

        $blockers = $this->dispatcher()->blockers($campaign);

        $this->assertContains(
            'The content has no unsubscribe link. Add a footer block before sending.',
            $blockers,
            'This is the one blocker that is not negotiable.'
        );

        Queue::fake();
        $this->post("/campaigns/{$campaign->id}/send")->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_an_empty_audience_blocks_the_send(): void
    {
        $campaign = $this->campaign(0, ['audience' => []]);

        $this->assertContains(
            'The audience is empty — no contact matches the selected lists, tags or segments.',
            $this->dispatcher()->blockers($campaign)
        );
    }

    public function test_having_no_smtp_account_blocks_the_send(): void
    {
        SmtpAccount::withoutGlobalScopes()->where('account_id', $this->owner->account_id)->delete();

        $this->assertContains(
            'No SMTP account is available to send through.',
            $this->dispatcher()->blockers($this->campaign())
        );
    }

    public function test_an_unknown_placeholder_is_reported_before_sending(): void
    {
        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi {{frist_name}}']],
            ['id' => 'f', 'type' => 'footer', 'settings' => []],
        ]];

        $campaign = $this->campaign(2, [
            'blocks' => $doc,
            'html' => app(EmailCompiler::class)->compile($doc),
        ]);

        $this->assertContains(
            'The content uses an unknown placeholder: {{frist_name}}',
            $this->dispatcher()->blockers($campaign),
            'Better caught here than in 50,000 inboxes.'
        );
    }

    public function test_a_ready_campaign_has_no_blockers(): void
    {
        $this->assertSame([], $this->dispatcher()->blockers($this->campaign()));
    }

    /**
     * Every link in a sent email is built from APP_URL, because a queue worker
     * has no request to take a host from. Pointing it at localhost puts an
     * unreachable unsubscribe link in every inbox.
     */
    public function test_a_local_app_url_warns_outside_production_and_blocks_in_it(): void
    {
        config()->set('app.url', 'http://localhost:8000');

        $campaign = $this->campaign();

        $this->assertNotEmpty(
            array_filter($this->dispatcher()->warnings($campaign), fn ($w) => str_contains($w, 'APP_URL')),
            'A local URL is fine for a test, but the operator has to be told.'
        );
        $this->assertSame([], $this->dispatcher()->blockers($campaign), 'It must not stop a local test send.');

        app()->detectEnvironment(fn () => 'production');

        $this->assertContains(
            'APP_URL is set to a local address, so the unsubscribe and tracking links '
            .'in this email would point somewhere recipients cannot reach. Set it to your real domain.',
            $this->dispatcher()->blockers($campaign),
            'In production an unreachable opt-out is a compliance failure, not a note.'
        );
    }

    public function test_a_real_domain_raises_no_url_warning(): void
    {
        config()->set('app.url', 'https://mail.example.com');

        $warnings = $this->dispatcher()->warnings($this->campaign());

        $this->assertEmpty(array_filter($warnings, fn ($w) => str_contains($w, 'APP_URL')));
    }

    public function test_tracking_switched_fully_off_is_worth_saying(): void
    {
        config()->set('app.url', 'https://mail.example.com');

        $campaign = $this->campaign(2, ['track_opens' => false, 'track_clicks' => false]);

        $this->assertNotEmpty(
            array_filter($this->dispatcher()->warnings($campaign), fn ($w) => str_contains($w, 'tracking are off'))
        );
    }

    // -------------------------------------------------------------- send

    public function test_sending_generates_recipients_and_queues_the_first_chunk(): void
    {
        Queue::fake();

        $campaign = $this->campaign(4);

        $total = $this->dispatcher()->sendNow($campaign);

        $this->assertSame(4, $total);
        $this->assertSame('queued', $campaign->fresh()->status);
        $this->assertSame(4, CampaignRecipient::where('campaign_id', $campaign->id)->count());
        $this->assertNotNull($campaign->fresh()->started_at);

        Queue::assertPushed(SendCampaignChunk::class);
    }

    public function test_a_campaign_that_is_already_sending_cannot_be_started_again(): void
    {
        Queue::fake();

        $campaign = $this->campaign(2, ['status' => 'sending']);

        $this->post("/campaigns/{$campaign->id}/send")->assertStatus(422);
    }

    // ---------------------------------------------------------- schedule

    public function test_scheduling_stores_the_moment_in_utc(): void
    {
        $campaign = $this->campaign(2);

        $this->dispatcher()->schedule($campaign, now('Asia/Karachi')->addDay()->format('Y-m-d H:i'), 'Asia/Karachi');

        $campaign->refresh();

        $this->assertSame('scheduled', $campaign->status);
        $this->assertSame('Asia/Karachi', $campaign->timezone);
        $this->assertTrue($campaign->scheduled_at->isFuture());
    }

    public function test_a_time_in_the_past_is_refused(): void
    {
        $campaign = $this->campaign(2);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->dispatcher()->schedule($campaign, now('Asia/Karachi')->subHour()->format('Y-m-d H:i'), 'Asia/Karachi');
    }

    public function test_scheduling_does_not_generate_recipients_yet(): void
    {
        $campaign = $this->campaign(3);

        $this->dispatcher()->schedule($campaign, now('UTC')->addDay()->format('Y-m-d H:i'), 'UTC');

        $this->assertSame(0, CampaignRecipient::where('campaign_id', $campaign->id)->count(),
            'The audience is resolved at send time so it reflects who is on the list then.');
    }

    public function test_the_scheduler_command_starts_a_due_campaign_exactly_once(): void
    {
        Queue::fake();

        $campaign = $this->campaign(3, [
            'status' => 'scheduled',
            'scheduled_at' => now()->subMinute(),
        ]);

        $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

        $this->assertSame('queued', $campaign->fresh()->status);
        Queue::assertPushed(SendCampaignChunk::class, 1);

        // A second scheduler tick must not start it again.
        $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

        Queue::assertPushed(SendCampaignChunk::class, 1);
    }

    public function test_a_future_campaign_is_left_alone(): void
    {
        Queue::fake();

        $campaign = $this->campaign(2, ['status' => 'scheduled', 'scheduled_at' => now()->addHour()]);

        $this->artisan('campaigns:dispatch-scheduled')->assertSuccessful();

        $this->assertSame('scheduled', $campaign->fresh()->status);
        Queue::assertNothingPushed();
    }

    // ----------------------------------------------------------- control

    public function test_pause_resume_and_cancel_move_through_the_state_machine(): void
    {
        Queue::fake();

        $campaign = $this->campaign(5);
        $this->dispatcher()->sendNow($campaign);
        $campaign->refresh()->forceFill(['status' => 'sending'])->save();

        $this->dispatcher()->pause($campaign);
        $this->assertSame('paused', $campaign->fresh()->status);
        $this->assertNotNull($campaign->fresh()->paused_at);

        $this->dispatcher()->resume($campaign->fresh());
        $this->assertSame('sending', $campaign->fresh()->status);
        $this->assertNull($campaign->fresh()->paused_at);

        $dropped = $this->dispatcher()->cancel($campaign->fresh());

        $this->assertSame(5, $dropped);
        $this->assertSame('cancelled', $campaign->fresh()->status);
        $this->assertSame(0, CampaignRecipient::where('campaign_id', $campaign->id)
            ->whereIn('status', ['pending', 'sending'])->count());
    }

    public function test_cancelling_keeps_what_was_already_sent(): void
    {
        Queue::fake();

        $campaign = $this->campaign(4);
        $this->dispatcher()->sendNow($campaign);

        CampaignRecipient::where('campaign_id', $campaign->id)->limit(2)
            ->update(['status' => 'sent', 'sent_at' => now()]);
        $campaign->forceFill(['status' => 'sending', 'sent_count' => 2])->save();

        $this->dispatcher()->cancel($campaign->fresh());

        $this->assertSame(2, CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'sent')->count(), 'Sent messages cannot be recalled, so they stay counted.');
        $this->assertSame(2, (int) $campaign->fresh()->sent_count);
    }

    public function test_a_completed_campaign_cannot_be_paused(): void
    {
        $campaign = $this->campaign(1, ['status' => 'completed']);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->dispatcher()->pause($campaign);
    }

    // -------------------------------------------------------- test send

    public function test_a_test_send_touches_no_counter_and_creates_no_recipient(): void
    {
        $campaign = $this->campaign(3);

        $this->dispatcher()->sendTest($campaign, 'reviewer@example.com');

        $campaign->refresh();

        $this->assertSame(0, (int) $campaign->sent_count);
        $this->assertSame(0, (int) $campaign->total_recipients);
        $this->assertSame(0, CampaignRecipient::where('campaign_id', $campaign->id)->count(),
            'A test must not appear in the campaign reporting.');
    }

    // ------------------------------------------------------ editability

    public function test_a_sending_campaign_cannot_be_edited(): void
    {
        $campaign = $this->campaign(2, ['status' => 'sending']);

        $this->get("/campaigns/{$campaign->id}/edit")->assertForbidden();
        $this->put("/campaigns/{$campaign->id}", ['name' => 'Changed'])->assertForbidden();

        $this->assertSame('Newsletter', $campaign->fresh()->name,
            'Editing mid-send would give half the list different content with no way to tell which.');
    }

    public function test_a_draft_can_be_edited(): void
    {
        $campaign = $this->campaign(2);

        $this->get("/campaigns/{$campaign->id}/edit")->assertOk();
    }

    public function test_a_running_campaign_cannot_be_deleted(): void
    {
        $campaign = $this->campaign(2, ['status' => 'sending']);

        $this->delete("/campaigns/{$campaign->id}")->assertStatus(422);
        $this->assertNotNull(Campaign::find($campaign->id));
    }

    // ------------------------------------------------------------ tenancy

    public function test_a_campaign_cannot_target_another_accounts_list(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@dispatch.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreignList = SubscriberList::factory()->forAccount($other->account)->create();

        $this->post('/campaigns', [
            'name' => 'Sneaky', 'subject' => 'Hi',
            'from_name' => 'X', 'from_email' => 'x@example.com',
            'timezone' => 'UTC',
            'audience' => ['lists' => [$foreignList->id]],
            'blocks' => [['type' => 'heading', 'settings' => ['text' => 'Hi']]],
        ])->assertSessionHasErrors('audience.lists.0');
    }
}
