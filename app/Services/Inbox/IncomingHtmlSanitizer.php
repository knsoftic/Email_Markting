<?php

namespace App\Services\Inbox;

use App\Models\Email;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Prepares a received email body for display.
 *
 * This is the most hostile input the application handles. The body was written
 * by anyone on the internet, it is stored verbatim — throwing away the original
 * would make the inbox lie about what arrived — and it is rendered back inside
 * a logged-in session. Three separate things protect that render, and none of
 * them is sufficient alone:
 *
 *  1. This sanitiser, a parser-based allow-list (symfony/html-sanitizer, a
 *     W3C HTML-sanitizer-API implementation). Hand-rolled tag stripping loses
 *     to mutation XSS in ways that are genuinely hard to foresee.
 *  2. A sandboxed iframe at render time, so even a bypass runs in an opaque
 *     origin with no script permission and no access to the app's DOM.
 *  3. A Content-Security-Policy on the frame document itself.
 *
 * ── Remote images are blocked by default, and that is a privacy decision ────
 * A remote image in an email is a tracking pixel unless proven otherwise —
 * this application ships one of its own, which is exactly how it knows. Loading
 * them on open would tell every sender the moment their mail was read, and the
 * reader's IP address with it. So they are held back, the reader is told how
 * many, and loading them is a choice they make.
 */
class IncomingHtmlSanitizer
{
    /** Tags that survive. Everything else is dropped. */
    protected const ALLOWED = [
        'p', 'br', 'hr', 'div', 'span', 'section', 'article', 'header', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup', 'small', 'mark',
        'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre', 'code',
        'a', 'img',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'caption', 'colgroup', 'col',
        'center', 'font', 'abbr', 'cite', 'q', 'time',
    ];

    protected const GLOBAL_ATTRIBUTES = [
        'style', 'class', 'align', 'valign', 'width', 'height', 'bgcolor', 'dir', 'title', 'lang',
    ];

    /** @var array<string, array<int, string>> */
    protected const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'border'],
        'table' => ['cellpadding', 'cellspacing', 'border', 'role'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'font' => ['color', 'face', 'size'],
        'col' => ['span'],
        'colgroup' => ['span'],
    ];

    protected ?HtmlSanitizer $sanitizer = null;

    /**
     * @return array{html: string, blocked_images: int, has_remote: bool}
     */
    public function prepare(Email $email, bool $showRemoteImages = false): array
    {
        $html = (string) $email->body_html;

        if (trim($html) === '') {
            return ['html' => $this->fromPlainText((string) $email->body_text), 'blocked_images' => 0, 'has_remote' => false];
        }

        // cid: is resolved BEFORE sanitising. symfony/html-sanitizer does not
        // treat cid as a media scheme it can allow, so it strips the src and
        // leaves a bare <img> — inline images in received mail would never
        // display. Rewriting first turns each one into an ordinary URL on our
        // own origin, which the allow-list then accepts on its merits.
        //
        // Parsing the raw body with DOMDocument to do that is safe: it is a
        // parser, not an executor, and the sanitiser still runs afterwards as
        // the actual security boundary.
        $resolved = $this->resolveInlineImages($html, $email);

        $clean = $this->sanitizer()->sanitize($resolved);

        return $this->rewriteImages($clean, $email, $showRemoteImages);
    }

    /**
     * Points every cid: image at the attachment it names, and removes the ones
     * that name nothing.
     */
    protected function resolveInlineImages(string $html, Email $email): string
    {
        if (stripos($html, 'cid:') === false) {
            return $html;
        }

        $inline = $this->inlineAttachments($email);
        $document = $this->parse($html);

        /** @var DOMElement $image */
        foreach ((new DOMXPath($document))->query('//img') as $image) {
            $src = trim((string) $image->getAttribute('src'));

            if (! str_starts_with(mb_strtolower($src), 'cid:')) {
                continue;
            }

            $cid = trim(mb_substr($src, 4), '<> ');

            if (isset($inline[$cid])) {
                $image->setAttribute('src', route('inbox.attachment.inline', [
                    'email' => $email->id,
                    'attachment' => $inline[$cid],
                ]));

                continue;
            }

            // The referenced part is not in the message. An <img> pointing at
            // a dead cid: renders as a broken-image icon in every browser,
            // which reads as our bug rather than a malformed message.
            $image->parentNode?->removeChild($image);
        }

        $root = $document->getElementById('kn-root');

        return $root ? $this->innerHtml($document, $root) : $html;
    }

    /**
     * Loads a body fragment into a DOM.
     *
     * The wrapper keeps DOMDocument from inventing a doctype, and the XML
     * declaration forces UTF-8 so a non-ASCII subject does not come back as
     * mojibake.
     */
    protected function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="kn-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * Rewrites the image sources.
     *
     * Done on the DOM of the ALREADY-SANITISED document rather than with a
     * regex over the raw body: by this point the markup is well formed and the
     * only job left is deciding what each <img> points at.
     *
     * @return array{html: string, blocked_images: int, has_remote: bool}
     */
    protected function rewriteImages(string $html, Email $email, bool $showRemote): array
    {
        if (! str_contains($html, '<img')) {
            return ['html' => $html, 'blocked_images' => 0, 'has_remote' => false];
        }

        $document = $this->parse($html);

        // Our own inline route, so an attachment that came WITH the message is
        // not mistaken for a remote tracker and held back.
        $ownOrigin = rtrim((string) config('app.url'), '/');

        $blocked = 0;
        $hasRemote = false;

        /** @var DOMElement $image */
        foreach ((new DOMXPath($document))->query('//img') as $image) {
            $src = trim((string) $image->getAttribute('src'));

            if ($src === '') {
                continue;
            }

            // data: images are self-contained — nothing is fetched, so nothing
            // is disclosed. The sanitiser has already restricted what they can
            // be.
            if (str_starts_with(mb_strtolower($src), 'data:image/')) {
                continue;
            }

            // An inline part of this message, already resolved to our own
            // origin. It came with the mail; loading it tells the sender
            // nothing.
            if ($ownOrigin !== '' && str_starts_with($src, $ownOrigin.'/inbox/')) {
                continue;
            }

            $hasRemote = true;

            if ($showRemote) {
                continue;
            }

            // Held back. The real address is kept in a data attribute so
            // "show images" is a re-render, not a second fetch of the message.
            $image->setAttribute('data-kn-blocked-src', $src);
            $image->removeAttribute('src');
            $image->setAttribute('class', trim($image->getAttribute('class').' kn-blocked-image'));
            $blocked++;
        }

        $root = $document->getElementById('kn-root');

        return [
            'html' => $root ? $this->innerHtml($document, $root) : $html,
            'blocked_images' => $blocked,
            'has_remote' => $hasRemote,
        ];
    }

    /**
     * Content-ID => attachment id, for the parts this message carries.
     *
     * @return array<string, int>
     */
    protected function inlineAttachments(Email $email): array
    {
        $map = [];

        foreach ($email->attachments as $attachment) {
            $cid = trim((string) $attachment->content_id, '<> ');

            if ($cid !== '') {
                $map[$cid] = (int) $attachment->id;
            }
        }

        return $map;
    }

    protected function innerHtml(DOMDocument $document, DOMElement $element): string
    {
        $html = '';

        foreach ($element->childNodes as $child) {
            $html .= $document->saveHTML($child);
        }

        return $html;
    }

    /**
     * A text-only message, presented as HTML.
     *
     * Escaped first, then given paragraph breaks — the text part of a message
     * is no more trustworthy than the HTML part, it is simply less capable.
     * Bare URLs are NOT auto-linked: turning text a stranger sent into a
     * clickable link is doing the sender a favour the reader did not ask for.
     */
    public function fromPlainText(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div style="white-space:pre-wrap;font-family:inherit;">'.$escaped.'</div>';
    }

    /**
     * A short, safe summary for a list row — no markup at all.
     *
     * strip_tags() removes the tags but keeps everything BETWEEN them, so on a
     * message with no stored preview it would put the contents of <style> and
     * <script> on the row. Blade escapes it, so it is not an injection — it is
     * simply a wall of CSS where the sentence should be, which is what most
     * marketing mail would show. Those elements go first, contents and all.
     *
     * The body is clipped to a working window BEFORE stripping, and the second
     * pattern catches a block whose closing tag fell outside that window —
     * clipping first and stripping second is what let the CSS survive.
     */
    public function excerpt(Email $email, int $length = 140): string
    {
        $text = (string) ($email->preview ?: $email->body_text);

        if (trim($text) === '') {
            $html = mb_substr((string) $email->body_html, 0, 20000);
            $html = (string) preg_replace('#<(script|style|head|title)\b[^>]*>.*?</\1\s*>#is', ' ', $html);
            $html = (string) preg_replace('#<(script|style|head|title)\b[^>]*>.*$#is', ' ', $html);
            $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);

            $text = strip_tags($html);
        }

        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', mb_substr($text, 0, 2000)));

        return mb_substr($text, 0, $length);
    }

    protected function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // http/https/mailto/tel only. This is what stops javascript: and
            // data: URIs in an href.
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            // data: allowed for media only: an inline image discloses nothing,
            // and blocking it would break signatures that legitimately embed
            // a small logo. cid: is handled after sanitising, from the
            // attachments this message actually carries.
            // No 'cid' here: the sanitiser has no notion of it and would strip
            // the src. Those references are resolved to real URLs before this
            // runs, so by now every image is http(s) or data:.
            ->allowMediaSchemes(['http', 'https', 'data'])
            ->allowRelativeLinks(false)
            ->allowRelativeMedias(false)
            // Dropped WITH their contents, so a removed <script> does not
            // leave its source visible as text.
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('frame')
            ->dropElement('frameset')
            ->dropElement('object')
            ->dropElement('embed')
            ->dropElement('applet')
            ->dropElement('form')
            ->dropElement('input')
            ->dropElement('textarea')
            ->dropElement('select')
            ->dropElement('option')
            ->dropElement('button')
            ->dropElement('link')
            ->dropElement('meta')
            // <base> would silently repoint every relative URL in the message.
            ->dropElement('base')
            ->dropElement('svg')
            ->dropElement('math')
            ->dropElement('video')
            ->dropElement('audio')
            ->dropElement('source')
            ->dropElement('track')
            ->dropElement('canvas')
            ->dropElement('template')
            ->dropElement('slot')
            ->dropElement('portal');

        foreach (self::ALLOWED as $tag) {
            $config = $config->allowElement($tag, array_merge(
                self::GLOBAL_ATTRIBUTES,
                self::TAG_ATTRIBUTES[$tag] ?? []
            ));
        }

        return $this->sanitizer = new HtmlSanitizer($config);
    }
}
