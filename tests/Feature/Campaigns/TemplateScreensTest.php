<?php

namespace Tests\Feature\Campaigns;

use App\Models\EmailTemplate;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SystemTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The template builder screens, end to end through HTTP: the routes render,
 * the form saves a real compiled document, and the ownership rules that keep
 * one account out of another's templates actually hold.
 */
class TemplateScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Builders Ltd', 'name' => 'Owner', 'email' => 'owner@templates.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->allow(['allow_template_builder' => true, 'max_templates' => 10]);

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    protected function allow(array $overrides): void
    {
        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], $overrides)]);
        $this->owner->account->refresh();
    }

    /** @return array<string, mixed> */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Monthly update',
            'subject' => 'Hello {{first_name|there}}',
            'description' => 'The regular one',
            'category' => 'newsletter',
            'blocks' => [
                ['id' => 'b1', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
                ['id' => 'b2', 'type' => 'footer', 'settings' => ['companyLine' => 'Builders Ltd']],
            ],
            'settings' => ['preheader' => 'A short line'],
        ], $overrides);
    }

    protected function own(array $attributes = []): EmailTemplate
    {
        return EmailTemplate::create(array_merge([
            'user_id' => $this->owner->id,
            'name' => 'My template',
            'category' => 'general',
            'blocks' => ['settings' => [], 'blocks' => [['id' => 'b1', 'type' => 'text', 'settings' => []]]],
            'html' => '<p>Hi</p>',
            'is_active' => true,
        ], $attributes));
    }

    // ------------------------------------------------------------- rendering

    public function test_the_index_renders(): void
    {
        $this->own(['name' => 'Spring promo']);

        $this->get('/templates')
            ->assertOk()
            ->assertSee('Spring promo');
    }

    public function test_the_builder_renders_for_a_new_template(): void
    {
        $this->get('/templates/create')
            ->assertOk()
            ->assertSee('knBlockBuilder', false);
    }

    public function test_the_builder_renders_for_an_existing_template(): void
    {
        $template = $this->own(['name' => 'Editable one']);

        $this->get("/templates/{$template->id}/edit")
            ->assertOk()
            ->assertSee('Editable one');
    }

    // --------------------------------------------------------------- saving

    public function test_saving_compiles_the_document(): void
    {
        $this->post('/templates', $this->payload())->assertRedirect();

        $template = EmailTemplate::where('name', 'Monthly update')->firstOrFail();

        $this->assertStringContainsString('Hello', (string) $template->html);
        $this->assertStringContainsString('{{unsubscribe_link}}', (string) $template->html,
            'The footer block is what puts the opt-out in the HTML.');
        $this->assertNotEmpty($template->plain_text, 'The text alternative is compiled at save time.');
        $this->assertFalse($template->is_system);
        $this->assertSame($this->owner->account_id, $template->account_id);
    }

    public function test_the_builder_may_post_the_document_as_json(): void
    {
        $payload = $this->payload();
        $payload['blocks'] = json_encode($payload['blocks']);
        $payload['settings'] = json_encode($payload['settings']);

        $this->post('/templates', $payload)->assertRedirect();

        $this->assertSame(1, EmailTemplate::where('name', 'Monthly update')->count());
    }

    public function test_an_unknown_block_type_is_rejected(): void
    {
        $this->post('/templates', $this->payload([
            'blocks' => [['type' => 'iframe', 'settings' => []]],
        ]))->assertSessionHasErrors('blocks.0.type');
    }

    public function test_a_template_needs_at_least_one_block(): void
    {
        $this->post('/templates', $this->payload(['blocks' => []]))
            ->assertSessionHasErrors('blocks');
    }

    // ------------------------------------------------------------- previewing

    public function test_the_preview_is_served_standalone_and_locked_down(): void
    {
        $template = $this->own(['html' => '<p>Hi {{first_name|there}}</p>']);

        $response = $this->get("/templates/{$template->id}/preview")->assertOk();

        $this->assertStringContainsString('text/html', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'),
            'The preview is embedded in an iframe; it must not be able to fetch anything.');
    }

    public function test_compiling_reports_unknown_tokens_without_saving(): void
    {
        $before = EmailTemplate::count();

        $response = $this->postJson('/templates/compile', [
            'settings' => [],
            'blocks' => [['type' => 'heading', 'settings' => ['text' => 'Hi {{frist_name}}']]],
        ])->assertOk();

        $response->assertJsonPath('unknown_tokens', ['frist_name']);
        $this->assertSame($before, EmailTemplate::count(), 'The live preview must not persist anything.');
    }

    /**
     * The endpoint that makes the nine ready-made templates worth having:
     * without it a campaign can link a template but never take its content.
     */
    public function test_the_document_endpoint_returns_a_normalised_document(): void
    {
        $this->seed(SystemTemplateSeeder::class);

        $system = EmailTemplate::withoutGlobalScopes()
            ->where('is_system', true)->where('category', 'welcome')->firstOrFail();

        $response = $this->getJson("/templates/{$system->id}/document")->assertOk();

        $response->assertJsonPath('name', $system->name);
        $this->assertNotEmpty($response->json('document.blocks'));
        $this->assertArrayHasKey('backgroundColor', $response->json('document.settings'));
    }

    public function test_the_document_endpoint_coerces_a_hostile_stored_colour(): void
    {
        // A document can be posted, so a stored colour is not trustworthy just
        // because a colour picker produced the last one.
        $template = $this->own([
            'blocks' => [
                'settings' => ['linkColor' => 'red;}</style><script>alert(1)</script>'],
                'blocks' => [['id' => 'b1', 'type' => 'text', 'settings' => []]],
            ],
        ]);

        $settings = $this->getJson("/templates/{$template->id}/document")
            ->assertOk()
            ->json('document.settings');

        $this->assertSame('#1d4ed8', $settings['linkColor'],
            'Anything that is not a literal colour falls back to the default.');
    }

    public function test_the_document_of_another_accounts_template_is_not_readable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival2@templates.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => EmailTemplate::create([
            'user_id' => $other->id, 'name' => 'Theirs', 'category' => 'general',
            'blocks' => ['settings' => [], 'blocks' => []], 'html' => '<p>x</p>', 'is_active' => true,
        ]));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->getJson("/templates/{$foreign->id}/document")->assertNotFound();
    }

    // ------------------------------------------------------- system templates

    public function test_system_templates_are_visible_but_not_editable(): void
    {
        $this->seed(SystemTemplateSeeder::class);

        $system = EmailTemplate::withoutGlobalScopes()->where('is_system', true)->firstOrFail();

        $this->get('/templates')->assertOk()->assertSee($system->name);

        $this->get("/templates/{$system->id}/edit")->assertForbidden();
        $this->put("/templates/{$system->id}", $this->payload())->assertForbidden();
        $this->delete("/templates/{$system->id}")->assertForbidden();
    }

    public function test_a_system_template_can_be_duplicated_into_the_account(): void
    {
        $this->seed(SystemTemplateSeeder::class);

        $system = EmailTemplate::withoutGlobalScopes()->where('is_system', true)->firstOrFail();

        $this->post("/templates/{$system->id}/duplicate")->assertRedirect();

        $copy = EmailTemplate::where('name', $system->name.' (copy)')->firstOrFail();

        $this->assertFalse($copy->is_system);
        $this->assertSame($this->owner->account_id, $copy->account_id);
        $this->assertSame($system->html, $copy->html);
    }

    public function test_every_seeded_template_carries_an_unsubscribe_link(): void
    {
        $this->seed(SystemTemplateSeeder::class);

        $templates = EmailTemplate::withoutGlobalScopes()->where('is_system', true)->get();

        $this->assertCount(9, $templates);

        foreach ($templates as $template) {
            $this->assertStringContainsString('{{unsubscribe_link}}', (string) $template->html,
                "{$template->name} would be unsendable without an opt-out.");
        }
    }

    // -------------------------------------------------------------- tenancy

    public function test_another_accounts_template_is_not_reachable(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@templates.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $foreign = app(TenantManager::class)->runAs($other->account_id, fn () => EmailTemplate::create([
            'user_id' => $other->id, 'name' => 'Their secret', 'category' => 'general',
            'blocks' => ['settings' => [], 'blocks' => []], 'html' => '<p>x</p>', 'is_active' => true,
        ]));

        app(TenantManager::class)->set($this->owner->account_id);

        $this->get('/templates')->assertOk()->assertDontSee('Their secret');
        $this->get("/templates/{$foreign->id}/edit")->assertNotFound();
        $this->get("/templates/{$foreign->id}/preview")->assertNotFound();
        $this->delete("/templates/{$foreign->id}")->assertNotFound();
    }

    // ----------------------------------------------------------- plan limits

    /**
     * A plan limit is not a crash and not a 403 page: the browser is sent back
     * with a readable message, and only XHR gets a 403 body. Both paths are
     * pinned here because the screens rely on the flash to explain the refusal.
     */
    public function test_the_builder_is_closed_when_the_plan_excludes_it(): void
    {
        $this->allow(['allow_template_builder' => false]);

        $this->get('/templates')->assertOk();

        $this->from('/templates')->get('/templates/create')
            ->assertRedirect('/templates')
            ->assertSessionHas('error');

        $this->from('/templates')->post('/templates', $this->payload())
            ->assertRedirect('/templates')
            ->assertSessionHas('error');

        $this->assertSame(0, EmailTemplate::count());
    }

    public function test_the_template_limit_is_enforced_on_save(): void
    {
        $this->allow(['max_templates' => 1]);

        $this->post('/templates', $this->payload(['name' => 'First']))->assertRedirect();

        $this->from('/templates')->post('/templates', $this->payload(['name' => 'Second']))
            ->assertRedirect('/templates')
            ->assertSessionHas('error');

        $this->assertSame(1, EmailTemplate::count());
    }

    public function test_a_limit_reached_over_xhr_answers_with_a_status_rather_than_a_redirect(): void
    {
        $this->allow(['max_templates' => 0]);

        $this->postJson('/templates', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('limit_key', 'max_templates');
    }
}
