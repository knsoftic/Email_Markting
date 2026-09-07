<?php

namespace App\Services\Campaigns;

use App\Models\Account;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CustomField;
use App\Models\Subscriber;
use App\Support\TenantManager;
use Illuminate\Support\Carbon;

/**
 * Replaces {{token}} placeholders in a subject or body.
 *
 * ── Why this is not Blade ───────────────────────────────────────────────────
 * Every value here is attacker-influenced: a contact's name and company come
 * from a public sign-up form or an uploaded CSV. Handing that to Blade, or to
 * any template compiler, turns a contact record into arbitrary PHP. So this is
 * a plain, non-recursive string replacement over an allow-list of tokens, and
 * the replacement output is never re-scanned for further tokens.
 *
 * Syntax:
 *   {{name}}              the value, or empty when missing
 *   {{name|there}}        a fallback used when the value is empty
 *   {{custom.tier}}       an account-defined custom field
 *
 * Whitespace inside the braces is tolerated because people type it.
 */
class PersonalizationEngine
{
    /** Tokens every account gets, whatever their schema. */
    public const CORE_TOKENS = [
        'name' => 'Full name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email address',
        'phone' => 'Phone',
        'company' => 'Company',
        'country' => 'Country',
        'city' => 'City',
        'current_date' => 'Today’s date',
        'current_year' => 'Current year',
        'campaign_name' => 'Campaign name',
        'company_name' => 'Your company name',
        'unsubscribe_link' => 'Unsubscribe URL',
        'preferences_link' => 'Preferences URL',
    ];

    /**
     * Tokens that hold a URL we generated ourselves. They are inserted raw so
     * a signed query string is not double-escaped into a broken link.
     */
    protected const URL_TOKENS = ['unsubscribe_link', 'preferences_link'];

    /**
     * Resolved once per instance: a 10,000-recipient send renders the same
     * sender name 10,000 times and must not ask the database each time.
     */
    protected ?string $senderName = null;

    public function __construct(protected LinkSigner $links) {}

    /**
     * @param  array<string, string>  $extra  values injected by the caller
     */
    public function render(
        string $content,
        ?Subscriber $subscriber = null,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
        array $extra = [],
        bool $escape = true,
    ): string {
        if ($content === '' || ! str_contains($content, '{{')) {
            return $content;
        }

        $values = $this->values($subscriber, $campaign, $recipient) + $extra;

        // A single pass. The callback's return value is never re-examined, so
        // a contact literally named "{{email}}" cannot expand any further.
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*(?:\|([^}]*))?\}\}/',
            function (array $m) use ($values, $escape) {
                $token = $m[1];
                $fallback = isset($m[2]) ? trim($m[2]) : '';

                // An unrecognised token is removed rather than left on screen —
                // a recipient should never see "{{frist_name}}" in their inbox.
                if (! array_key_exists($token, $values)) {
                    return $fallback;
                }

                $value = (string) $values[$token];

                if ($value === '') {
                    $value = $fallback;
                }

                if (in_array($token, self::URL_TOKENS, true)) {
                    return $value;
                }

                return $escape ? $this->sanitise($value) : $value;
            },
            $content
        );
    }

    /**
     * Renders the plain-text alternative. The same values, but no HTML
     * escaping, because the output is not HTML.
     */
    public function renderText(
        string $content,
        ?Subscriber $subscriber = null,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
        array $extra = [],
    ): string {
        return $this->render($content, $subscriber, $campaign, $recipient, $extra, escape: false);
    }

    /**
     * The values behind every token for one recipient.
     *
     * @return array<string, string>
     */
    public function values(
        ?Subscriber $subscriber = null,
        ?Campaign $campaign = null,
        ?CampaignRecipient $recipient = null,
    ): array {
        $timezone = $campaign?->timezone ?: config('app.timezone');
        $now = Carbon::now($timezone);

        $values = [
            'name' => $subscriber?->displayName() ?? '',
            'first_name' => $subscriber?->first_name ?? '',
            'last_name' => $subscriber?->last_name ?? '',
            'email' => $subscriber?->email ?? '',
            'phone' => $subscriber?->phone ?? '',
            'company' => $subscriber?->company ?? '',
            'country' => $subscriber?->country ?? '',
            'city' => $subscriber?->city ?? '',
            'current_date' => $now->isoFormat('D MMMM YYYY'),
            'current_year' => $now->format('Y'),
            'campaign_name' => $campaign?->name ?? '',
            'company_name' => $this->senderName($campaign),
            'unsubscribe_link' => $this->links->unsubscribeUrl($subscriber, $campaign, $recipient),
            'preferences_link' => $this->links->preferencesUrl($subscriber, $campaign, $recipient),
        ];

        foreach (($subscriber?->custom ?? []) as $key => $value) {
            if (is_scalar($value)) {
                $values['custom.'.$key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * The sending account's own company name — the footer's "who sent this",
     * which a shared system template cannot hard-code.
     *
     * Read from the campaign when there is one so a queue worker resolves the
     * right account, and from the bound tenant when previewing a template.
     */
    protected function senderName(?Campaign $campaign): string
    {
        if ($campaign?->account?->name) {
            return $campaign->account->name;
        }

        if ($this->senderName === null) {
            $tenantId = app(TenantManager::class)->id();

            $this->senderName = $tenantId
                ? (string) (Account::find($tenantId)?->name ?? '')
                : '';
        }

        return $this->senderName;
    }

    /**
     * Every token the builder should offer, including this account's custom
     * fields.
     *
     * @return array<int, array{token: string, label: string, group: string}>
     */
    public function catalogue(): array
    {
        $tokens = [];

        foreach (self::CORE_TOKENS as $token => $label) {
            $tokens[] = [
                'token' => '{{'.$token.'}}',
                'label' => $label,
                'group' => in_array($token, self::URL_TOKENS, true) ? 'Links'
                    : (in_array($token, ['current_date', 'current_year', 'campaign_name', 'company_name'], true) ? 'Campaign' : 'Contact'),
            ];
        }

        foreach (CustomField::orderBy('sort_order')->get() as $field) {
            $tokens[] = [
                'token' => '{{custom.'.$field->key.'}}',
                'label' => $field->name,
                'group' => 'Custom fields',
            ];
        }

        return $tokens;
    }

    /**
     * Tokens used in the content that nothing will fill. Surfaced in the
     * campaign preview so a typo is caught before 50,000 people see it.
     *
     * @return array<int, string>
     */
    public function unknownTokens(string $content): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+)\s*(?:\|[^}]*)?\}\}/', $content, $matches);

        $known = array_keys(self::CORE_TOKENS);
        $customKeys = CustomField::pluck('key')->map(fn ($k) => 'custom.'.$k)->all();

        return collect($matches[1] ?? [])
            ->unique()
            ->reject(fn (string $token) => in_array($token, $known, true) || in_array($token, $customKeys, true))
            ->values()
            ->all();
    }

    /**
     * Escapes a contact-supplied value for HTML, and neutralises the two URL
     * schemes that turn a template author's `<a href="{{website}}">` into an
     * attack. Escaping alone stops attribute breakout, but not a javascript:
     * href, which is still live once the quotes are intact.
     */
    protected function sanitise(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;

        if ($this->hasDangerousScheme($value)) {
            $value = '#';
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', double_encode: false);
    }

    /**
     * Whether the value is a URL whose scheme we will not allow.
     *
     * The test runs on a copy with EVERY whitespace and control character
     * removed, because that is what a browser does before resolving a scheme:
     * href="java&#9;script:alert(1)" is a live javascript: URL, and a pattern
     * matched against the raw string walks straight past it. The value arrives
     * from a public sign-up form, so the attacker is an unauthenticated
     * stranger and the payload fires in the app's own preview pane, where a
     * logged-in session exists.
     *
     * It is an allow-list, not a blocklist: a scheme nobody thought of is
     * refused rather than waved through.
     */
    protected function hasDangerousScheme(string $value): bool
    {
        $collapsed = preg_replace('/[\s\x00-\x20]+/u', '', $value) ?? $value;

        if (! preg_match('/^([a-z][a-z0-9+.\-]*):/i', $collapsed, $m)) {
            return false; // Not a URL with a scheme at all.
        }

        return ! in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true);
    }
}
