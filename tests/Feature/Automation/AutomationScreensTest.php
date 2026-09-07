<?php

namespace Tests\Feature\Automation;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
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
 * The automation module's screens.
 *
 * The point of these is the states nobody builds on purpose: an automation with
 * no steps, one pointing at a tag that has since been deleted, a run sitting on
 * a step somebody removed, a filter posted as an array. Every one of them is a
 * page that has to render rather than 500, because they are exactly the pages
 * somebody opens when they are already trying to work out why an automation is
 * not doing anything.
 */
class AutomationScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SubscriberList $list;

    protected Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Flows Ltd', 'name' => 'Owner', 'email' => 'owner@flows.test',
            'password' => 'Password123!', 'timezone' => 'Asia/Karachi',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_automation' => true,
            'max_automations' => 20,
            'allow_custom_smtp' => true,
            'max_smtp_accounts' => 5,
            'max_emails_per_month' => 100000,
        ])]);
        $this->owner->account->refresh();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)
            ->create(['name' => 'Relay', 'from_email' => 'relay@flows.test', 'from_name' => 'Relay']);

        $this->list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Main list']);
        $this->tag = Tag::factory()->forAccount($this->owner->account)->create(['name' => 'VIP']);
    }

    // ------------------------------------------------------------- factories

    protected function automation(array $overrides = []): Automation
    {
        return Automation::create(array_merge([
            'account_id' => $this->owner->account_id,
            'user_id' => $this->owner->id,
            'name' => 'Welcome sequence',
            'trigger_type' => 'subscriber_added',
            'trigger_config' => [],
            'status' => 'draft',
            'from_name' => 'Flows Ltd',
            'from_email' => 'hello@flows.test',
        ], $overrides));
    }

    protected function sendStep(Automation $automation, int $position = 1): AutomationStep
    {
        return AutomationStep::create([
            'automation_id' => $automation->id,
            'position' => $position,
            'type' => 'send_email',
            'label' => 'Welcome email',
            'config' => ['subject' => 'Welcome', 'html' => '<p>Hello there</p>'],
        ]);
    }

    /** The block document the builder posts for an email step. */
    protected function document(): array
    {
        return [
            ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
            ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Flows Ltd']],
        ];
    }

    protected function contact(string $email = 'reader@example.com'): Subscriber
    {
        return Subscriber::factory()->forAccount($this->owner->account)
            ->create(['email' => $email, 'first_name' => 'Reader', 'status' => 'active']);
    }

    // ===================================================================== index

    public function test_the_index_renders_an_automation_in_every_status_and_every_trigger(): void
    {
        $statuses = ['draft', 'active', 'paused', 'completed'];

        foreach (Automation::TRIGGERS as $i => $trigger) {
            $automation = $this->automation([
                'name' => "Row {$trigger}",
                'trigger_type' => $trigger,
                'status' => $statuses[$i % count($statuses)],
                'trigger_config' => match ($trigger) {
                    'tag_added' => ['tag_id' => $this->tag->id],
                    'list_joined' => ['list_id' => $this->list->id],
                    'subscriber_added' => ['list_ids' => [$this->list->id]],
                    'campaign_not_opened' => ['campaign_id' => $this->campaign()->id, 'after_hours' => 6],
                    'specific_date' => ['date' => '2026-01-01', 'time' => '09:00', 'list_ids' => [$this->list->id]],
                    default => [],
                },
                'entered_count' => 10,
                'completed_count' => 4,
                'emails_sent' => 7,
            ]);

            $this->sendStep($automation);
        }

        $response = $this->get('/automations')->assertOk();

        foreach (Automation::TRIGGERS as $trigger) {
            $response->assertSee("Row {$trigger}");
        }

        // The enum value itself is never printed as the trigger's description.
        $response->assertSee('A campaign is not opened')
            ->assertSee('A contact joins a list');
    }

    public function test_the_index_renders_hostile_rows(): void
    {
        // No steps, no counters, no trigger config at all.
        $bare = $this->automation(['name' => 'Bare draft']);
        DB::table('automations')->where('id', $bare->id)->update(['trigger_config' => null]);

        // Points at a tag that has since been deleted: it can never fire.
        $orphanTag = $this->automation([
            'name' => 'Ghost tag', 'trigger_type' => 'tag_added', 'status' => 'active',
            'trigger_config' => ['tag_id' => 999999],
        ]);
        $this->sendStep($orphanTag);

        // Active, with steps but no email step at all.
        $tagger = $this->automation(['name' => 'Only tags', 'status' => 'active', 'entered_count' => 3]);
        AutomationStep::create([
            'automation_id' => $tagger->id, 'position' => 1, 'type' => 'add_tag',
            'config' => ['tag_id' => $this->tag->id],
        ]);

        // A run, so last activity has something to show.
        $withRun = $this->automation(['name' => 'Has activity', 'status' => 'active', 'entered_count' => 1]);
        $step = $this->sendStep($withRun);
        AutomationRun::create([
            'account_id' => $this->owner->account_id, 'automation_id' => $withRun->id,
            'subscriber_id' => $this->contact('run@example.com')->id, 'current_step_id' => $step->id,
            'status' => 'waiting', 'next_run_at' => now(), 'started_at' => now(),
        ]);

        $this->get('/automations')->assertOk()
            ->assertSee('Bare draft')
            ->assertSee('Ghost tag')
            ->assertSee('Only tags')
            ->assertSee('Has activity')
            ->assertSee('Nobody has entered it')
            ->assertSee('Sends none')
            ->assertSee('None yet');
    }

    public function test_the_index_survives_hostile_filters(): void
    {
        $this->automation(['name' => 'Findable']);

        // ?q[]=x must not reach the markup as an array.
        $this->get('/automations?q[]=x')->assertOk();
        $this->get('/automations?status[]=active')->assertOk();
        $this->get('/automations?trigger[]=tag_added')->assertOk();

        $this->get('/automations?status=nonsense')->assertOk();
        $this->get('/automations?trigger=nonsense')->assertOk();
        $this->get('/automations?q=Findable')->assertOk()->assertSee('Findable');
        $this->get('/automations?q=nothing+matches')->assertOk()->assertSee('No automations match this search');
        $this->get('/automations?page=9')->assertOk();
    }

    // ================================================================ the form

    public function test_the_create_and_edit_forms_render_for_every_trigger(): void
    {
        $this->get('/automations/create')->assertOk()->assertSee('What starts it');

        foreach (Automation::TRIGGERS as $trigger) {
            $automation = $this->automation(['trigger_type' => $trigger, 'trigger_config' => ['tag_id' => 999999]]);

            $this->get("/automations/{$automation->id}/edit")->assertOk();
        }
    }

    public function test_storing_keeps_only_the_chosen_triggers_config(): void
    {
        $campaign = $this->campaign();

        $response = $this->post('/automations', [
            'name' => 'Tagged welcome',
            'trigger_type' => 'tag_added',
            'tag_id' => $this->tag->id,
            'from_name' => 'Flows Ltd',
            'from_email' => 'HELLO@Flows.test',
            'allow_reentry' => '1',
            // Fields belonging to other triggers. The browser never sends
            // these, but a replayed post can, and a stale campaign_id left in
            // the config is something AutomationTrigger would go on matching.
            'campaign_id' => $campaign->id,
            'date' => '2026-01-01',
        ]);

        $automation = Automation::firstWhere('name', 'Tagged welcome');

        $this->assertNotNull($automation);
        $response->assertRedirect("/automations/{$automation->id}");

        $this->assertSame(['tag_id' => $this->tag->id], $automation->trigger_config);
        $this->assertSame('draft', $automation->status, 'A new automation must never start active.');
        $this->assertTrue($automation->allow_reentry);
        $this->assertSame('hello@flows.test', $automation->from_email);
    }

    public function test_a_did_not_open_trigger_needs_a_campaign_and_a_window(): void
    {
        $this->from('/automations/create')
            ->post('/automations', [
                'name' => 'Missed it',
                'trigger_type' => 'campaign_not_opened',
            ])
            ->assertRedirect('/automations/create')
            ->assertSessionHasErrors(['campaign_id', 'after_hours']);

        $this->assertSame(0, Automation::count());
    }

    public function test_a_date_trigger_needs_a_date_and_a_time(): void
    {
        $this->from('/automations/create')
            ->post('/automations', ['name' => 'On the day', 'trigger_type' => 'specific_date'])
            ->assertRedirect('/automations/create')
            ->assertSessionHasErrors(['date', 'time']);
    }

    public function test_switching_the_trigger_drops_the_old_config(): void
    {
        $automation = $this->automation([
            'trigger_type' => 'tag_added',
            'trigger_config' => ['tag_id' => $this->tag->id],
        ]);

        $this->put("/automations/{$automation->id}", [
            'name' => 'Now a list automation',
            'trigger_type' => 'list_joined',
            'list_id' => $this->list->id,
        ])->assertRedirect("/automations/{$automation->id}");

        $automation->refresh();

        $this->assertSame(['list_id' => $this->list->id], $automation->trigger_config,
            'The tag from the old trigger must not survive the switch.');
    }

    public function test_another_tenants_tag_cannot_be_used_as_a_trigger(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rivals Ltd', 'name' => 'Rival', 'email' => 'rival@rivals.test',
            'password' => 'Password123!',
        ]);

        $theirTag = Tag::withoutGlobalScopes()->create([
            'account_id' => $other->account_id, 'name' => 'Theirs', 'slug' => 'theirs',
        ]);

        $this->from('/automations/create')
            ->post('/automations', [
                'name' => 'Cross tenant', 'trigger_type' => 'tag_added', 'tag_id' => $theirTag->id,
            ])
            ->assertSessionHasErrors('tag_id');
    }

    // =============================================================== activation

    public function test_an_automation_with_no_steps_cannot_be_activated(): void
    {
        $automation = $this->automation(['name' => 'Empty']);

        $this->post("/automations/{$automation->id}/activate")
            ->assertRedirect("/automations/{$automation->id}")
            ->assertSessionHas('error');

        $this->assertSame('draft', $automation->refresh()->status);

        // The reason is on the screen, not only in the flash.
        $this->get("/automations/{$automation->id}")->assertOk()
            ->assertSee('It has no steps yet, so there is nothing for a contact to be entered into.');
    }

    public function test_an_incomplete_trigger_config_refuses_activation(): void
    {
        // Written straight to the row: the form will not let this be saved, but
        // an older record or an import can hold it.
        $automation = $this->automation([
            'name' => 'No campaign', 'trigger_type' => 'campaign_not_opened', 'trigger_config' => [],
        ]);
        $this->sendStep($automation);

        $this->post("/automations/{$automation->id}/activate")->assertSessionHas('error');
        $this->assertSame('draft', $automation->refresh()->status);

        $this->get("/automations/{$automation->id}")->assertOk()->assertSee('The trigger has no campaign.');
    }

    public function test_an_email_step_with_no_content_refuses_activation(): void
    {
        $automation = $this->automation(['name' => 'Hollow']);

        AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 1, 'type' => 'send_email',
            'label' => 'Empty one', 'config' => ['subject' => 'Hi'],
        ]);

        $this->post("/automations/{$automation->id}/activate")->assertSessionHas('error');
        $this->assertSame('draft', $automation->refresh()->status);

        $this->get("/automations/{$automation->id}")->assertOk()->assertSee('has no content');
    }

    public function test_a_complete_automation_activates_and_pauses(): void
    {
        $automation = $this->automation(['name' => 'Ready']);
        $this->sendStep($automation);

        $this->post("/automations/{$automation->id}/activate")->assertSessionHas('success');
        $this->assertSame('active', $automation->refresh()->status);

        $this->post("/automations/{$automation->id}/pause")->assertSessionHas('warning');
        $this->assertSame('paused', $automation->refresh()->status);
    }

    public function test_activation_is_refused_when_the_plan_does_not_include_automations(): void
    {
        $automation = $this->automation(['name' => 'Ready']);
        $this->sendStep($automation);

        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_automation' => false,
        ])]);
        $this->owner->account->refresh();

        $this->post("/automations/{$automation->id}/activate")->assertSessionHas('error');
        $this->assertSame('draft', $automation->refresh()->status);

        // The list still renders, and says why nothing can be created.
        $this->get('/automations')->assertOk()->assertSee('Automations are not part of this plan');
    }

    // ================================================================ lifecycle

    public function test_duplicating_copies_the_steps_and_resets_the_counters(): void
    {
        $automation = $this->automation([
            'name' => 'Original', 'status' => 'active',
            'entered_count' => 40, 'completed_count' => 30, 'emails_sent' => 90,
        ]);
        $this->sendStep($automation, 1);
        AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 2, 'type' => 'wait',
            'config' => ['amount' => 3, 'unit' => 'days'],
        ]);

        $this->post("/automations/{$automation->id}/duplicate")->assertSessionHas('success');

        $copy = Automation::firstWhere('name', 'Original (copy)');

        $this->assertNotNull($copy);
        $this->assertSame('draft', $copy->status);
        $this->assertSame(0, (int) $copy->entered_count);
        $this->assertSame(0, (int) $copy->emails_sent);
        $this->assertSame(2, $copy->steps()->count());
        $this->assertSame(['amount' => 3, 'unit' => 'days'], $copy->steps()->where('type', 'wait')->first()->config);
    }

    public function test_deleting_an_automation_stops_the_contacts_inside_it(): void
    {
        $automation = $this->automation(['name' => 'Doomed', 'status' => 'active']);
        $step = $this->sendStep($automation);

        $run = AutomationRun::create([
            'account_id' => $this->owner->account_id, 'automation_id' => $automation->id,
            'subscriber_id' => $this->contact()->id, 'current_step_id' => $step->id,
            'status' => 'waiting', 'next_run_at' => now(), 'started_at' => now(),
        ]);

        $this->delete("/automations/{$automation->id}")->assertRedirect('/automations');

        $this->assertNull(Automation::find($automation->id));

        $run->refresh();

        // Without this the runner would find no automation, park the run for
        // another fifteen minutes, and do that forever.
        $this->assertSame('cancelled', $run->status);
        $this->assertNull($run->next_run_at);
        $this->assertSame('The automation was deleted.', $run->last_error);
    }

    // ==================================================================== steps

    public function test_a_step_of_every_type_can_be_added(): void
    {
        $automation = $this->automation();
        $campaign = $this->campaign();

        $posts = [
            'send_email' => ['subject' => 'Welcome', 'blocks' => $this->document(), 'settings' => []],
            'wait' => ['amount' => 3, 'unit' => 'days'],
            'condition' => ['check' => 'has_tag', 'tag_id' => $this->tag->id, 'if_false' => 'skip'],
            'add_tag' => ['tag_id' => $this->tag->id],
            'remove_tag' => ['tag_id' => $this->tag->id],
            'move_list' => ['list_id' => $this->list->id, 'from_list_id' => $this->list->id],
            'unsubscribe' => [],
        ];

        foreach ($posts as $type => $payload) {
            $this->get("/automations/{$automation->id}/steps/create?type={$type}")->assertOk();

            $this->post("/automations/{$automation->id}/steps", array_merge(
                ['type' => $type, 'label' => 'Step '.$type],
                $payload
            ))->assertRedirect("/automations/{$automation->id}")->assertSessionHasNoErrors();
        }

        $steps = $automation->steps()->get();

        $this->assertSame(count($posts), $steps->count());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $steps->pluck('position')->map('intval')->all());

        // The email step is compiled server-side from the posted document.
        $email = $steps->firstWhere('type', 'send_email');
        $this->assertSame('Welcome', $email->config['subject']);
        $this->assertStringContainsString('Hello', (string) $email->config['html']);
        $this->assertNotSame('', trim((string) $email->config['plain_text']));

        // A condition keeps only the id its check uses.
        $condition = $steps->firstWhere('type', 'condition');
        $this->assertSame('has_tag', $condition->config['check']);
        $this->assertSame('skip', $condition->config['if_false']);
        $this->assertArrayNotHasKey('list_id', $condition->config);

        // unsubscribe genuinely has nothing to configure.
        $this->assertSame([], $steps->firstWhere('type', 'unsubscribe')->config);

        // Every step renders in the builder and in its own editor.
        $this->get("/automations/{$automation->id}")->assertOk()->assertSee('Wait 3 day(s)');

        foreach ($steps as $step) {
            $this->get("/automations/{$automation->id}/steps/{$step->id}/edit")->assertOk();
        }

        $this->assertSame($campaign->id, $campaign->id);
    }

    public function test_step_validation_refuses_incomplete_configuration(): void
    {
        $automation = $this->automation();
        $base = "/automations/{$automation->id}/steps";

        $this->from($base.'/create')->post($base, ['type' => 'send_email', 'subject' => 'Hi'])
            ->assertSessionHasErrors('blocks');

        $this->from($base.'/create')->post($base, ['type' => 'send_email', 'blocks' => $this->document()])
            ->assertSessionHasErrors('subject');

        // Longer than the runner can hold: waitUntil() caps every unit at about
        // a year, so a bigger number would silently become a smaller one.
        $this->from($base.'/create')->post($base, ['type' => 'wait', 'amount' => 999999, 'unit' => 'weeks'])
            ->assertSessionHasErrors('amount');

        $this->from($base.'/create')->post($base, ['type' => 'condition', 'check' => 'has_tag', 'if_false' => 'end'])
            ->assertSessionHasErrors('tag_id');

        $this->from($base.'/create')->post($base, ['type' => 'add_tag'])
            ->assertSessionHasErrors('tag_id');

        $this->from($base.'/create')->post($base, ['type' => 'move_list'])
            ->assertSessionHasErrors('list_id');

        // A field posted as an array must be rejected, not fatal.
        $this->from($base.'/create')->post($base, ['type' => 'add_tag', 'tag_id' => ['x']])
            ->assertSessionHasErrors('tag_id');

        $this->assertSame(0, $automation->steps()->count());
    }

    public function test_reordering_writes_position(): void
    {
        $automation = $this->automation();

        $one = $this->sendStep($automation, 1);
        $two = AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 2, 'type' => 'wait',
            'config' => ['amount' => 1, 'unit' => 'days'],
        ]);
        $three = AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 3, 'type' => 'add_tag',
            'config' => ['tag_id' => $this->tag->id],
        ]);

        $this->post("/automations/{$automation->id}/steps/reorder", [
            'order' => [$three->id, $one->id, $two->id],
        ])->assertRedirect("/automations/{$automation->id}");

        $this->assertSame(1, (int) $three->refresh()->position);
        $this->assertSame(2, (int) $one->refresh()->position);
        $this->assertSame(3, (int) $two->refresh()->position);
    }

    public function test_reordering_ignores_ids_that_are_not_this_automations(): void
    {
        $automation = $this->automation();
        $mine = $this->sendStep($automation, 1);
        $second = AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 2, 'type' => 'unsubscribe', 'config' => [],
        ]);

        $other = $this->automation(['name' => 'Someone else']);
        $theirs = $this->sendStep($other, 1);

        // A step id from another automation, and one step of ours left out.
        $this->post("/automations/{$automation->id}/steps/reorder", [
            'order' => [$theirs->id, $second->id],
        ])->assertRedirect("/automations/{$automation->id}");

        $this->assertSame(1, (int) $second->refresh()->position);
        $this->assertSame(2, (int) $mine->refresh()->position, 'A step left out of the post keeps a place at the end.');
        $this->assertSame(1, (int) $theirs->refresh()->position, 'Another automation\'s step must not be touched.');
    }

    public function test_a_step_from_another_automation_is_not_editable_through_this_one(): void
    {
        $mine = $this->automation();
        $other = $this->automation(['name' => 'Other']);
        $theirStep = $this->sendStep($other, 1);

        $this->get("/automations/{$mine->id}/steps/{$theirStep->id}/edit")->assertNotFound();
        $this->delete("/automations/{$mine->id}/steps/{$theirStep->id}")->assertNotFound();
    }

    public function test_deleting_a_step_renumbers_and_reports_who_was_on_it(): void
    {
        $automation = $this->automation(['status' => 'active']);

        $one = $this->sendStep($automation, 1);
        $two = AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 2, 'type' => 'wait',
            'config' => ['amount' => 1, 'unit' => 'days'],
        ]);
        $three = AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 3, 'type' => 'unsubscribe', 'config' => [],
        ]);

        $run = AutomationRun::create([
            'account_id' => $this->owner->account_id, 'automation_id' => $automation->id,
            'subscriber_id' => $this->contact()->id, 'current_step_id' => $two->id,
            'status' => 'waiting', 'next_run_at' => now()->addDay(), 'started_at' => now(),
        ]);

        // The builder warns before the press.
        $this->get("/automations/{$automation->id}")->assertOk()->assertSee('1 here now');

        $this->delete("/automations/{$automation->id}/steps/{$two->id}")
            ->assertRedirect("/automations/{$automation->id}")
            ->assertSessionHas('warning');

        $this->assertSame(1, (int) $one->refresh()->position);
        $this->assertSame(2, (int) $three->refresh()->position);

        // nullOnDelete, so the contact is not stranded on a row that is gone —
        // the runner completes them on its next pass, which the screen says.
        $this->assertNull($run->refresh()->current_step_id);

        $this->get("/automations/{$automation->id}/runs")->assertOk()
            ->assertSee('The step they were on has been deleted');
    }

    // ===================================================================== runs

    public function test_the_runs_screen_renders_every_state(): void
    {
        $automation = $this->automation(['status' => 'active']);
        $step = $this->sendStep($automation);

        $states = [
            ['waiting', now()->addHour(), null],
            ['running', now(), null],
            ['completed', null, null],
            ['failed', null, 'The step "Welcome email" has no subject or content to send.'],
            ['cancelled', null, 'The contact opted out while this automation was running.'],
        ];

        foreach ($states as $i => [$status, $next, $error]) {
            AutomationRun::create([
                'account_id' => $this->owner->account_id,
                'automation_id' => $automation->id,
                'subscriber_id' => $this->contact("person{$i}@example.com")->id,
                'current_step_id' => in_array($status, ['waiting', 'running'], true) ? $step->id : null,
                'status' => $status,
                'next_run_at' => $next,
                'started_at' => now()->subDay(),
                'completed_at' => in_array($status, ['completed', 'cancelled'], true) ? now() : null,
                'steps_completed' => 1,
                'last_error' => $error,
            ]);
        }

        $response = $this->get("/automations/{$automation->id}/runs")->assertOk();

        foreach ($states as $i => $_) {
            $response->assertSee("person{$i}@example.com");
        }

        $response->assertSee('has no subject or content to send.')
            ->assertSee('The contact opted out while this automation was running.');

        // Filtering, including the shapes a crafted URL can produce.
        $this->get("/automations/{$automation->id}/runs?status=failed")->assertOk()->assertSee('person3@example.com');
        $this->get("/automations/{$automation->id}/runs?status=nonsense")->assertOk();
        $this->get("/automations/{$automation->id}/runs?status[]=failed")->assertOk();
        $this->get("/automations/{$automation->id}/runs?q[]=x")->assertOk();
        $this->get("/automations/{$automation->id}/runs?q=person1")->assertOk()->assertSee('person1@example.com');
        $this->get("/automations/{$automation->id}/runs?page=9")->assertOk();
    }

    public function test_the_runs_screen_survives_a_deleted_contact_and_a_missing_start_time(): void
    {
        $automation = $this->automation(['status' => 'paused']);
        $step = $this->sendStep($automation);

        $subscriber = $this->contact('gone@example.com');

        $run = AutomationRun::create([
            'account_id' => $this->owner->account_id, 'automation_id' => $automation->id,
            'subscriber_id' => $subscriber->id, 'current_step_id' => $step->id,
            'status' => 'waiting', 'next_run_at' => now()->subHour(), 'started_at' => now(),
        ]);

        // Soft-deleted, so the row survives but the relation resolves to null.
        $subscriber->delete();

        DB::table('automation_runs')->where('id', $run->id)->update(['started_at' => null]);

        $this->get("/automations/{$automation->id}/runs")->assertOk()
            ->assertSee('The contact has been deleted')
            ->assertSee('Not recorded')
            ->assertSee('so nothing below is moving');
    }

    public function test_the_runs_screen_is_empty_without_pretending_to_measure_anything(): void
    {
        $automation = $this->automation();

        $this->get("/automations/{$automation->id}/runs")->assertOk()
            ->assertSee('Nobody has entered this automation')
            ->assertSee('simply nobody yet');
    }

    // ============================================================== permissions

    public function test_a_view_only_member_sees_no_management_controls(): void
    {
        $automation = $this->automation(['name' => 'Read only row', 'status' => 'active']);
        $this->sendStep($automation);

        $staffRole = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $staffRole->permissions()->syncWithoutDetaching(
            Permission::where('slug', 'automation.view')->pluck('id')->all()
        );

        $staff = User::factory()->create([
            'account_id' => $this->owner->account_id,
            'role_id' => $staffRole->id,
            'email_verified_at' => now(),
        ]);

        $this->actingAs($staff);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/automations')->assertOk()
            ->assertSee('Read only row')
            ->assertDontSee('New automation')
            ->assertDontSee('Duplicate');

        $this->get("/automations/{$automation->id}")->assertOk()->assertDontSee('Add a step');
        $this->get("/automations/{$automation->id}/runs")->assertOk();

        // And the management routes themselves are refused, not merely hidden.
        $this->get("/automations/{$automation->id}/edit")->assertForbidden();
        $this->post("/automations/{$automation->id}/pause")->assertForbidden();
        $this->get("/automations/{$automation->id}/steps/create")->assertForbidden();
    }

    public function test_another_tenants_automation_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rivals Ltd', 'name' => 'Rival', 'email' => 'rival2@rivals.test',
            'password' => 'Password123!',
        ]);

        $theirs = Automation::withoutGlobalScopes()->create([
            'account_id' => $other->account_id, 'name' => 'Theirs', 'trigger_type' => 'subscriber_added',
            'status' => 'active',
        ]);

        $this->get("/automations/{$theirs->id}")->assertNotFound();
        $this->get("/automations/{$theirs->id}/runs")->assertNotFound();
        $this->delete("/automations/{$theirs->id}")->assertNotFound();
    }

    // ================================================================== helpers

    protected function campaign(): Campaign
    {
        return Campaign::factory()->forAccount($this->owner->account)->create([
            'name' => 'March newsletter', 'subject' => 'Hello', 'from_name' => 'Flows Ltd',
            'from_email' => 'hello@flows.test',
        ]);
    }

    // ============================ regressions found by the Phase 10 audit ====

    /**
     * A block setting arriving as an array where text belongs.
     *
     * `blocks.*.settings => array` validates the container and says nothing
     * about what is inside it, so `settings.text` can be posted as an array.
     * The compiler then casts it to string, PHP raises "Array to string
     * conversion", Laravel turns that into an ErrorException, and a save is
     * answered with a 500 instead of a validation message. Fixed at
     * BlockCatalogue::normalise(), which is the one point every document
     * passes through.
     */
    public function test_a_block_setting_posted_as_an_array_does_not_500(): void
    {
        $automation = $this->automation();

        $response = $this->from("/automations/{$automation->id}/steps/create")
            ->post("/automations/{$automation->id}/steps", [
                'type' => 'send_email',
                'subject' => 'Hello',
                'blocks' => [['type' => 'heading', 'settings' => ['text' => ['deep']]]],
                'settings' => [],
            ]);

        $this->assertNotSame(500, $response->status(),
            'A hostile block document must be refused or normalised, never crash the save.');

        $this->get("/automations/{$automation->id}/steps/create")->assertOk();
        $this->get("/automations/{$automation->id}")->assertOk();

        // And the same document through the builder's live preview, which had
        // the identical defect because it never normalised at all.
        $this->postJson('/templates/compile', [
            'blocks' => [['type' => 'heading', 'settings' => ['text' => ['deep']]]],
            'settings' => [],
        ])->assertOk();
    }

    /**
     * The "In progress" card counts waiting AND running; its link must show
     * both. Pointing it at status=waiting made the card say 3 and the screen
     * it opened show 1 — the card was right and its own link disagreed.
     */
    public function test_the_in_progress_card_link_lists_exactly_what_the_card_counts(): void
    {
        $automation = $this->automation(['status' => 'active']);
        $step = $this->sendStep($automation);

        $statuses = ['waiting', 'running', 'running'];

        foreach ($statuses as $i => $status) {
            AutomationRun::create([
                'account_id' => $this->owner->account_id,
                'automation_id' => $automation->id,
                'subscriber_id' => $this->contact("live{$i}@example.com")->id,
                'current_step_id' => $step->id,
                'status' => $status,
                'next_run_at' => now(),
                'started_at' => now(),
            ]);
        }

        $show = $this->get("/automations/{$automation->id}")->assertOk()->getContent();

        $this->assertStringContainsString(
            route('automations.runs', ['automation' => $automation, 'status' => 'live']),
            $show,
            'The card must link to a filter that can express what it counted.'
        );

        $runs = $this->get("/automations/{$automation->id}/runs?status=live")->assertOk()->getContent();

        foreach ($statuses as $i => $status) {
            $this->assertStringContainsString("live{$i}@example.com", $runs,
                'A contact the card counted is missing from the screen the card links to.');
        }
    }

    /**
     * The list screen must not get slower the more the customer uses it.
     *
     * Each trigger that names a tag, list or campaign used to cost its own
     * EXISTS query to decide whether that target still existed.
     */
    public function test_the_index_costs_the_same_whatever_the_triggers_watch(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->automation([
                'name' => "Tagged {$i}",
                'trigger_type' => 'tag_added',
                'trigger_config' => ['tag_id' => $this->tag->id],
            ]);
        }

        $count = function (): int {
            $n = 0;
            DB::listen(function () use (&$n) { $n++; });
            $this->get('/automations')->assertOk();

            return $n;
        };

        $withTargets = $count();

        Automation::query()->update(['trigger_config' => json_encode([])]);

        $withoutTargets = $count();

        $this->assertLessThanOrEqual(
            $withoutTargets + 4,
            $withTargets,
            "Twenty triggers that name a tag cost {$withTargets} queries against {$withoutTargets} "
            .'for the same rows with no target — the lookups must be batched, not per row.'
        );
    }

    /**
     * Deleted contacts and lists were still charged against the plan.
     *
     * `usageFor()` counts seven things; five said `whereNull('deleted_at')`
     * and these two used `withoutGlobalScopes()`, which lifts SoftDeletingScope
     * as well as tenancy. Delete five hundred contacts and the allowance still
     * said they were there — and would eventually refuse to let any more in.
     */
    public function test_deleted_contacts_and_lists_stop_counting_against_the_plan(): void
    {
        $limits = \App\Support\PlanLimits::for($this->owner->account);

        $before = $limits->usageFor('max_contacts');
        $listsBefore = $limits->usageFor('max_lists');

        $contact = $this->contact('deleted@example.com');
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create(['name' => 'Temporary']);

        $this->assertSame($before + 1, $limits->usageFor('max_contacts'));
        $this->assertSame($listsBefore + 1, $limits->usageFor('max_lists'));

        $contact->delete();
        $list->delete();

        $this->assertSame($before, $limits->usageFor('max_contacts'),
            'A deleted contact must not keep consuming the plan allowance.');
        $this->assertSame($listsBefore, $limits->usageFor('max_lists'),
            'A deleted list must not keep consuming the plan allowance.');
    }
}
