<?php

namespace App\Services\Campaigns;

/**
 * The block types the builder offers, their default settings, and the fields
 * the editor renders for each.
 *
 * Adding a block type means adding one entry here and one render method on
 * EmailCompiler — nothing else in the app needs to know about it. The same
 * shape as SegmentFieldRegistry, for the same reason.
 */
class BlockCatalogue
{
    /** Document-level defaults, applied when a template omits them. */
    public const DOCUMENT_DEFAULTS = [
        'width' => 600,
        'backgroundColor' => '#f1f5f9',
        'contentBackground' => '#ffffff',
        'fontFamily' => 'Arial, Helvetica, sans-serif',
        'textColor' => '#1e293b',
        'mutedColor' => '#64748b',
        'linkColor' => '#1d4ed8',
        'preheader' => '',
    ];

    /**
     * Settings whose value is interpolated into CSS and must therefore be a
     * literal, not merely escaped. HTML escaping does not help inside a
     * <style> element — "red;}</style><script>…" closes it regardless.
     */
    public const COLOUR_SETTINGS = [
        'backgroundColor', 'contentBackground', 'textColor', 'mutedColor', 'linkColor',
    ];

    /** A #rgb / #rrggbb / #rrggbbaa value, or the fallback. Nothing else. */
    public function safeColour(mixed $value, string $fallback): string
    {
        $value = trim((string) $value);

        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $value)
            ? $value
            : $fallback;
    }

    /**
     * A font stack is interpolated into CSS too. Letters, digits, spaces,
     * commas, hyphens and quotes cover every real stack; anything else is a
     * breakout attempt, not a typeface.
     */
    public function safeFontStack(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' && preg_match('/^[A-Za-z0-9 ,\-\x27"]+$/u', $value)
            ? $value
            : self::DOCUMENT_DEFAULTS['fontFamily'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'heading' => [
                'label' => 'Heading',
                'icon' => 'H',
                'defaults' => [
                    'text' => 'Your headline here',
                    'level' => 2,
                    'align' => 'left',
                    'color' => '',
                    'fontSize' => 26,
                    'paddingY' => 12,
                ],
                'fields' => [
                    ['key' => 'text', 'type' => 'text', 'label' => 'Text'],
                    ['key' => 'level', 'type' => 'select', 'label' => 'Level', 'options' => [1, 2, 3]],
                    ['key' => 'fontSize', 'type' => 'number', 'label' => 'Font size (px)'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                    ['key' => 'color', 'type' => 'color', 'label' => 'Colour'],
                    ['key' => 'paddingY', 'type' => 'number', 'label' => 'Vertical padding'],
                ],
            ],

            'text' => [
                'label' => 'Text',
                'icon' => 'T',
                'defaults' => [
                    'html' => '<p>Write your message here. You can use {{first_name}} to personalise it.</p>',
                    'align' => 'left',
                    'fontSize' => 15,
                    'lineHeight' => 24,
                    'color' => '',
                    'paddingY' => 10,
                ],
                'fields' => [
                    ['key' => 'html', 'type' => 'richtext', 'label' => 'Content'],
                    ['key' => 'fontSize', 'type' => 'number', 'label' => 'Font size (px)'],
                    ['key' => 'lineHeight', 'type' => 'number', 'label' => 'Line height (px)'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                    ['key' => 'color', 'type' => 'color', 'label' => 'Colour'],
                ],
            ],

            'image' => [
                'label' => 'Image',
                'icon' => 'IMG',
                'defaults' => [
                    'src' => '',
                    'alt' => '',
                    'href' => '',
                    'width' => 0, // 0 = full content width
                    'align' => 'center',
                    'paddingY' => 10,
                ],
                'fields' => [
                    ['key' => 'src', 'type' => 'image', 'label' => 'Image URL'],
                    ['key' => 'alt', 'type' => 'text', 'label' => 'Alt text'],
                    ['key' => 'href', 'type' => 'url', 'label' => 'Links to'],
                    ['key' => 'width', 'type' => 'number', 'label' => 'Width (px, 0 = full)'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                ],
            ],

            'button' => [
                'label' => 'Button',
                'icon' => 'BTN',
                'defaults' => [
                    'text' => 'Click here',
                    'href' => 'https://example.com',
                    'backgroundColor' => '',
                    'textColor' => '#ffffff',
                    'align' => 'center',
                    'radius' => 6,
                    'paddingX' => 28,
                    'paddingY' => 14,
                    'fontSize' => 15,
                ],
                'fields' => [
                    ['key' => 'text', 'type' => 'text', 'label' => 'Label'],
                    ['key' => 'href', 'type' => 'url', 'label' => 'Links to'],
                    ['key' => 'backgroundColor', 'type' => 'color', 'label' => 'Background'],
                    ['key' => 'textColor', 'type' => 'color', 'label' => 'Text colour'],
                    ['key' => 'radius', 'type' => 'number', 'label' => 'Corner radius'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                ],
            ],

            'divider' => [
                'label' => 'Divider',
                'icon' => '—',
                'defaults' => ['color' => '#e2e8f0', 'thickness' => 1, 'paddingY' => 16],
                'fields' => [
                    ['key' => 'color', 'type' => 'color', 'label' => 'Colour'],
                    ['key' => 'thickness', 'type' => 'number', 'label' => 'Thickness (px)'],
                    ['key' => 'paddingY', 'type' => 'number', 'label' => 'Vertical padding'],
                ],
            ],

            'spacer' => [
                'label' => 'Spacer',
                'icon' => '↕',
                'defaults' => ['height' => 24],
                'fields' => [
                    ['key' => 'height', 'type' => 'number', 'label' => 'Height (px)'],
                ],
            ],

            'columns' => [
                'label' => 'Columns',
                'icon' => '▥',
                'defaults' => [
                    'count' => 2,
                    'gap' => 16,
                    'paddingY' => 10,
                    'columns' => [
                        ['html' => '<p>Left column</p>'],
                        ['html' => '<p>Right column</p>'],
                    ],
                ],
                'fields' => [
                    ['key' => 'count', 'type' => 'select', 'label' => 'Columns', 'options' => [2, 3]],
                    ['key' => 'gap', 'type' => 'number', 'label' => 'Gap (px)'],
                    ['key' => 'columns', 'type' => 'columns', 'label' => 'Column content'],
                ],
            ],

            'logo' => [
                'label' => 'Logo',
                'icon' => '◆',
                'defaults' => [
                    'src' => '',
                    'alt' => '',
                    'href' => '',
                    'width' => 160,
                    'align' => 'center',
                    'paddingY' => 20,
                ],
                'fields' => [
                    ['key' => 'src', 'type' => 'image', 'label' => 'Logo URL'],
                    ['key' => 'alt', 'type' => 'text', 'label' => 'Alt text'],
                    ['key' => 'href', 'type' => 'url', 'label' => 'Links to'],
                    ['key' => 'width', 'type' => 'number', 'label' => 'Width (px)'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                ],
            ],

            'social' => [
                'label' => 'Social icons',
                'icon' => '◎',
                'defaults' => [
                    'align' => 'center',
                    'paddingY' => 16,
                    'links' => [
                        ['network' => 'facebook', 'href' => ''],
                        ['network' => 'instagram', 'href' => ''],
                        ['network' => 'linkedin', 'href' => ''],
                    ],
                ],
                'fields' => [
                    ['key' => 'links', 'type' => 'social', 'label' => 'Networks'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                ],
            ],

            'footer' => [
                'label' => 'Footer',
                'icon' => '⌄',
                'defaults' => [
                    'companyLine' => '',
                    'addressLine' => '',
                    'showUnsubscribe' => true,
                    'unsubscribeText' => 'Unsubscribe',
                    'preferencesText' => 'Email preferences',
                    'align' => 'center',
                    'fontSize' => 12,
                    'paddingY' => 20,
                ],
                'fields' => [
                    ['key' => 'companyLine', 'type' => 'text', 'label' => 'Company line'],
                    ['key' => 'addressLine', 'type' => 'text', 'label' => 'Postal address'],
                    ['key' => 'unsubscribeText', 'type' => 'text', 'label' => 'Unsubscribe label'],
                    ['key' => 'align', 'type' => 'align', 'label' => 'Alignment'],
                ],
            ],

            'html' => [
                'label' => 'Custom HTML',
                'icon' => '</>',
                'defaults' => ['html' => '<p>Paste your HTML here.</p>', 'paddingY' => 10],
                'fields' => [
                    ['key' => 'html', 'type' => 'code', 'label' => 'HTML'],
                ],
            ],
        ];
    }

    public function has(string $type): bool
    {
        return array_key_exists($type, $this->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(string $type): array
    {
        return $this->all()[$type]['defaults'] ?? [];
    }

    /**
     * The payload the Alpine builder needs: type, label, icon, defaults and
     * the editor fields.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUi(): array
    {
        return collect($this->all())
            ->map(fn (array $block, string $type) => array_merge(['type' => $type], $block))
            ->values()
            ->all();
    }

    /**
     * Normalises a stored document: fills in missing settings, drops unknown
     * block types, and gives every block an id. A document that has been
     * hand-edited or written by an older version still opens.
     *
     * @param  array<string, mixed>|null  $document
     * @return array{settings: array<string, mixed>, blocks: array<int, array<string, mixed>>}
     */
    public function normalise(?array $document): array
    {
        $document ??= [];

        $settings = array_merge(
            self::DOCUMENT_DEFAULTS,
            array_intersect_key(
                (array) ($document['settings'] ?? []),
                self::DOCUMENT_DEFAULTS
            )
        );

        // Coerced here, at the single point every document passes through,
        // rather than trusted because the editor happens to render a colour
        // picker. A template document is stored JSON: it can be posted, and
        // linkColor lands inside a <style> element where escaping is no help.
        foreach (self::COLOUR_SETTINGS as $key) {
            $settings[$key] = $this->safeColour($settings[$key] ?? null, self::DOCUMENT_DEFAULTS[$key]);
        }

        $settings['fontFamily'] = $this->safeFontStack($settings['fontFamily'] ?? null);
        $settings['width'] = max(320, min(900, (int) ($settings['width'] ?? 600)));

        $blocks = [];
        $index = 0;

        foreach ((array) ($document['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            // An unknown type is dropped rather than rendered — a template
            // from a future version must not emit raw JSON into an inbox.
            if (! is_string($type) || ! $this->has($type)) {
                continue;
            }

            $defaults = $this->defaults($type);

            $blocks[] = [
                'id' => is_scalar($block['id'] ?? null) ? (string) $block['id'] : 'b'.(++$index),
                'type' => $type,
                'settings' => $this->settleTypes(
                    array_merge($defaults, array_intersect_key((array) ($block['settings'] ?? []), $defaults)),
                    $defaults
                ),
            ];
        }

        return ['settings' => $settings, 'blocks' => $blocks];
    }

    /**
     * Forces every setting back to the shape of its default.
     *
     * array_intersect_key keeps only the keys we know about — it says nothing
     * about their VALUES. A document is stored JSON and can be posted, so
     * `settings.text` can arrive as an array where a string belongs, and the
     * compiler then casts it: PHP raises "Array to string conversion", Laravel
     * turns that into an ErrorException, and the editor answers a save with a
     * 500 rather than a validation message.
     *
     * Coercing here rather than in each of the fourteen block renderers is the
     * same argument as the colour handling above: this is the one point every
     * document passes through, and a fix anywhere else has to be remembered
     * fourteen more times.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    protected function settleTypes(array $settings, array $defaults): array
    {
        foreach ($defaults as $key => $default) {
            $value = $settings[$key] ?? null;

            $settings[$key] = match (true) {
                // A block that genuinely holds a list — columns, social links.
                is_array($default) => is_array($value) ? $value : $default,
                is_bool($default) => (bool) $value,
                is_int($default) => is_numeric($value) ? (int) $value : $default,
                is_float($default) => is_numeric($value) ? (float) $value : $default,
                // Everything else is text. Anything that is not a scalar
                // cannot be text, and the default is a better answer than a
                // crash or the word "Array" in somebody's inbox.
                default => is_scalar($value) ? (string) $value : $default,
            };
        }

        return $settings;
    }
}
