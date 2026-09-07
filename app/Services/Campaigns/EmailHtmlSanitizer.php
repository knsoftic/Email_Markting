<?php

namespace App\Services\Campaigns;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Cleans the Custom HTML block.
 *
 * A marketing user pasting a snippet is untrusted input twice over: it goes
 * out to recipients, and — the sharper risk — it is rendered back inside the
 * app's own preview. A stored <script> there would run with a logged-in
 * session, so this is an XSS boundary, not a tidiness pass.
 *
 * It uses symfony/html-sanitizer (a W3C HTML-sanitizer-API implementation)
 * rather than regexes. Hand-rolled tag stripping loses to mutation XSS in
 * ways that are genuinely hard to foresee; a parser-based allow-list does not.
 *
 * The allow-list is deliberately the set of tags email clients actually
 * render — there is no point permitting <video> when no inbox will play it.
 */
class EmailHtmlSanitizer
{
    protected ?HtmlSanitizer $sanitizer = null;

    /** Tags that survive. Everything else is dropped, contents kept. */
    protected const ALLOWED = [
        'p', 'br', 'hr', 'div', 'span',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'small',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th',
        'center', 'font',
    ];

    /**
     * Attributes allowed on every permitted tag. `style` is included because
     * email HTML is inline-styled by necessity; the sanitizer still parses and
     * re-serialises it, so a style attribute cannot smuggle markup out.
     */
    protected const GLOBAL_ATTRIBUTES = ['style', 'class', 'align', 'valign', 'width', 'height', 'bgcolor', 'dir', 'title'];

    /** @var array<string, array<int, string>> */
    protected const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'border'],
        'table' => ['cellpadding', 'cellspacing', 'border', 'role'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'font' => ['color', 'face', 'size'],
    ];

    public function sanitize(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        return trim($this->sanitizer()->sanitize($html));
    }

    protected function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // http/https/mailto/tel only. This is what stops
            // javascript: and data: URIs in an href or src.
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            // Hosted images only. A cid: reference needs a matching inline
            // attachment, which a pasted snippet can never have, so allowing
            // the scheme here would only produce broken images.
            ->allowMediaSchemes(['http', 'https'])
            // Relative URLs would be meaningless in an inbox anyway.
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            // Anything not allow-listed is removed along with its contents,
            // so a dropped <script> does not leave its source on the page.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('button')
            ->dropElement('link')
            ->dropElement('meta')
            ->dropElement('base')
            ->dropElement('svg')
            ->dropElement('math');

        foreach (self::ALLOWED as $tag) {
            $config = $config->allowElement($tag, array_merge(
                self::GLOBAL_ATTRIBUTES,
                self::TAG_ATTRIBUTES[$tag] ?? []
            ));
        }

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
