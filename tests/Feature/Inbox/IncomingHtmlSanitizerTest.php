<?php

namespace Tests\Feature\Inbox;

use App\Models\Email;
use App\Models\EmailAttachment;
use App\Models\Mailbox;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Inbox\IncomingHtmlSanitizer;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The security boundary of the inbox.
 *
 * A received body was written by anyone on the internet and is rendered back
 * inside a logged-in session. Three things protect that render — this
 * sanitiser, a sandboxed iframe, and a CSP on the frame document — and this
 * file is about the first one. Every case here is something a real message has
 * carried.
 */
class IncomingHtmlSanitizerTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Mailbox $mailbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@sanitise.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        app(TenantManager::class)->set($this->owner->account_id);

        $this->mailbox = Mailbox::create([
            'user_id' => $this->owner->id, 'name' => 'Support', 'email' => 'support@senders.test',
            'imap_host' => 'imap.senders.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@senders.test', 'imap_password' => 'secret',
        ]);
    }

    protected function sanitizer(): IncomingHtmlSanitizer
    {
        return app(IncomingHtmlSanitizer::class);
    }

    protected function email(string $html, ?string $text = null): Email
    {
        static $seq = 0;
        $seq++;

        return Email::create([
            'mailbox_id' => $this->mailbox->id,
            'message_id' => "m{$seq}@example.com",
            'subject' => 'Test',
            'from_email' => 'stranger@example.com',
            'body_html' => $html,
            'body_text' => $text,
            'received_at' => now(),
        ]);
    }

    protected function clean(string $html, bool $showRemote = false): string
    {
        return $this->sanitizer()->prepare($this->email($html), $showRemote)['html'];
    }

    // ------------------------------------------------------------- scripting

    public function test_script_tags_are_removed_with_their_contents(): void
    {
        $html = $this->clean('<p>Hello</p><script>alert(document.cookie)</script>');

        $this->assertStringContainsString('Hello', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('alert(', $html,
            'Dropping the tag but keeping its source leaves the payload on the page as text.');
    }

    public function test_event_handler_attributes_are_removed(): void
    {
        foreach ([
            '<p onclick="alert(1)">x</p>',
            '<div onmouseover="alert(1)">x</div>',
            '<img src="https://example.com/a.png" onerror="alert(1)">',
            '<a href="https://example.com" onfocus="alert(1)">x</a>',
        ] as $payload) {
            $html = $this->clean($payload);

            $this->assertStringNotContainsString('onclick', $html);
            $this->assertStringNotContainsString('onmouseover', $html);
            $this->assertStringNotContainsString('onerror', $html);
            $this->assertStringNotContainsString('onfocus', $html);
        }
    }

    public function test_javascript_urls_are_removed_from_links(): void
    {
        foreach ([
            '<a href="javascript:alert(1)">click</a>',
            "<a href=\"java\tscript:alert(1)\">click</a>",
            "<a href=\"java\nscript:alert(1)\">click</a>",
            '<a href="JaVaScRiPt:alert(1)">click</a>',
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">click</a>',
            '<a href="vbscript:msgbox(1)">click</a>',
        ] as $payload) {
            $html = mb_strtolower($this->clean($payload));

            $this->assertStringNotContainsString('javascript', $html, "Survived: {$payload}");
            $this->assertStringNotContainsString('vbscript', $html, "Survived: {$payload}");
            $this->assertStringNotContainsString('data:text/html', $html, "Survived: {$payload}");
        }
    }

    public function test_style_blocks_and_dangerous_elements_are_dropped(): void
    {
        $html = $this->clean(
            '<style>body{background:url(javascript:alert(1))}</style>'
            .'<iframe src="https://evil.test"></iframe>'
            .'<object data="x"></object><embed src="x">'
            .'<form action="https://evil.test"><input name="password"></form>'
            .'<svg onload="alert(1)"></svg><math></math>'
            .'<base href="https://evil.test/">'
            .'<link rel="stylesheet" href="https://evil.test/x.css">'
            .'<p>Real content</p>'
        );

        $this->assertStringContainsString('Real content', $html);

        foreach (['<style', '<iframe', '<object', '<embed', '<form', '<input',
            '<svg', '<math', '<base', '<link'] as $tag) {
            $this->assertStringNotContainsString($tag, mb_strtolower($html), "{$tag} survived.");
        }
    }

    public function test_a_base_tag_cannot_repoint_the_documents_links(): void
    {
        // <base> would silently rewrite every relative URL in the message.
        $html = $this->clean('<base href="https://evil.test/"><a href="/settings">Click</a>');

        $this->assertStringNotContainsString('evil.test', $html);
    }

    // ---------------------------------------------------------- remote images

    public function test_remote_images_are_blocked_by_default(): void
    {
        $result = $this->sanitizer()->prepare(
            $this->email('<p>Hi</p><img src="https://tracker.example.com/pixel.gif">'),
            showRemoteImages: false
        );

        $this->assertSame(1, $result['blocked_images']);
        $this->assertTrue($result['has_remote']);
        // Checked as an attribute, not a substring: data-kn-blocked-src="..."
        // ends with src="..." and would satisfy a naive assertion.
        $this->assertDoesNotMatchRegularExpression('/<img[^>]*\ssrc=/i', $result['html'],
            'Loading it would tell the sender the message was opened, and give them the reader IP.');
        $this->assertStringContainsString('data-kn-blocked-src', $result['html'],
            'The address is kept so "show images" is a re-render, not a second fetch.');
    }

    public function test_remote_images_load_when_the_reader_asks(): void
    {
        $result = $this->sanitizer()->prepare(
            $this->email('<img src="https://cdn.example.com/logo.png">'),
            showRemoteImages: true
        );

        $this->assertSame(0, $result['blocked_images']);
        $this->assertStringContainsString('https://cdn.example.com/logo.png', $result['html']);
    }

    public function test_a_data_uri_image_is_not_blocked(): void
    {
        // Self-contained: nothing is fetched, so nothing is disclosed.
        $gif = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

        $result = $this->sanitizer()->prepare($this->email('<img src="'.$gif.'">'), false);

        $this->assertSame(0, $result['blocked_images']);
        $this->assertFalse($result['has_remote']);
        $this->assertStringContainsString('data:image/gif', $result['html']);
    }

    // ------------------------------------------------------ inline images

    public function test_a_cid_reference_is_rewritten_to_our_own_route(): void
    {
        $email = $this->email('<p>See below</p><img src="cid:logo123">');

        EmailAttachment::create([
            'email_id' => $email->id,
            'account_id' => $this->owner->account_id,
            'name' => 'logo.png',
            'mime_type' => 'image/png',
            'size' => 100,
            'disk' => 'local',
            'path' => 'attachments/x/logo.png',
            'content_id' => 'logo123',
            'is_inline' => true,
        ]);

        $html = $this->sanitizer()->prepare($email->fresh(), false)['html'];

        $this->assertStringContainsString('/inline/', $html);
        $this->assertStringNotContainsString('cid:', $html);
    }

    public function test_a_cid_reference_with_no_matching_part_is_removed(): void
    {
        $html = $this->sanitizer()->prepare(
            $this->email('<p>Text</p><img src="cid:missing">'), false
        )['html'];

        $this->assertStringContainsString('Text', $html);
        $this->assertStringNotContainsString('cid:missing', $html,
            'A dead cid: renders as a broken-image icon, which reads as our bug.');
        $this->assertStringNotContainsString('<img', $html);
    }

    // -------------------------------------------------------------- content

    public function test_ordinary_formatting_survives(): void
    {
        $html = $this->clean(
            '<h2>Heading</h2><p><strong>Bold</strong> and <em>italic</em> and '
            .'<a href="https://example.com">a link</a></p>'
            .'<table><tr><td>Cell</td></tr></table><ul><li>Item</li></ul>'
        );

        foreach (['<h2', '<strong', '<em', '<a href="https://example.com"', '<table', '<td', '<li'] as $needle) {
            $this->assertStringContainsString($needle, $html, "{$needle} was stripped from a normal message.");
        }
    }

    public function test_inline_styles_survive_because_email_depends_on_them(): void
    {
        $html = $this->clean('<p style="color:#ff0000;font-size:18px">Red</p>');

        $this->assertStringContainsString('style=', $html);
    }

    public function test_a_text_only_message_is_escaped_not_interpreted(): void
    {
        $email = $this->email('', '<script>alert(1)</script> plain & simple');

        $html = $this->sanitizer()->prepare($email, false)['html'];

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html,
            'The text part is no more trustworthy than the HTML part, only less capable.');
        $this->assertStringContainsString('&amp;', $html);
    }

    public function test_a_bare_url_in_plain_text_is_not_turned_into_a_link(): void
    {
        $email = $this->email('', 'Visit https://example.com/offer now');

        $html = $this->sanitizer()->prepare($email, false)['html'];

        $this->assertStringNotContainsString('<a ', $html,
            'Making a stranger text clickable is a favour the reader did not ask for.');
        $this->assertStringContainsString('https://example.com/offer', $html);
    }

    public function test_an_empty_body_produces_nothing_rather_than_breaking(): void
    {
        $result = $this->sanitizer()->prepare($this->email('', null), false);

        $this->assertSame('', $result['html']);
        $this->assertSame(0, $result['blocked_images']);
    }

    // ------------------------------------------------------------- excerpt

    public function test_the_excerpt_carries_no_markup(): void
    {
        $email = $this->email('<p>Hello <strong>there</strong> &amp; welcome</p>');
        $email->forceFill(['preview' => null])->save();

        $excerpt = $this->sanitizer()->excerpt($email->fresh());

        $this->assertStringNotContainsString('<', $excerpt);
        $this->assertStringContainsString('Hello there', $excerpt);
        $this->assertStringContainsString('&', $excerpt);
    }

    public function test_a_very_long_body_does_not_make_a_very_long_excerpt(): void
    {
        $email = $this->email('<p>'.str_repeat('word ', 500).'</p>');
        $email->forceFill(['preview' => null])->save();

        $this->assertLessThanOrEqual(140, mb_strlen($this->sanitizer()->excerpt($email->fresh())));
    }

    // --------------------------------------------------------- mutation xss

    public function test_known_mutation_payloads_do_not_survive(): void
    {
        foreach ([
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<svg><style><img src=x onerror=alert(1)></style></svg>',
            '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
            '<form><math><mtext></form><form><mglyph><style></math><img src onerror=alert(1)>',
            '<xmp><p title="</xmp><img src=x onerror=alert(1)>">',
            '<listing><img src=x onerror=alert(1)></listing>',
        ] as $payload) {
            $html = mb_strtolower($this->clean($payload));

            $this->assertStringNotContainsString('onerror', $html, "Survived: {$payload}");
            $this->assertStringNotContainsString('alert(1)', $html, "Survived: {$payload}");
        }
    }
}
