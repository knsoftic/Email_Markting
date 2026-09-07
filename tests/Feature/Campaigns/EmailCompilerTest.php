<?php

namespace Tests\Feature\Campaigns;

use App\Services\Campaigns\BlockCatalogue;
use App\Services\Campaigns\EmailCompiler;
use Tests\TestCase;

/**
 * The compiler's output goes to real inboxes, so these tests pin the things
 * that are invisible in a browser preview but decide whether the email renders
 * in Outlook — and the things that decide whether it is safe at all.
 */
class EmailCompilerTest extends TestCase
{
    protected EmailCompiler $compiler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compiler = app(EmailCompiler::class);
    }

    /** @param array<int, array<string, mixed>> $blocks */
    protected function doc(array $blocks, array $settings = []): array
    {
        return ['settings' => $settings, 'blocks' => $blocks];
    }

    protected function block(string $type, array $settings = []): array
    {
        return ['id' => 'b1', 'type' => $type, 'settings' => $settings];
    }

    // --------------------------------------------------------------- shell

    public function test_the_document_shell_carries_what_outlook_needs(): void
    {
        $html = $this->compiler->compile($this->doc([$this->block('heading', ['text' => 'Hi'])]));

        $this->assertStringContainsString('xmlns:v="urn:schemas-microsoft-com:vml"', $html,
            'VML namespace is required for the button conditional to work.');
        $this->assertStringContainsString('<o:PixelsPerInch>96</o:PixelsPerInch>', $html,
            'Without this Outlook scales images by system DPI.');
        $this->assertStringContainsString('<!--[if mso]><table role="presentation" width="600"', $html,
            'Outlook ignores max-width, so it needs a ghost table at a fixed width.');
        $this->assertStringContainsString('x-apple-disable-message-reformatting', $html);
        $this->assertStringContainsString('@media only screen and (max-width:600px)', $html);
    }

    public function test_the_preheader_is_padded_so_body_copy_does_not_leak_into_the_preview(): void
    {
        $html = $this->compiler->compile($this->doc(
            [$this->block('text', ['html' => '<p>Body copy</p>'])],
            ['preheader' => 'Twenty percent off this week']
        ));

        $this->assertStringContainsString('Twenty percent off this week', $html);
        $this->assertStringContainsString('&#8199;&#65279;&#847;', $html,
            'The zero-width padding is what stops the first body line following it in the inbox list.');
    }

    public function test_no_preheader_means_no_hidden_div(): void
    {
        $html = $this->compiler->compile($this->doc([$this->block('heading', ['text' => 'Hi'])]));

        $this->assertStringNotContainsString('&#8199;&#65279;&#847;', $html);
    }

    // -------------------------------------------------------------- blocks

    public function test_a_button_renders_both_the_mso_and_the_normal_version(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('button', ['text' => 'Shop now', 'href' => 'https://example.com/shop']),
        ]));

        $this->assertStringContainsString('<v:roundrect', $html, 'Outlook needs VML or the button is a bare link.');
        $this->assertStringContainsString('<!--[if !mso]><!-- -->', $html);
        $this->assertStringContainsString('href="https://example.com/shop"', $html);
        $this->assertStringContainsString('Shop now', $html);
    }

    public function test_a_button_without_a_usable_link_is_dropped_not_rendered_broken(): void
    {
        foreach (['', 'javascript:alert(1)', 'ftp://x.test'] as $href) {
            $html = $this->compiler->compile($this->doc([
                $this->block('button', ['text' => 'Click', 'href' => $href]),
            ]));

            $this->assertStringNotContainsString('Click</a>', $html);
            $this->assertStringNotContainsString('javascript:', $html);
        }
    }

    public function test_columns_stack_on_mobile_and_stay_tabular_for_outlook(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('columns', [
                'count' => 2,
                'columns' => [['html' => '<p>Left</p>'], ['html' => '<p>Right</p>']],
            ]),
        ]));

        $this->assertStringContainsString('class="kn-col"', $html);
        $this->assertStringContainsString('.kn-col{display:block!important;width:100%!important;', $html);
        $this->assertStringContainsString('<td class="kn-col" width="50%"', $html);
        $this->assertStringContainsString('Left', $html);
        $this->assertStringContainsString('Right', $html);
    }

    public function test_a_missing_column_is_padded_rather_than_breaking_the_row(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('columns', ['count' => 3, 'columns' => [['html' => '<p>Only one</p>']]]),
        ]));

        $this->assertSame(3, substr_count($html, 'class="kn-col"'));
    }

    public function test_a_divider_is_a_table_cell_not_an_hr(): void
    {
        $html = $this->compiler->compile($this->doc([$this->block('divider', ['thickness' => 2])]));

        $this->assertStringNotContainsString('<hr', $html, 'Outlook styles <hr> its own way.');
        $this->assertStringContainsString('height:2px', $html);
    }

    public function test_an_image_without_a_source_is_skipped(): void
    {
        $html = $this->compiler->compile($this->doc([$this->block('image', ['src' => '', 'alt' => 'Nothing'])]));

        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_an_image_gets_the_attributes_clients_need(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('image', ['src' => 'https://cdn.example.com/a.png', 'alt' => 'Product', 'width' => 300]),
        ]));

        $this->assertStringContainsString('display:block', $html);
        $this->assertStringContainsString('border:0', $html);
        $this->assertStringContainsString('max-width:300px', $html);
        $this->assertStringContainsString('alt="Product"', $html);
    }

    // ------------------------------------------------------------- footer

    public function test_the_footer_leaves_the_unsubscribe_token_for_the_recipient_pass(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('footer', ['companyLine' => 'Acme Ltd', 'addressLine' => '1 High St']),
        ]));

        $this->assertStringContainsString('href="{{unsubscribe_link}}"', $html,
            'A hard-coded URL here would unsubscribe whoever it was compiled for.');
        $this->assertStringContainsString('href="{{preferences_link}}"', $html);
        $this->assertStringContainsString('Acme Ltd', $html);
    }

    // ------------------------------------------------------------ security

    public function test_the_custom_html_block_is_sanitised(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('html', ['html' => '<p>Fine</p><script>alert(1)</script><img src=x onerror=alert(2)>']),
        ]));

        $this->assertStringContainsString('<p>Fine</p>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_the_rich_text_block_is_sanitised_too(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('text', ['html' => '<p onclick="alert(1)">Hi</p>']),
        ]));

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('Hi', $html);
    }

    public function test_a_heading_cannot_inject_markup(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('heading', ['text' => '</h2><script>alert(1)</script>']),
        ]));

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_a_colour_setting_cannot_break_out_of_the_style_attribute(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('heading', ['text' => 'Hi', 'color' => 'red;"><script>alert(1)</script>']),
        ]));

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('color:#1e293b', $html, 'An invalid colour falls back, it does not pass through.');
    }

    // ------------------------------------------------------- normalisation

    public function test_an_unknown_block_type_is_dropped(): void
    {
        $html = $this->compiler->compile($this->doc([
            ['id' => 'x', 'type' => 'quantum_teleporter', 'settings' => ['payload' => 'raw json']],
            $this->block('heading', ['text' => 'Still here']),
        ]));

        $this->assertStringNotContainsString('raw json', $html);
        $this->assertStringContainsString('Still here', $html);
    }

    public function test_an_empty_document_still_produces_a_valid_shell(): void
    {
        $html = $this->compiler->compile(null);

        $this->assertStringContainsString('<html', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    public function test_unknown_settings_keys_are_ignored(): void
    {
        $normalised = app(BlockCatalogue::class)->normalise([
            'settings' => ['width' => 640, 'evil' => 'x'],
            'blocks' => [['type' => 'spacer', 'settings' => ['height' => 40, 'evil' => 'y']]],
        ]);

        $this->assertSame(640, $normalised['settings']['width']);
        $this->assertArrayNotHasKey('evil', $normalised['settings']);
        $this->assertArrayNotHasKey('evil', $normalised['blocks'][0]['settings']);
        $this->assertSame(40, $normalised['blocks'][0]['settings']['height']);
    }

    // ----------------------------------------------------------- plain text

    public function test_plain_text_is_built_from_blocks_not_stripped_from_html(): void
    {
        $text = $this->compiler->compileText($this->doc([
            $this->block('heading', ['text' => 'Spring sale']),
            $this->block('text', ['html' => '<p>Everything is <strong>20% off</strong> this week.</p>']),
            $this->block('button', ['text' => 'Shop now', 'href' => 'https://example.com/shop']),
            $this->block('footer', ['companyLine' => 'Acme Ltd']),
        ]));

        $this->assertStringContainsString('SPRING SALE', $text);
        $this->assertStringContainsString('Everything is 20% off this week.', $text);
        $this->assertStringContainsString('Shop now: https://example.com/shop', $text,
            'A text reader cannot click a button, so the URL has to be spelled out.');
        $this->assertStringContainsString('{{unsubscribe_link}}', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_plain_text_keeps_link_destinations(): void
    {
        $text = $this->compiler->compileText($this->doc([
            $this->block('text', ['html' => '<p>See our <a href="https://example.com/terms">terms</a>.</p>']),
        ]));

        $this->assertStringContainsString('terms (https://example.com/terms)', $text);
    }

    public function test_upper_casing_a_heading_does_not_break_its_tokens(): void
    {
        $text = $this->compiler->compileText($this->doc([
            $this->block('heading', ['text' => 'Spring sale, {{first_name|there}}']),
        ]));

        // A blanket strtoupper turns {{first_name}} into {{FIRST_NAME}}, which
        // matches no token — every recipient would silently get the fallback.
        $this->assertStringContainsString('SPRING SALE, {{first_name|there}}', $text);
        $this->assertStringNotContainsString('{{FIRST_NAME', $text);
    }

    public function test_line_height_is_pinned_for_word(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('heading', ['text' => 'Hi']),
            $this->block('text', ['html' => '<p>Body</p>']),
        ]));

        // Word inherits ratio line-heights badly and adds its own leading.
        $this->assertSame(2, substr_count($html, 'mso-line-height-rule:exactly'));
    }

    public function test_every_content_cell_declares_both_background_and_text_colour(): void
    {
        $html = $this->compiler->compile($this->doc([
            $this->block('text', ['html' => '<p>Body</p>']),
            $this->block('columns', ['count' => 2, 'columns' => [['html' => '<p>A</p>'], ['html' => '<p>B</p>']]]),
        ]));

        // A client that inverts only unstyled areas is what produces dark text
        // on a dark background. Declaring both is what prevents it.
        preg_match_all('/<td[^>]*style="([^"]*)"/', $html, $matches);

        foreach ($matches[1] as $style) {
            if (str_contains($style, 'color:') && ! str_contains($style, 'background-color:')) {
                $this->fail('A cell sets a text colour without a background: '.$style);
            }
        }

        $this->assertGreaterThan(0, count($matches[1]));
    }

    public function test_the_layout_survives_the_style_block_being_stripped(): void
    {
        // Gmail on Android with a non-Gmail account strips <style> entirely.
        // Nothing load-bearing may live only there.
        $html = $this->compiler->compile($this->doc([
            $this->block('columns', ['count' => 2, 'columns' => [['html' => '<p>A</p>'], ['html' => '<p>B</p>']]]),
        ]));

        $stripped = preg_replace('#<style.*?</style>#is', '', $html);

        $this->assertStringContainsString('width:600px', $stripped, 'The shell width must be inline.');
        $this->assertStringContainsString('width:50%', $stripped, 'Column widths must be inline.');
        $this->assertStringContainsString('font-family:', $stripped, 'Typography must be inline.');
    }

    /**
     * Also from the adversarial review. Document colours are interpolated into
     * style attributes AND into the <style> element, where HTML escaping is no
     * help: "red;}</style><script>" closes the element whatever you escape.
     * The document is stored JSON and can be posted, so "it is a colour picker
     * in the UI" is not a control.
     */
    public function test_document_settings_cannot_inject_css_or_markup(): void
    {
        foreach ([
            ['linkColor' => 'red;} </style><script>alert(1)</script><style>{'],
            ['backgroundColor' => '#fff"><script>alert(2)</script>'],
            ['contentBackground' => 'url(javascript:alert(3))'],
            ['textColor' => '#000;}</style><script>alert(4)</script>'],
            ['mutedColor' => 'expression(alert(5))'],
            ['fontFamily' => 'Arial;}</style><script>alert(6)</script>'],
            ['width' => '600"><script>alert(7)</script>'],
        ] as $settings) {
            $html = $this->compiler->compile($this->doc(
                [$this->block('heading', ['text' => 'Hi']), $this->block('footer', [])],
                $settings
            ));

            $this->assertStringNotContainsString('<script', $html,
                'Injection survived through: '.key($settings));
            $this->assertStringNotContainsString('javascript:', $html);
            $this->assertStringNotContainsString('expression(', $html);
        }
    }

    public function test_legitimate_document_settings_are_preserved(): void
    {
        $html = $this->compiler->compile($this->doc(
            [$this->block('heading', ['text' => 'Hi'])],
            ['linkColor' => '#ff0055', 'fontFamily' => "Georgia, 'Times New Roman', serif", 'width' => 640]
        ));

        $this->assertStringContainsString('#ff0055', $html);
        $this->assertStringContainsString('Georgia', $html);
        $this->assertStringContainsString('width:640px', $html);
    }
}
