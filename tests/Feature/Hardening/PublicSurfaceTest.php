<?php

namespace Tests\Feature\Hardening;

use App\Models\Campaign;
use App\Models\Email;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The parts of the application a stranger can reach.
 *
 * Every case here comes from an external penetration test of the deployed site
 * on 2026-09-07. They are written as tests rather than fixed and forgotten
 * because each one is a header or a policy that is easy to lose in a refactor
 * and impossible to notice has gone.
 */
class PublicSurfaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);
    }

    // ------------------------------------------------------- security headers

    /**
     * The finding that mattered: the sign-in page could be framed.
     *
     * An attacker loads it in an iframe on their own site, covers it with their
     * own interface, and collects a click — or a password — the person believed
     * they were giving to us. Nothing inside the application can detect it.
     */
    public function test_the_sign_in_page_cannot_be_framed(): void
    {
        $response = $this->get('/login')->assertOk();

        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertStringContainsString(
            "frame-ancestors 'none'",
            (string) $response->headers->get('Content-Security-Policy')
        );
    }

    public function test_every_public_page_carries_the_headers(): void
    {
        foreach (['/', '/login', '/register', '/forgot-password'] as $url) {
            $response = $this->get($url)->assertOk();
            $headers = $response->headers;

            $this->assertSame('nosniff', $headers->get('X-Content-Type-Options'), $url);
            $this->assertSame('DENY', $headers->get('X-Frame-Options'), $url);
            $this->assertSame('strict-origin-when-cross-origin', $headers->get('Referrer-Policy'), $url);
            $this->assertNotNull($headers->get('Permissions-Policy'), $url);

            $csp = (string) $headers->get('Content-Security-Policy');

            foreach ([
                "frame-ancestors 'none'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
            ] as $directive) {
                $this->assertStringContainsString($directive, $csp, "{$url} is missing {$directive}");
            }
        }
    }

    /**
     * The trap in adding those headers globally.
     *
     * The inbox body is served into a `sandbox=""` iframe on our own page and
     * sets a far stricter policy of its own. `X-Frame-Options: DENY` blocks even
     * a same-origin frame, so applying it here would stop the application
     * rendering the mail it just fetched.
     */
    public function test_the_email_frame_keeps_its_own_policy_and_is_not_blocked(): void
    {
        $owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Framed Ltd', 'name' => 'Owner', 'email' => 'owner@framed.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $owner->forceFill(['email_verified_at' => now()])->save();

        $subscription = $owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_inbox' => true, 'max_mailboxes' => 5,
        ])]);
        $owner->account->refresh();

        $this->actingAs($owner);
        app(TenantManager::class)->set($owner->account_id);

        $mailbox = Mailbox::create([
            'account_id' => $owner->account_id, 'user_id' => $owner->id,
            'name' => 'Support', 'email' => 'support@framed.test',
            'imap_host' => 'imap.framed.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@framed.test', 'imap_password' => 'secret',
        ]);

        $email = Email::create([
            'account_id' => $owner->account_id, 'mailbox_id' => $mailbox->id,
            'direction' => 'incoming', 'folder_type' => 'inbox',
            'message_id' => '<framed@example.test>', 'subject' => 'Hello',
            'from_email' => 'someone@example.com', 'to' => ['support@framed.test'],
            'body_html' => '<p>Hello there</p>', 'received_at' => now(),
        ]);

        $response = $this->get("/inbox/{$email->id}/body")->assertOk();

        $this->assertNull($response->headers->get('X-Frame-Options'),
            'X-Frame-Options: DENY here would stop the inbox rendering its own message.');

        $csp = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("default-src 'none'", $csp,
            "The frame's own, stricter policy must survive the global middleware.");

        // The harmless headers are still applied to it.
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    // -------------------------------------------------------- password policy

    /**
     * `12345678` is close to the most common password in every breach corpus
     * ever published, and registration accepted it.
     */
    public function test_registration_refuses_a_weak_password(): void
    {
        foreach (['12345678', 'password', 'abcdefghij'] as $weak) {
            $this->post('/register', [
                'company_name' => 'Weak Ltd',
                'name' => 'Weak Person',
                'email' => 'weak'.md5($weak).'@example.com',
                'password' => $weak,
                'password_confirmation' => $weak,
                'terms' => '1',
            ])->assertSessionHasErrors('password');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_accepts_a_reasonable_password(): void
    {
        $this->post('/register', [
            'company_name' => 'Strong Ltd',
            'name' => 'Careful Person',
            'email' => 'careful@example.com',
            'password' => 'harbour-lantern-93',
            'password_confirmation' => 'harbour-lantern-93',
            'terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'careful@example.com']);
    }

    /**
     * Five call sites used to override the shared policy with `->min(8)`, which
     * silently put every one of them back to the weak default.
     */
    public function test_no_caller_weakens_the_shared_password_policy(): void
    {
        $offenders = [];

        foreach (['app/Http/Controllers', 'app/Http/Requests'] as $dir) {
            $path = base_path($dir);

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                if (str_contains(file_get_contents($file->getPathname()), 'Password::defaults()->min(')) {
                    $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $offenders,
            'These override the shared policy and weaken it: '.implode(', ', $offenders));
    }

    // ------------------------------------------------------------ error pages

    public function test_an_unknown_url_gets_the_branded_error_page(): void
    {
        $response = $this->get('/this-page-does-not-exist')->assertNotFound();

        $response->assertSee('That page does not exist')
            ->assertSee('Error 404')
            ->assertDontSee('nginx');
    }

    public function test_the_error_page_tells_crawlers_to_stay_away(): void
    {
        $this->get('/this-page-does-not-exist')
            ->assertNotFound()
            ->assertSee('noindex', false);
    }

    // --------------------------------------------------------------- favicon

    public function test_a_default_favicon_is_linked_and_is_not_empty(): void
    {
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')),
            'favicon.ico shipped as a zero-byte file, so every browser tab showed a broken icon.');

        $this->assertGreaterThan(0, filesize(public_path('favicon.svg')));

        $this->get('/login')->assertOk()->assertSee('favicon.svg', false);
    }

    // -------------------------------------------------------------- headings

    /**
     * The marketing panel beside the form used an `<h1>`, and so did the form,
     * so a screen reader announced the marketing line as the subject of the
     * sign-in page. Four other auth pages had no heading at all.
     */
    public function test_every_auth_page_has_exactly_one_top_level_heading(): void
    {
        $pages = [
            '/login' => 'Sign in',
            '/register' => null,
            '/forgot-password' => 'Reset your password',
            '/reset-password/'.str_repeat('a', 32) => 'Choose a new password',
        ];

        foreach ($pages as $url => $heading) {
            $html = $this->get($url)->assertOk()->getContent();

            $this->assertSame(1, substr_count($html, '<h1'),
                "{$url} must have exactly one <h1>.");

            if ($heading !== null) {
                $this->assertStringContainsString($heading, $html);
            }
        }
    }

    // -------------------------------------------------- robots and sitemap

    /**
     * The rules that matter are the Disallows. Unsubscribe, preferences and
     * tracking URLs are signed and minted for one recipient; they reach the
     * open web whenever a newsletter is forwarded to a public archive, and a
     * crawler following a tracking pixel records an open nobody performed.
     */
    public function test_robots_keeps_crawlers_off_per_recipient_urls(): void
    {
        $body = $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->getContent();

        foreach ([
            'Disallow: /unsubscribe',
            'Disallow: /preferences',
            'Disallow: /t/',
            'Disallow: /login',
            'Disallow: /admin',
        ] as $rule) {
            $this->assertStringContainsString($rule, $body);
        }

        // Absolute, as the sitemap protocol requires — which is why this is a
        // route and not a static file.
        $this->assertStringContainsString('Sitemap: '.url('/sitemap.xml'), $body);
    }

    /**
     * Asserted by fetching every URL it lists rather than by counting them: a
     * count has to be edited whenever a page is added, which teaches nobody
     * anything, while a sitemap advertising a page that 404s is a real defect.
     */
    public function test_every_url_in_the_sitemap_is_a_page_that_loads(): void
    {
        $body = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->getContent();

        $xml = simplexml_load_string($body);

        $this->assertNotFalse($xml, 'The sitemap must be parseable XML.');
        $this->assertGreaterThan(0, count($xml->url));

        $listed = [];

        foreach ($xml->url as $url) {
            $loc = (string) $url->loc;
            $path = parse_url($loc, PHP_URL_PATH) ?: '/';
            $listed[] = $path;

            $this->assertStringStartsWith(url('/'), $loc,
                "The sitemap must list absolute URLs on this install: {$loc}");

            $this->get($path)->assertOk("The sitemap lists {$path}, which does not load.");
        }

        $this->assertContains('/', $listed, 'The landing page must be listed.');
    }

    /** The user guide is a real page, served without a login. */
    public function test_the_guide_is_public_and_renders(): void
    {
        $this->get('/guide')
            ->assertOk()
            ->assertSee('Platform kaise chalayen')
            ->assertSee('Plan comparison');
    }

    /** A sitemap that disagreed with robots.txt would be worse than none. */
    public function test_the_sitemap_lists_nothing_robots_disallows(): void
    {
        $robots = $this->get('/robots.txt')->getContent();
        $xml = simplexml_load_string($this->get('/sitemap.xml')->getContent());

        $disallowed = [];

        foreach (explode("\n", $robots) as $line) {
            if (str_starts_with(trim($line), 'Disallow: ')) {
                $disallowed[] = trim(substr(trim($line), 10));
            }
        }

        foreach ($xml->url as $url) {
            $path = parse_url((string) $url->loc, PHP_URL_PATH) ?: '/';

            foreach ($disallowed as $rule) {
                $this->assertFalse($rule !== '/' && str_starts_with($path, $rule),
                    "The sitemap lists {$path}, which robots.txt disallows.");
            }
        }
    }
}
