<?php

namespace Tests\Feature\Campaigns;

use App\Models\Campaign;
use App\Models\CustomField;
use App\Models\Subscriber;
use App\Models\Suppression;
use App\Models\Unsubscribe;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\LinkSigner;
use App\Services\Campaigns\PersonalizationEngine;
use App\Services\Contacts\SuppressionService;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected PersonalizationEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Acme Ltd', 'name' => 'Owner', 'email' => 'owner@acme.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->engine = app(PersonalizationEngine::class);
    }

    protected function contact(array $attributes = []): Subscriber
    {
        return Subscriber::factory()->forAccount($this->owner->account)->create($attributes);
    }

    // ---------------------------------------------------------- substitution

    public function test_core_tokens_are_filled(): void
    {
        $contact = $this->contact([
            'name' => 'Jane Cooper', 'first_name' => 'Jane', 'last_name' => 'Cooper',
            'email' => 'jane@example.com', 'company' => 'Cooper Ltd', 'city' => 'Lahore',
        ]);
        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(['name' => 'Spring Sale']);

        $out = $this->engine->render(
            'Hi {{first_name}} at {{company}} in {{city}} — {{campaign_name}} ({{email}})',
            $contact, $campaign
        );

        $this->assertSame('Hi Jane at Cooper Ltd in Lahore — Spring Sale (jane@example.com)', $out);
    }

    public function test_a_fallback_covers_a_missing_value(): void
    {
        $contact = $this->contact(['first_name' => null, 'name' => null]);

        $this->assertSame(
            'Hi there,',
            $this->engine->render('Hi {{first_name|there}},', $contact)
        );
    }

    public function test_an_unknown_token_is_removed_not_shown_to_the_recipient(): void
    {
        $contact = $this->contact();

        $this->assertSame(
            'Hi ,',
            $this->engine->render('Hi {{frist_name}},', $contact),
            'A typo must never reach the inbox as literal braces.'
        );

        $this->assertSame(['frist_name'], $this->engine->unknownTokens('Hi {{frist_name}} and {{email}}'));
    }

    public function test_whitespace_inside_the_braces_is_tolerated(): void
    {
        $contact = $this->contact(['first_name' => 'Jane']);

        $this->assertSame('Jane', $this->engine->render('{{  first_name  }}', $contact));
    }

    public function test_custom_fields_resolve(): void
    {
        CustomField::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tier', 'key' => 'tier', 'type' => 'text',
        ]);

        $contact = $this->contact()->forceFill(['custom' => ['tier' => 'gold']]);
        $contact->save();

        $this->assertSame('You are gold', $this->engine->render('You are {{custom.tier}}', $contact));
    }

    // -------------------------------------------------------------- security

    public function test_a_contact_name_cannot_inject_markup(): void
    {
        $contact = $this->contact(['name' => '<script>alert(1)</script>', 'first_name' => null, 'last_name' => null]);

        $out = $this->engine->render('Hello {{name}}', $contact);

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function test_a_contact_value_cannot_break_out_of_an_attribute(): void
    {
        $contact = $this->contact(['company' => '" onmouseover="alert(1)']);

        $out = $this->engine->render('<a title="{{company}}">x</a>', $contact);

        $this->assertStringNotContainsString('onmouseover="alert', $out);
        $this->assertStringContainsString('&quot;', $out);
    }

    public function test_a_javascript_url_in_a_contact_value_is_neutralised(): void
    {
        $contact = $this->contact()->forceFill(['custom' => ['site' => 'javascript:alert(1)']]);
        $contact->save();
        CustomField::factory()->forAccount($this->owner->account)->create(['key' => 'site', 'name' => 'Site']);

        $out = app(PersonalizationEngine::class)->render('<a href="{{custom.site}}">Visit</a>', $contact->fresh());

        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_a_token_inside_a_contact_value_is_not_expanded_again(): void
    {
        $contact = $this->contact(['name' => '{{email}}', 'first_name' => null, 'last_name' => null, 'email' => 'real@example.com']);

        $out = $this->engine->render('Hi {{name}}', $contact);

        $this->assertSame('Hi {{email}}', $out,
            'The replacement output must never be re-scanned, or a contact record becomes a template.');
    }

    public function test_plain_text_rendering_does_not_html_escape(): void
    {
        $contact = $this->contact(['company' => 'Smith & Sons']);

        $this->assertSame('Smith & Sons', $this->engine->renderText('{{company}}', $contact));
        $this->assertSame('Smith &amp; Sons', $this->engine->render('{{company}}', $contact));
    }

    // ----------------------------------------------------------------- links

    public function test_the_unsubscribe_link_is_signed_and_per_recipient(): void
    {
        $a = $this->contact();
        $b = $this->contact();
        $campaign = Campaign::factory()->forAccount($this->owner->account)->create();

        $signer = app(LinkSigner::class);

        $urlA = $signer->unsubscribeUrl($a, $campaign);
        $urlB = $signer->unsubscribeUrl($b, $campaign);

        $this->assertNotSame($urlA, $urlB);
        $this->assertStringContainsString('signature=', $urlA);
        $this->assertStringContainsString('/unsubscribe/'.$a->id, $urlA);
    }

    public function test_a_preview_gets_an_inert_link_rather_than_a_broken_one(): void
    {
        $url = app(LinkSigner::class)->unsubscribeUrl(new Subscriber);

        $this->assertStringEndsWith('/unsubscribe/preview', $url);
        $this->get($url)->assertOk()->assertSee('Nothing happened');
    }

    public function test_list_unsubscribe_headers_are_produced(): void
    {
        $contact = $this->contact();

        $headers = app(LinkSigner::class)->listUnsubscribeHeaders($contact);

        $this->assertStringStartsWith('<http', $headers['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $headers['List-Unsubscribe-Post']);
    }

    // ----------------------------------------------------- the opt-out pages

    public function test_a_get_never_unsubscribes_anyone(): void
    {
        $contact = $this->contact();
        $url = app(LinkSigner::class)->unsubscribeUrl($contact);

        $this->get($url)->assertOk()->assertSee('Unsubscribe');

        $this->assertFalse(app(SuppressionService::class)->isSuppressed($contact->email, $contact->account_id),
            'A scanner pre-fetching the link must not opt the person out.');
        $this->assertSame('active', $contact->fresh()->status);
    }

    public function test_confirming_unsubscribes_and_suppresses(): void
    {
        $contact = $this->contact();
        $campaign = Campaign::factory()->forAccount($this->owner->account)->create();
        $url = app(LinkSigner::class)->unsubscribeUrl($contact, $campaign);

        $this->post($url)->assertOk()->assertSee('You have been unsubscribed');

        $this->assertTrue(app(SuppressionService::class)->isSuppressed($contact->email, $contact->account_id));
        $this->assertSame('unsubscribed', $contact->fresh()->status);
        $this->assertSame(1, Unsubscribe::withoutGlobalScopes()->where('subscriber_id', $contact->id)->count());
        $this->assertSame(1, (int) $campaign->fresh()->unsubscribed_count);
    }

    public function test_one_click_unsubscribe_answers_plainly(): void
    {
        $contact = $this->contact();
        $url = app(LinkSigner::class)->oneClickUrl($contact);

        $this->post($url)->assertOk()->assertSee('Unsubscribed');

        $this->assertTrue(app(SuppressionService::class)->isSuppressed($contact->email, $contact->account_id));
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $victim = $this->contact();
        $attacker = $this->contact();

        $url = app(LinkSigner::class)->unsubscribeUrl($attacker);
        $forged = str_replace('/unsubscribe/'.$attacker->id, '/unsubscribe/'.$victim->id, $url);

        $this->get($forged)->assertForbidden();
        $this->post($forged)->assertForbidden();

        $this->assertSame('active', $victim->fresh()->status,
            'Editing the id in the address bar must not opt somebody else out.');
    }

    public function test_unsubscribing_twice_does_not_duplicate_the_record(): void
    {
        $contact = $this->contact();
        $url = app(LinkSigner::class)->unsubscribeUrl($contact);

        $this->post($url)->assertOk();
        $this->post($url)->assertOk();

        $this->assertSame(1, Unsubscribe::withoutGlobalScopes()->where('subscriber_id', $contact->id)->count());
        $this->assertSame(1, Suppression::withoutGlobalScopes()->where('email', $contact->email)->count());
    }

    public function test_the_page_names_the_sending_business_not_the_platform(): void
    {
        $contact = $this->contact();

        $this->get(app(LinkSigner::class)->unsubscribeUrl($contact))
            ->assertOk()
            ->assertSee('Acme Ltd', false);
    }

    /**
     * Found by an adversarial review of the shipped code, not by writing it.
     * A browser strips whitespace and control characters inside a URL scheme
     * before resolving it, so href="java&#9;script:alert(1)" is live. The
     * value comes from a public sign-up form, which makes the attacker an
     * unauthenticated stranger and the target the app's own preview pane.
     */
    public function test_whitespace_cannot_smuggle_a_dangerous_scheme_past_the_check(): void
    {
        CustomField::factory()->forAccount($this->owner->account)->create(['key' => 'site', 'name' => 'Site']);

        foreach ([
            "java	script:alert(1)",
            "java
script:alert(1)",
            "javascript:alert(1)",
            ' javascript:alert(1)',
            'JaVaScRiPt:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'vbscript:msgbox(1)',
            'file:///etc/passwd',
        ] as $payload) {
            $contact = $this->contact()->forceFill(['custom' => ['site' => $payload]]);
            $contact->save();

            $out = app(PersonalizationEngine::class)
                ->render('<a href="{{custom.site}}">go</a>', $contact->fresh());

            $this->assertStringContainsString('href="#"', $out,
                'This payload survived the scheme check: '.addcslashes($payload, "	
"));
        }
    }

    public function test_ordinary_web_and_mail_links_still_work(): void
    {
        CustomField::factory()->forAccount($this->owner->account)->create(['key' => 'site', 'name' => 'Site']);

        foreach ([
            'https://example.com/offer?a=1&b=2',
            'HTTP://Example.com',
            'mailto:hello@example.com',
            'tel:+441234567890',
            'Just some text, not a URL',
        ] as $value) {
            $contact = $this->contact()->forceFill(['custom' => ['site' => $value]]);
            $contact->save();

            $out = app(PersonalizationEngine::class)
                ->render('<a href="{{custom.site}}">go</a>', $contact->fresh());

            $this->assertStringNotContainsString('href="#"', $out,
                'A legitimate value was wrongly blocked: '.$value);
        }
    }
}
