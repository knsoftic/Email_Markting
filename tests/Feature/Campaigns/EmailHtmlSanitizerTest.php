<?php

namespace Tests\Feature\Campaigns;

use App\Services\Campaigns\EmailHtmlSanitizer;
use Tests\TestCase;

/**
 * The Custom HTML block is user-supplied markup that ends up in two dangerous
 * places: a recipient's inbox, and the app's own preview pane where a logged-in
 * session is available. These tests pin the boundary.
 */
class EmailHtmlSanitizerTest extends TestCase
{
    protected EmailHtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = app(EmailHtmlSanitizer::class);
    }

    public function test_harmless_email_markup_survives(): void
    {
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">'
            .'<tr><td align="center" style="padding:16px"><p><strong>Hello</strong> world</p>'
            .'<a href="https://example.com/offer" target="_blank">See the offer</a></td></tr></table>';

        $out = $this->sanitizer->sanitize($html);

        $this->assertStringContainsString('<table', $out);
        $this->assertStringContainsString('cellpadding="0"', $out);
        $this->assertStringContainsString('style="padding:16px"', $out);
        $this->assertStringContainsString('href="https://example.com/offer"', $out);
        $this->assertStringContainsString('<strong>Hello</strong>', $out);
    }

    public function test_script_tags_are_removed_with_their_contents(): void
    {
        $out = $this->sanitizer->sanitize('<p>Hi</p><script>alert(document.cookie)</script>');

        $this->assertStringContainsString('<p>Hi</p>', $out);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(', $out,
            'Dropping the tag but leaving its source would still execute once re-parsed.');
    }

    public function test_event_handler_attributes_are_stripped(): void
    {
        foreach ([
            '<img src="https://x.test/a.png" onerror="alert(1)">',
            '<div onclick="alert(1)">click</div>',
            '<a href="https://x.test" onmouseover="alert(1)">x</a>',
        ] as $html) {
            $out = $this->sanitizer->sanitize($html);

            $this->assertStringNotContainsString('onerror', $out);
            $this->assertStringNotContainsString('onclick', $out);
            $this->assertStringNotContainsString('onmouseover', $out);
            $this->assertStringNotContainsString('alert(1)', $out);
        }
    }

    public function test_dangerous_url_schemes_are_rejected(): void
    {
        foreach ([
            '<a href="javascript:alert(1)">go</a>',
            '<a href="JaVaScRiPt:alert(1)">go</a>',
            '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">go</a>',
            '<a href="vbscript:msgbox(1)">go</a>',
        ] as $html) {
            $out = $this->sanitizer->sanitize($html);

            $this->assertStringNotContainsString('javascript:', strtolower($out));
            $this->assertStringNotContainsString('vbscript:', strtolower($out));
            $this->assertStringNotContainsString('data:text/html', strtolower($out));
        }
    }

    public function test_iframes_forms_and_embeds_are_removed(): void
    {
        $out = $this->sanitizer->sanitize(
            '<p>ok</p><iframe src="https://evil.test"></iframe>'
            .'<form action="https://evil.test"><input name="password"></form>'
            .'<object data="x"></object><embed src="x">'
        );

        $this->assertStringContainsString('<p>ok</p>', $out);

        foreach (['<iframe', '<form', '<input', '<object', '<embed'] as $tag) {
            $this->assertStringNotContainsString($tag, $out);
        }
    }

    public function test_style_blocks_and_imports_are_removed(): void
    {
        $out = $this->sanitizer->sanitize('<style>@import url("https://evil.test/x.css");</style><p>Hi</p>');

        $this->assertStringNotContainsString('<style', $out);
        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringContainsString('<p>Hi</p>', $out);
    }

    public function test_svg_is_removed_because_it_carries_script(): void
    {
        $out = $this->sanitizer->sanitize('<svg><script>alert(1)</script></svg><p>after</p>');

        $this->assertStringNotContainsString('<svg', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<p>after</p>', $out);
    }

    public function test_a_mutation_xss_attempt_does_not_survive_reparsing(): void
    {
        // Classic mXSS shapes: markup that changes meaning when re-parsed.
        foreach ([
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<svg></p><style><a id="</style><img src=1 onerror=alert(2)>">',
            '<math><mtext><table><mglyph><style><img src=1 onerror=alert(3)>',
        ] as $payload) {
            $out = $this->sanitizer->sanitize($payload);

            $this->assertStringNotContainsString('onerror', $out);
            $this->assertStringNotContainsString('alert(', $out);
        }
    }

    public function test_empty_input_is_handled(): void
    {
        $this->assertSame('', $this->sanitizer->sanitize(null));
        $this->assertSame('', $this->sanitizer->sanitize('   '));
    }

    public function test_relative_urls_are_dropped_because_an_inbox_has_no_base(): void
    {
        $out = $this->sanitizer->sanitize('<a href="/offers">Offers</a>');

        $this->assertStringNotContainsString('href="/offers"', $out,
            'A relative link resolves against the mail client, not the sender.');
    }

    public function test_only_hosted_image_urls_are_kept(): void
    {
        $kept = $this->sanitizer->sanitize('<img src="https://cdn.example.com/logo.png" alt="Logo">');
        $this->assertStringContainsString('https://cdn.example.com/logo.png', $kept);

        // A cid: reference needs a matching inline attachment, which a pasted
        // snippet never has — allowing it would only produce broken images.
        $dropped = $this->sanitizer->sanitize('<img src="cid:logo123" alt="Logo">');
        $this->assertStringNotContainsString('cid:logo123', $dropped);
    }
}
