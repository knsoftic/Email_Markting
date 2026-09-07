<?php

namespace Tests\Feature\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Automation\AutomationSweeper;
use App\Services\Campaigns\MessageBuilder;
use App\Services\Contacts\ListService;
use App\Services\Contacts\SubscriberService;
use App\Services\Contacts\SuppressionService;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * What starts an automation.
 *
 * The interesting failures are all one of two shapes: a trigger that fires
 * when nothing new happened (re-saving a contact, re-importing a file, a
 * scanner opening the mail), and a trigger that fires for the wrong tenant.
 * Both put mail in somebody's inbox that they did not earn, so each has a
 * test that would catch it.
 */
class AutomationTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@triggers.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'max_emails_per_month' => 100000, 'max_contacts' => 100000,
        ]]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();
    }

    protected function automation(string $trigger, array $config = [], array $overrides = []): Automation
    {
        $automation = Automation::create(array_merge([
            'account_id' => $this->owner->account_id,
            'name' => 'Triggered sequence',
            'trigger_type' => $trigger,
            'trigger_config' => $config,
            'status' => 'active',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
        ], $overrides));

        AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 1, 'type' => 'wait',
            'config' => ['amount' => 7, 'unit' => 'days'],
        ]);

        return $automation->refresh();
    }

    protected function runs(Automation $automation): int
    {
        return AutomationRun::withoutGlobalScopes()->where('automation_id', $automation->id)->count();
    }

    // ---------------------------------------------------------- new contacts

    public function test_adding_a_contact_enrols_them(): void
    {
        $automation = $this->automation('subscriber_added');

        app(SubscriberService::class)->create(['email' => 'new@example.com', 'status' => 'active']);

        $this->assertSame(1, $this->runs($automation));
    }

    public function test_saving_the_same_contact_again_does_not_enrol_them_twice(): void
    {
        $automation = $this->automation('subscriber_added');
        $service = app(SubscriberService::class);

        $subscriber = $service->create(['email' => 'new@example.com', 'status' => 'active']);
        $service->update($subscriber, ['first_name' => 'Edited']);
        $service->update($subscriber, ['first_name' => 'Edited again']);

        $this->assertSame(1, $this->runs($automation),
            'Editing a contact is not the same event as adding one.');
    }

    public function test_a_contact_who_already_opted_out_is_not_enrolled(): void
    {
        $automation = $this->automation('subscriber_added');

        app(SuppressionService::class)->suppress('gone@example.com', 'unsubscribed');
        app(SubscriberService::class)->create(['email' => 'gone@example.com']);

        $this->assertSame(0, $this->runs($automation),
            'Adding a name cannot undo an opt-out, and must not start a sequence.');
    }

    public function test_an_automation_scoped_to_a_list_ignores_contacts_added_elsewhere(): void
    {
        $wanted = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Newsletter']);
        $other = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Staff']);

        $automation = $this->automation('subscriber_added', ['list_ids' => [$wanted->id]]);
        $service = app(SubscriberService::class);

        $service->create(['email' => 'wrong@example.com'], [$other->id]);
        $this->assertSame(0, $this->runs($automation));

        $service->create(['email' => 'right@example.com'], [$wanted->id]);
        $this->assertSame(1, $this->runs($automation));
    }

    // ------------------------------------------------------------ list joins

    public function test_joining_a_list_enrols_the_contact_once(): void
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();
        $automation = $this->automation('list_joined', ['list_id' => $list->id]);

        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'joiner@example.com', 'status' => 'active']);

        $lists = app(ListService::class);
        $lists->attach([$subscriber->id], [$list->id], $this->owner->account_id);

        $this->assertSame(1, $this->runs($automation));

        // Attaching a membership they already have is not a new join.
        $lists->attach([$subscriber->id], [$list->id], $this->owner->account_id);

        $this->assertSame(1, $this->runs($automation),
            'Re-attaching an existing membership must not re-enrol anybody.');
    }

    public function test_a_list_automation_ignores_a_different_list(): void
    {
        $wanted = SubscriberList::factory()->forAccount($this->owner->account)->create();
        $other = SubscriberList::factory()->forAccount($this->owner->account)->create();

        $automation = $this->automation('list_joined', ['list_id' => $wanted->id]);

        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'joiner@example.com', 'status' => 'active']);

        app(ListService::class)->attach([$subscriber->id], [$other->id], $this->owner->account_id);

        $this->assertSame(0, $this->runs($automation));
    }

    // ------------------------------------------------------------------ tags

    public function test_tagging_a_contact_enrols_them_once(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'VIP']);
        $automation = $this->automation('tag_added', ['tag_id' => $tag->id]);

        $service = app(SubscriberService::class);
        $subscriber = $service->create(['email' => 'vip@example.com', 'status' => 'active']);

        $service->update($subscriber, [], null, [$tag->id]);
        $this->assertSame(1, $this->runs($automation));

        // Saving again with the tag still on is not a second tagging.
        $service->update($subscriber, ['first_name' => 'Edited'], null, [$tag->id]);
        $this->assertSame(1, $this->runs($automation));
    }

    public function test_bulk_tagging_enrols_only_the_contacts_that_did_not_have_the_tag(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'VIP']);
        $automation = $this->automation('tag_added', ['tag_id' => $tag->id]);

        $already = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'already@example.com', 'status' => 'active']);
        $already->tags()->attach($tag->id);

        $fresh = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'fresh@example.com', 'status' => 'active']);

        app(SubscriberService::class)->bulk('add_tags', [$already->id, $fresh->id], ['tag_ids' => [$tag->id]]);

        $this->assertSame(1, $this->runs($automation));

        $run = AutomationRun::withoutGlobalScopes()->where('automation_id', $automation->id)->first();
        $this->assertSame($fresh->id, $run->subscriber_id);
    }

    // ------------------------------------------------------------ engagement

    public function test_a_first_open_enrols_but_a_second_does_not(): void
    {
        [$automation, $recipient] = $this->campaignSetup('campaign_opened');

        $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]))->assertOk();
        $this->assertSame(1, $this->runs($automation));

        $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]))->assertOk();
        $this->assertSame(1, $this->runs($automation), 'Reading the same email twice is one event.');
    }

    /**
     * A corporate mail gateway opening every message to scan it is not a
     * person reading one. Enrolling on it would send a "since you read our
     * email" follow-up to everybody behind that gateway.
     */
    public function test_a_machine_open_does_not_start_an_automation(): void
    {
        [$automation, $recipient] = $this->campaignSetup('campaign_opened');

        $this->withHeaders(['User-Agent' => 'GoogleImageProxy'])
            ->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]))
            ->assertOk();

        $this->assertSame(0, $this->runs($automation));
    }

    public function test_a_click_enrols_and_a_scanners_click_does_not(): void
    {
        [$automation, $recipient, $link] = $this->campaignSetup('link_clicked', withLink: true);

        $url = URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]);

        $this->withHeaders(['User-Agent' => 'Mimecast'])->get($url);

        $this->assertSame(0, $this->runs($automation), 'A link scanner checked the URL; nobody clicked it.');

        // A real browser, arriving second — which is the normal order, because
        // the gateway scans the message before the reader has opened it.
        // withHeaders() persists across requests in a test, so the reader's
        // user agent has to be stated rather than assumed.
        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/125'])->get($url);

        $this->assertSame(1, $this->runs($automation),
            'The scanner must not consume the click that the person then makes.');
    }

    // ----------------------------------------------------------- time-based

    public function test_the_sweeper_enrols_people_who_did_not_open_after_the_window(): void
    {
        [$automation, $recipient] = $this->campaignSetup('campaign_not_opened', configure: fn ($c) => [
            'campaign_id' => $c->id, 'after_hours' => 48,
        ]);

        // Sent an hour ago: the window has not passed.
        $this->assertSame(0, app(AutomationSweeper::class)->sweep()['enrolled']);

        CampaignRecipient::withoutGlobalScopes()->whereKey($recipient->id)
            ->update(['sent_at' => now()->subDays(3)]);

        $this->assertSame(1, app(AutomationSweeper::class)->sweep()['enrolled']);
        $this->assertSame(1, $this->runs($automation));

        // And the sweep repeats every minute — it must not re-enrol them.
        app(AutomationSweeper::class)->sweep();
        $this->assertSame(1, $this->runs($automation));
    }

    public function test_somebody_who_opened_is_never_swept_up_as_not_having_opened(): void
    {
        [$automation, $recipient] = $this->campaignSetup('campaign_not_opened', configure: fn ($c) => [
            'campaign_id' => $c->id, 'after_hours' => 1,
        ]);

        $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]))->assertOk();

        CampaignRecipient::withoutGlobalScopes()->whereKey($recipient->id)
            ->update(['sent_at' => now()->subDays(3)]);

        app(AutomationSweeper::class)->sweep();

        $this->assertSame(0, $this->runs($automation));
    }

    public function test_a_date_trigger_fires_once_and_then_closes_itself(): void
    {
        $automation = $this->automation('specific_date', ['date' => now()->subDay()->toDateString()]);

        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'invited@example.com', 'status' => 'active']);

        app(AutomationSweeper::class)->sweep();
        $this->assertSame(1, $this->runs($automation));

        // The second sweep finds nobody left and closes the automation, so a
        // contact added tomorrow is not enrolled for a day that has passed.
        app(AutomationSweeper::class)->sweep();
        $this->assertSame('completed', $automation->fresh()->status);

        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'latecomer@example.com', 'status' => 'active']);

        app(AutomationSweeper::class)->sweep();
        $this->assertSame(1, $this->runs($automation));
    }

    public function test_a_future_date_does_not_fire_yet(): void
    {
        $automation = $this->automation('specific_date', ['date' => now()->addWeek()->toDateString()]);

        Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'invited@example.com', 'status' => 'active']);

        app(AutomationSweeper::class)->sweep();

        $this->assertSame(0, $this->runs($automation));
        $this->assertSame('active', $automation->fresh()->status);
    }

    // -------------------------------------------------------------- guardrails

    public function test_a_paused_automation_is_not_triggered(): void
    {
        $automation = $this->automation('subscriber_added', [], ['status' => 'paused']);

        app(SubscriberService::class)->create(['email' => 'new@example.com']);

        $this->assertSame(0, $this->runs($automation));
    }

    public function test_an_automation_with_no_steps_is_not_triggered(): void
    {
        $automation = $this->automation('subscriber_added');
        AutomationStep::where('automation_id', $automation->id)->delete();

        app(SubscriberService::class)->create(['email' => 'new@example.com']);

        $this->assertSame(0, $this->runs($automation));
    }

    public function test_another_accounts_automation_is_never_triggered_by_our_contacts(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@triggers.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, function () use ($other) {
            $automation = Automation::create([
                'account_id' => $other->account_id, 'name' => 'Theirs',
                'trigger_type' => 'subscriber_added', 'status' => 'active',
            ]);
            AutomationStep::create([
                'automation_id' => $automation->id, 'position' => 1, 'type' => 'wait',
                'config' => ['amount' => 1, 'unit' => 'days'],
            ]);

            return $automation;
        });

        app(TenantManager::class)->set($this->owner->account_id);
        app(SubscriberService::class)->create(['email' => 'ours@example.com']);

        $this->assertSame(0, $this->runs($foreign));
    }

    /**
     * An automation that cannot enrol must not take down the thing that
     * triggered it. Losing a contact import because somebody's welcome email
     * is misconfigured is a far worse outcome than a missed enrolment.
     */
    public function test_a_broken_automation_does_not_break_the_contact_save(): void
    {
        $automation = $this->automation('subscriber_added');

        // A step pointing at a template that no longer exists — the run will
        // fail later, but the contact must still be created now.
        AutomationStep::where('automation_id', $automation->id)
            ->update(['type' => 'send_email', 'email_template_id' => null, 'config' => null]);

        $subscriber = app(SubscriberService::class)->create(['email' => 'saved@example.com']);

        $this->assertNotNull($subscriber->id);
        $this->assertDatabaseHas('subscribers', ['email' => 'saved@example.com']);
    }

    // ------------------------------------------------------------- scaffolding

    /**
     * @return array<int, mixed>
     */
    protected function campaignSetup(string $trigger, bool $withLink = false, ?callable $configure = null): array
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        $subscriber = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'reader@example.com', 'status' => 'active']);
        $subscriber->lists()->attach($list->id, ['subscribed_at' => now()]);

        $doc = ['settings' => [], 'blocks' => [
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hi']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['text' => 'Shop', 'href' => 'https://shop.example.com/sale']],
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ]];

        $compiler = app(\App\Services\Campaigns\EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tracked campaign', 'subject' => 'Hello',
            'from_name' => 'Senders Ltd', 'from_email' => 'hello@senders.test',
            'status' => 'completed', 'blocks' => $doc,
            'html' => $compiler->compile($doc), 'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'started_at' => now()->subHours(2), 'completed_at' => now()->subHour(),
            // Set here rather than relying on the column defaults: a model
            // straight out of create() has not read them back, so the rewriter
            // would see null and build no links.
            'track_opens' => true, 'track_clicks' => true,
        ]);

        app(\App\Services\Campaigns\RecipientGenerator::class)->generate($campaign);

        CampaignRecipient::withoutGlobalScopes()->where('campaign_id', $campaign->id)
            ->update(['status' => 'sent', 'sent_at' => now()->subHour()]);

        $config = $configure ? $configure($campaign) : ['campaign_id' => $campaign->id];
        $automation = $this->automation($trigger, $config);

        $recipient = CampaignRecipient::withoutGlobalScopes()->where('campaign_id', $campaign->id)->first();

        if (! $withLink) {
            return [$automation, $recipient, $campaign];
        }

        app(MessageBuilder::class)->blueprint($campaign);
        $link = CampaignLink::withoutGlobalScopes()->where('campaign_id', $campaign->id)->firstOrFail();

        return [$automation, $recipient, $link, $campaign];
    }

    // ================== firing for events the app already calls an event =====

    /**
     * Restoring a soft-deleted contact through the New Contact form is an
     * addition everywhere else in the app — the activity log says "Added
     * contact" and the plan's contact allowance is charged for it — so the
     * trigger has to agree. It was the only part that did not.
     */
    public function test_re_adding_a_deleted_contact_fires_subscriber_added(): void
    {
        $automation = $this->automation('subscriber_added');
        $service = app(SubscriberService::class);

        $first = $service->create(['email' => 'comesback@example.com', 'first_name' => 'Ayesha']);
        $first->delete();

        AutomationRun::query()->delete();

        $again = $service->create(['email' => 'comesback@example.com', 'first_name' => 'Ayesha']);

        $this->assertSame($first->id, $again->id, 'The unique index means this must be the restored row.');
        $this->assertSame(1, AutomationRun::query()->where('automation_id', $automation->id)->count(),
            'A contact added back through the form must enter the welcome automation.');
    }

    /**
     * A restored contact can already carry the tags being applied. Re-applying
     * one is not an event, and on an automation with re-entry it would re-arm
     * a finished run and re-send the whole sequence for nothing.
     */
    public function test_re_applying_a_tag_the_contact_already_has_fires_nothing(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'VIP']);
        $automation = $this->automation('tag_added', ['tag_id' => $tag->id], ['allow_reentry' => true]);

        $service = app(SubscriberService::class);

        $contact = $service->create(['email' => 'vip@example.com'], [], [$tag->id]);
        $this->assertSame(1, AutomationRun::query()->where('automation_id', $automation->id)->count());

        AutomationRun::query()->update(['status' => 'completed', 'completed_at' => now(), 'next_run_at' => null]);

        $contact->delete();
        $service->create(['email' => 'vip@example.com'], [], [$tag->id]);

        $run = AutomationRun::query()->where('automation_id', $automation->id)->sole();

        $this->assertSame('completed', $run->status,
            'The contact already carried this tag, so nothing new happened and the finished run must stay finished.');
    }

    /**
     * Importing a spreadsheet to tag customers the account already knows is
     * the ordinary way people use tagging at scale. Firing only for rows the
     * import happened to create left that doing nothing at all.
     */
    public function test_an_import_fires_tag_added_for_contacts_it_already_knew(): void
    {
        $tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'Customer']);
        $automation = $this->automation('tag_added', ['tag_id' => $tag->id]);

        $existing = Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => 'known@example.com', 'status' => 'active']);

        $this->assertSame(0, AutomationRun::query()->where('automation_id', $automation->id)->count());

        app(\App\Services\Automation\AutomationTrigger::class)
            ->tagsAdded($this->owner->account_id, [$existing->id], [$tag->id]);

        $this->assertSame(1, AutomationRun::query()->where('automation_id', $automation->id)->count(),
            'A contact the account already had must still enter when the import tags them.');
    }
}
