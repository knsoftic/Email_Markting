<?php

namespace App\Services\Campaigns;

use Illuminate\Support\Str;

/**
 * Turns a block document into email HTML, and into a plain-text alternative.
 *
 * ── Why this looks like 2005 ────────────────────────────────────────────────
 * Email HTML is not web HTML. Outlook 2016-2021 on Windows renders with the
 * Word engine: no float, no flexbox, no max-width on a div, no background-image
 * on anything but a table, and media queries ignored entirely. So the layout is
 * nested tables with inline styles, and the responsive behaviour is a media
 * query that only the clients supporting it will act on.
 *
 * What that means concretely, stated plainly rather than pretended away:
 *   - Columns stack on phones in Apple Mail, Gmail and Outlook.com. In Outlook
 *     on Windows they stay side by side. That is the standard trade and the
 *     alternative (ghost tables plus inline-block) costs far more complexity
 *     than it buys for a 2-3 column layout.
 *   - Buttons use the padded-anchor technique with an MSO conditional so
 *     Outlook gets a real clickable rectangle rather than a text link.
 *   - Dark mode is largely outside anyone's control. Explicit background and
 *     text colours are set on every cell so that clients which only invert
 *     unstyled areas leave the email alone.
 *
 * Compilation happens on SAVE (so the template list can preview instantly) and
 * again on SEND (so a campaign always ships the content it was given, not
 * whatever the template later became).
 */
class EmailCompiler
{
    public function __construct(
        protected BlockCatalogue $catalogue,
        protected EmailHtmlSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string, mixed>|null  $document
     */
    public function compile(?array $document): string
    {
        $doc = $this->catalogue->normalise($document);
        $s = $doc['settings'];

        $rows = '';

        foreach ($doc['blocks'] as $block) {
            $rows .= $this->renderBlock($block, $s);
        }

        return $this->wrap($rows, $s);
    }

    /**
     * The plain-text alternative, built from the blocks rather than by
     * stripping tags off the HTML — a tag-stripped table layout reads as a
     * wall of fragments, which is why so many plain-text parts are unusable.
     *
     * @param  array<string, mixed>|null  $document
     */
    public function compileText(?array $document): string
    {
        $doc = $this->catalogue->normalise($document);
        $lines = [];

        foreach ($doc['blocks'] as $block) {
            $settings = $block['settings'];

            $piece = match ($block['type']) {
                'heading' => $this->upperPreservingTokens(trim((string) $settings['text'])),
                'text' => $this->htmlToText((string) $settings['html']),
                'button' => trim((string) $settings['text']).': '.trim((string) $settings['href']),
                'image', 'logo' => trim((string) ($settings['alt'] ?? '')) !== ''
                    ? '['.$settings['alt'].']'
                    : '',
                'divider' => str_repeat('-', 40),
                'columns' => collect((array) ($settings['columns'] ?? []))
                    ->map(fn ($col) => $this->htmlToText((string) ($col['html'] ?? '')))
                    ->filter()->implode("\n\n"),
                'social' => collect((array) ($settings['links'] ?? []))
                    ->filter(fn ($l) => filled($l['href'] ?? null))
                    ->map(fn ($l) => Str::title((string) $l['network']).': '.$l['href'])
                    ->implode("\n"),
                'footer' => trim(implode("\n", array_filter([
                    (string) ($settings['companyLine'] ?? ''),
                    (string) ($settings['addressLine'] ?? ''),
                    ($settings['showUnsubscribe'] ?? true)
                        ? ($settings['unsubscribeText'] ?? 'Unsubscribe').': {{unsubscribe_link}}'
                        : '',
                ]))),
                'html' => $this->htmlToText($this->sanitizer->sanitize((string) ($settings['html'] ?? ''))),
                default => '',
            };

            if (trim((string) $piece) !== '') {
                $lines[] = trim((string) $piece);
            }
        }

        return implode("\n\n", $lines)."\n";
    }

    // --------------------------------------------------------------- blocks

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $s  document settings
     */
    protected function renderBlock(array $block, array $s): string
    {
        $x = $block['settings'];

        $inner = match ($block['type']) {
            'heading' => $this->heading($x, $s),
            'text' => $this->text($x, $s),
            'image' => $this->image($x, $s),
            'logo' => $this->image($x, $s, isLogo: true),
            'button' => $this->button($x, $s),
            'divider' => $this->divider($x),
            'spacer' => $this->spacer($x),
            'columns' => $this->columns($x, $s),
            'social' => $this->social($x, $s),
            'footer' => $this->footer($x, $s),
            'html' => $this->customHtml($x),
            default => '',
        };

        if ($inner === '') {
            return '';
        }

        $padY = (int) ($x['paddingY'] ?? 0);

        // Every content cell states BOTH its background and its text colour.
        // Clients that invert only unstyled areas are the ones that produce
        // dark text on a dark background; declaring both prevents it. This is
        // the one dark-mode failure that makes an email unreadable rather than
        // merely ugly.
        return '<tr><td class="kn-pad" style="padding:'.$padY.'px 24px;'
            .'background-color:'.$s['contentBackground'].';color:'.$s['textColor'].';">'
            .$inner.'</td></tr>';
    }

    protected function heading(array $x, array $s): string
    {
        $level = max(1, min(3, (int) ($x['level'] ?? 2)));
        $size = (int) ($x['fontSize'] ?? 26);
        $color = $this->color($x['color'] ?? '', $s['textColor']);

        // mso-line-height-rule:exactly — Word inherits ratio line-heights
        // badly and will otherwise add its own leading to every heading.
        return '<h'.$level.' style="margin:0;font-family:'.$this->e($s['fontFamily'])
            .';font-size:'.$size.'px;mso-line-height-rule:exactly;line-height:'.round($size * 1.25).'px;'
            .'font-weight:700;color:'.$color.';text-align:'.$this->align($x).';">'
            .$this->e($x['text'] ?? '')
            .'</h'.$level.'>';
    }

    protected function text(array $x, array $s): string
    {
        $size = (int) ($x['fontSize'] ?? 15);
        $lh = (int) ($x['lineHeight'] ?? 24);
        $color = $this->color($x['color'] ?? '', $s['textColor']);

        // The rich-text body is author-supplied, so it goes through the same
        // sanitiser as the Custom HTML block.
        $html = $this->sanitizer->sanitize((string) ($x['html'] ?? ''));

        return '<div style="font-family:'.$this->e($s['fontFamily'])
            .';font-size:'.$size.'px;mso-line-height-rule:exactly;line-height:'.$lh.'px;color:'.$color
            .';text-align:'.$this->align($x).';">'.$html.'</div>';
    }

    protected function image(array $x, array $s, bool $isLogo = false): string
    {
        $src = trim((string) ($x['src'] ?? ''));

        if ($src === '' || ! $this->isSafeUrl($src)) {
            return '';
        }

        $width = (int) ($x['width'] ?? 0);
        $maxWidth = $width > 0 ? $width : ((int) $s['width'] - 48);

        $img = '<img src="'.$this->e($src).'" alt="'.$this->e($x['alt'] ?? '').'" width="'.$maxWidth.'"'
            .' style="display:block;border:0;outline:none;text-decoration:none;'
            .'width:100%;max-width:'.$maxWidth.'px;height:auto;" />';

        $href = trim((string) ($x['href'] ?? ''));

        if ($href !== '' && $this->isSafeUrl($href)) {
            $img = '<a href="'.$this->e($href).'" target="_blank" style="text-decoration:none;">'.$img.'</a>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="'.$this->align($x).'"'
            .' style="margin:0 auto;"><tr><td align="'.$this->align($x).'">'.$img.'</td></tr></table>';
    }

    /**
     * A padded anchor, with an MSO conditional so Outlook renders a real
     * rectangle. Outlook ignores padding on an <a>, which is why the plain
     * version alone shows up there as a bare text link.
     */
    protected function button(array $x, array $s): string
    {
        $href = trim((string) ($x['href'] ?? ''));

        if ($href === '' || ! $this->isSafeUrl($href)) {
            return '';
        }

        $bg = $this->color($x['backgroundColor'] ?? '', $s['linkColor']);
        $fg = $this->color($x['textColor'] ?? '', '#ffffff');
        $radius = (int) ($x['radius'] ?? 6);
        $padX = (int) ($x['paddingX'] ?? 28);
        $padY = (int) ($x['paddingY'] ?? 14);
        $size = (int) ($x['fontSize'] ?? 15);
        $label = $this->e($x['text'] ?? 'Click here');
        $safeHref = $this->e($href);

        $height = $size + ($padY * 2) + 4;

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="'.$this->align($x).'"'
            .' style="margin:0 auto;"><tr><td align="center" style="border-radius:'.$radius.'px;background:'.$bg.';">'
            .'<!--[if mso]>'
            .'<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"'
            .' href="'.$safeHref.'" style="height:'.$height.'px;v-text-anchor:middle;width:220px;"'
            .' arcsize="'.min(50, (int) round($radius / $height * 100)).'%" stroke="f" fillcolor="'.$bg.'">'
            .'<w:anchorlock/><center style="color:'.$fg.';font-family:'.$this->e($s['fontFamily']).';font-size:'.$size.'px;font-weight:bold;">'
            .$label.'</center></v:roundrect>'
            .'<![endif]-->'
            .'<!--[if !mso]><!-- -->'
            .'<a href="'.$safeHref.'" target="_blank" style="display:inline-block;padding:'.$padY.'px '.$padX.'px;'
            .'font-family:'.$this->e($s['fontFamily']).';font-size:'.$size.'px;font-weight:bold;line-height:1;'
            .'color:'.$fg.';text-decoration:none;border-radius:'.$radius.'px;background:'.$bg.';">'
            .$label.'</a>'
            .'<!--<![endif]-->'
            .'</td></tr></table>';
    }

    protected function divider(array $x): string
    {
        $color = $this->color($x['color'] ?? '', '#e2e8f0');
        $thickness = max(1, (int) ($x['thickness'] ?? 1));

        // A bordered table cell, not <hr> — Outlook styles <hr> its own way.
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr><td style="font-size:0;line-height:0;height:'.$thickness.'px;background:'.$color.';">&nbsp;</td></tr></table>';
    }

    protected function spacer(array $x): string
    {
        $height = max(1, (int) ($x['height'] ?? 24));

        return '<div style="font-size:0;line-height:0;height:'.$height.'px;">&nbsp;</div>';
    }

    /**
     * Columns as table cells. The media query stacks them on clients that
     * honour it; Outlook keeps them side by side, which is the documented
     * trade-off in the class docblock.
     */
    protected function columns(array $x, array $s): string
    {
        $columns = array_values((array) ($x['columns'] ?? []));
        $count = max(1, min(3, (int) ($x['count'] ?? count($columns) ?: 2)));
        $columns = array_slice(array_pad($columns, $count, ['html' => '']), 0, $count);
        $gap = (int) ($x['gap'] ?? 16);
        $pct = round(100 / $count, 4);

        $cells = '';

        foreach ($columns as $i => $column) {
            $html = $this->sanitizer->sanitize((string) ($column['html'] ?? ''));
            $padLeft = $i === 0 ? 0 : (int) round($gap / 2);
            $padRight = $i === $count - 1 ? 0 : (int) round($gap / 2);

            // The gap is padding on the INNER cell. Putting it on a wrapper
            // instead would add to the 50% width — email HTML has no
            // box-sizing:border-box — and the columns would wrap on desktop.
            $cells .= '<td class="kn-col" width="'.$pct.'%" valign="top"'
                .' style="width:'.$pct.'%;padding:0 '.$padRight.'px 0 '.$padLeft.'px;'
                .'font-family:'.$this->e($s['fontFamily']).';font-size:15px;'
                .'mso-line-height-rule:exactly;line-height:24px;'
                .'background-color:'.$s['contentBackground'].';color:'.$s['textColor'].';">'
                .$html.'</td>';
        }

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
            .'<tr class="kn-col-row">'.$cells.'</tr></table>';
    }

    protected function social(array $x, array $s): string
    {
        $links = collect((array) ($x['links'] ?? []))
            ->filter(fn ($l) => filled($l['href'] ?? null) && $this->isSafeUrl((string) $l['href']));

        if ($links->isEmpty()) {
            return '';
        }

        $cells = $links->map(function (array $link) use ($s) {
            $label = Str::title((string) $link['network']);

            // Text labels rather than hosted icon images: a remote icon that
            // 404s later leaves a broken image in every archived email, and
            // most clients block remote images by default anyway.
            return '<td style="padding:0 8px;"><a href="'.$this->e($link['href']).'" target="_blank"'
                .' style="font-family:'.$this->e($s['fontFamily']).';font-size:13px;color:'.$s['linkColor']
                .';text-decoration:underline;">'.$this->e($label).'</a></td>';
        })->implode('');

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="'.$this->align($x).'"'
            .' style="margin:0 auto;"><tr>'.$cells.'</tr></table>';
    }

    /**
     * The footer always carries the unsubscribe link. The token is left in
     * place for PersonalizationEngine to fill per recipient — a footer with a
     * hard-coded URL would unsubscribe the wrong person.
     */
    protected function footer(array $x, array $s): string
    {
        $size = (int) ($x['fontSize'] ?? 12);
        $align = $this->align($x);

        $parts = [];

        foreach (['companyLine', 'addressLine'] as $key) {
            if (trim((string) ($x[$key] ?? '')) !== '') {
                $parts[] = '<div style="margin:0 0 6px;">'.$this->e($x[$key]).'</div>';
            }
        }

        if ($x['showUnsubscribe'] ?? true) {
            $parts[] = '<div style="margin:6px 0 0;">'
                .'<a href="{{unsubscribe_link}}" style="color:'.$s['mutedColor'].';text-decoration:underline;">'
                .$this->e($x['unsubscribeText'] ?? 'Unsubscribe').'</a>'
                .'&nbsp;&nbsp;·&nbsp;&nbsp;'
                .'<a href="{{preferences_link}}" style="color:'.$s['mutedColor'].';text-decoration:underline;">'
                .$this->e($x['preferencesText'] ?? 'Email preferences').'</a>'
                .'</div>';
        }

        return '<div style="font-family:'.$this->e($s['fontFamily']).';font-size:'.$size.'px;line-height:'
            .round($size * 1.6).'px;color:'.$s['mutedColor'].';text-align:'.$align.';">'
            .implode('', $parts).'</div>';
    }

    protected function customHtml(array $x): string
    {
        return $this->sanitizer->sanitize((string) ($x['html'] ?? ''));
    }

    // --------------------------------------------------------------- wrapper

    /**
     * @param  array<string, mixed>  $s
     */
    protected function wrap(string $rows, array $s): string
    {
        $width = (int) $s['width'];
        $font = $this->e($s['fontFamily']);

        // Zero-width joiners pad the preheader so the client does not pull the
        // first line of body copy into the inbox preview after it.
        $preheader = trim((string) $s['preheader']) !== ''
            ? '<div style="display:none;font-size:1px;color:'.$s['contentBackground'].';line-height:1px;'
                .'max-height:0;max-width:0;opacity:0;overflow:hidden;">'
                .$this->e($s['preheader'])
                .str_repeat('&#8199;&#65279;&#847; ', 60)
                .'</div>'
            : '';

        return '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            .'<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
            .'<head>'
            .'<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />'
            .'<meta name="viewport" content="width=device-width, initial-scale=1" />'
            .'<meta name="x-apple-disable-message-reformatting" />'
            .'<meta name="color-scheme" content="light" /><meta name="supported-color-schemes" content="light" />'
            .'<title></title>'
            // Outlook needs this or it renders images at their native DPI.
            .'<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch>'
            .'</o:OfficeDocumentSettings></xml><![endif]-->'
            .'<style type="text/css">'
            .'body{margin:0;padding:0;width:100%!important;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}'
            .'table{border-collapse:collapse;}'
            .'img{-ms-interpolation-mode:bicubic;}'
            .'a{color:'.$s['linkColor'].';}'
            .'@media only screen and (max-width:'.$width.'px){'
            .'.kn-shell{width:100%!important;}'
            .'.kn-pad{padding-left:16px!important;padding-right:16px!important;}'
            .'.kn-col{display:block!important;width:100%!important;padding:0 0 16px 0!important;}'
            .'}'
            .'</style>'
            .'</head>'
            .'<body style="margin:0;padding:0;background:'.$s['backgroundColor'].';">'
            .$preheader
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"'
            .' style="background:'.$s['backgroundColor'].';">'
            .'<tr><td align="center" style="padding:24px 8px;">'
            // The MSO ghost table gives Outlook a fixed width, since it will
            // not honour max-width on the real one.
            .'<!--[if mso]><table role="presentation" width="'.$width.'" align="center"><tr><td><![endif]-->'
            .'<table role="presentation" class="kn-shell" cellpadding="0" cellspacing="0" border="0" width="'.$width.'"'
            .' style="width:'.$width.'px;max-width:'.$width.'px;background:'.$s['contentBackground'].';'
            .'font-family:'.$font.';color:'.$s['textColor'].';">'
            .$rows
            .'</table>'
            .'<!--[if mso]></td></tr></table><![endif]-->'
            .'</td></tr></table>'
            .'</body></html>';
    }

    // --------------------------------------------------------------- helpers

    protected function align(array $x): string
    {
        return in_array($x['align'] ?? 'left', ['left', 'center', 'right'], true)
            ? $x['align']
            : 'left';
    }

    protected function color(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) ? $value : $fallback;
    }

    /** Only schemes a mail client will act on, plus our own token. */
    protected function isSafeUrl(string $url): bool
    {
        if (str_starts_with($url, '{{')) {
            return true;
        }

        return (bool) preg_match('#^(https?://|mailto:|tel:)#i', $url);
    }

    protected function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
    }

    /**
     * Upper-cases a heading for the plain-text part WITHOUT touching the
     * personalization tokens inside it.
     *
     * A blanket strtoupper turns {{first_name}} into {{FIRST_NAME}}, which no
     * longer matches any token, so every recipient silently gets the fallback
     * instead of their name. Found by rendering a real email, not by reading
     * the code.
     */
    protected function upperPreservingTokens(string $text): string
    {
        return (string) preg_replace_callback(
            '/\{\{[^}]*\}\}|[^{]+/u',
            fn (array $m) => str_starts_with($m[0], '{{') ? $m[0] : mb_strtoupper($m[0]),
            $text
        );
    }

    /** Readable text from a small fragment of HTML, for the plain-text part. */
    protected function htmlToText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $html = preg_replace('#</(p|div|li|h[1-6])>#i', "\n", $html) ?? $html;
        $html = preg_replace('#<li[^>]*>#i', '- ', $html) ?? $html;

        // Keep the destination of a link, since a text reader cannot click it.
        $html = preg_replace_callback(
            '#<a[^>]+href="([^"]+)"[^>]*>(.*?)</a>#is',
            fn ($m) => trim(strip_tags($m[2])).' ('.$m[1].')',
            $html
        ) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
