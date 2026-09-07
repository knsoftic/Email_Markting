<?php

namespace App\Services\Tracking;

use App\Models\Campaign;
use App\Models\CampaignLink;
use Illuminate\Support\Facades\DB;

/**
 * Turns the links in a campaign into trackable ones.
 *
 * ── Why the destination is never in the URL ─────────────────────────────────
 * The obvious design is /click?to=https://example.com — and it is an open
 * redirect on a domain the recipient has been told to trust. Anyone could mail
 * a link that looks like ours and lands anywhere. So the redirect URL carries
 * only ids: the destination is read back from `campaign_links`, a row this
 * account wrote when the campaign was prepared, and is re-checked against
 * http/https before the redirect is issued.
 *
 * ── Why placeholders, not finished URLs ─────────────────────────────────────
 * The tracked URL differs per recipient, but the surrounding HTML does not.
 * Rewriting the whole document once per recipient would mean a regex pass over
 * 50 KB of HTML 100,000 times. Instead the document is rewritten ONCE per
 * chunk into placeholders, and each message replaces them with one strtr()
 * pass — a single scan with a tiny map.
 *
 * The placeholder is %%KNL<id>%% rather than a {{token}}: the personalisation
 * engine works on {{...}} and drops what it does not recognise, so a token
 * here would be deleted before it ever reached this class.
 */
class TrackingLinkRewriter
{
    /** Matches the placeholder this class writes into the blueprint. */
    public const PLACEHOLDER_PATTERN = '/%%KNL(\d+)%%/';

    public const PIXEL_PLACEHOLDER = '%%KNPIXEL%%';

    /**
     * Schemes a click may point at. A tracked link is a link a person clicks,
     * so anything a browser would not navigate to is left alone rather than
     * wrapped — wrapping mailto: would break the mail client's compose.
     */
    protected const TRACKABLE = ['http', 'https'];

    /**
     * Rewrites a campaign's HTML for tracking and records its links.
     *
     * Idempotent: it runs once per chunk and upserts on (campaign_id, hash),
     * so re-running never duplicates a link row or renumbers an existing one.
     *
     * @return array{html: string, links: array<int, int>} links: placeholder id => link id
     */
    public function prepare(Campaign $campaign, string $html): array
    {
        if (! $campaign->track_clicks) {
            return ['html' => $html, 'links' => []];
        }

        $found = $this->extract($html);

        if ($found === []) {
            return ['html' => $html, 'links' => []];
        }

        $ids = $this->store($campaign, $found);

        // Longest first: a URL that is a prefix of another must not be
        // replaced inside it.
        $replacements = [];

        foreach ($found as $url => $label) {
            if (isset($ids[$url])) {
                $replacements[$url] = '%%KNL'.$ids[$url].'%%';
            }
        }

        uksort($replacements, fn ($a, $b) => strlen($b) <=> strlen($a));

        return [
            'html' => $this->replaceHrefs($html, $replacements),
            'links' => array_values($ids),
        ];
    }

    /**
     * Every trackable destination in the document, as url => first link text.
     *
     * @return array<string, string>
     */
    public function extract(string $html): array
    {
        $found = [];

        // Both quote styles: the compiler emits double quotes, but a Custom
        // HTML block is whatever the user pasted.
        preg_match_all('#<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a>#is', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $url = html_entity_decode(trim($match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (! $this->isTrackable($url)) {
                continue;
            }

            if (! isset($found[$url])) {
                $label = trim(preg_replace('/\s+/u', ' ', strip_tags($match[3])) ?? '');
                $found[$url] = mb_substr($label, 0, 191);
            }
        }

        return $found;
    }

    /**
     * A destination worth wrapping.
     *
     * Deliberately excluded:
     *  - {{tokens}}, including the unsubscribe and preferences links. Routing
     *    an opt-out through a click tracker means an opt-out that breaks when
     *    tracking is off, and it records a "click" for the act of leaving.
     *  - mailto:, tel: and in-document anchors, which a redirect would break.
     *  - Anything already pointing at our own tracking route, so a second
     *    prepare() pass cannot wrap a wrapped link.
     */
    public function isTrackable(string $url): bool
    {
        if ($url === '' || str_contains($url, '{{') || str_starts_with($url, '#')) {
            return false;
        }

        if (str_contains($url, '%%KNL')) {
            return false;
        }

        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, self::TRACKABLE, true)) {
            return false;
        }

        return ! str_contains($url, '/t/c/');
    }

    /**
     * Upserts the links and returns url => campaign_link id.
     *
     * @param  array<string, string>  $found
     * @return array<string, int>
     */
    protected function store(Campaign $campaign, array $found): array
    {
        $rows = [];
        $hashes = [];

        foreach ($found as $url => $label) {
            $hash = sha1($url);
            $hashes[$hash] = $url;

            $rows[] = [
                'campaign_id' => $campaign->id,
                'url' => $url,
                'hash' => $hash,
                'label' => $label !== '' ? $label : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // insertOrIgnore, not upsert: an existing row's counters must not be
        // touched, and its label is whatever it was first seen as.
        CampaignLink::query()->insertOrIgnore($rows);

        $ids = [];

        foreach (
            CampaignLink::query()
                ->where('campaign_id', $campaign->id)
                ->whereIn('hash', array_keys($hashes))
                ->get(['id', 'hash']) as $link
        ) {
            $ids[$hashes[$link->hash]] = (int) $link->id;
        }

        return $ids;
    }

    /**
     * Replaces the href VALUES only.
     *
     * A blanket str_replace over the document would also rewrite the same URL
     * where it appears as visible text — "visit https://example.com" would
     * turn into "visit %%KNL4%%" in the reader's face.
     *
     * @param  array<string, string>  $replacements
     */
    protected function replaceHrefs(string $html, array $replacements): string
    {
        return (string) preg_replace_callback(
            '#(<a\b[^>]*\bhref\s*=\s*)(["\'])(.*?)\2#is',
            function (array $m) use ($replacements) {
                $url = html_entity_decode(trim($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return isset($replacements[$url])
                    ? $m[1].$m[2].$replacements[$url].$m[2]
                    : $m[0];
            },
            $html
        );
    }

    /**
     * Appends the open pixel, as a placeholder the message builder fills in.
     *
     * Placed immediately before </body> so it is the last thing loaded — a
     * pixel at the top delays the visible content on a slow connection for no
     * reason. `display:block` stops Outlook leaving a stray text line under it.
     */
    public function withPixel(Campaign $campaign, string $html): string
    {
        if (! $campaign->track_opens) {
            return $html;
        }

        $pixel = '<img src="'.self::PIXEL_PLACEHOLDER.'" width="1" height="1" border="0" alt=""'
            .' style="display:block;width:1px;height:1px;border:0;margin:0;padding:0;overflow:hidden;">';

        if (stripos($html, '</body>') !== false) {
            return preg_replace('#</body>#i', $pixel.'</body>', $html, 1) ?? $html.$pixel;
        }

        return $html.$pixel;
    }

    /**
     * The link ids a prepared document refers to, read back from the document
     * itself. Used by the message builder to build only the URLs this message
     * actually needs.
     *
     * @return array<int, int>
     */
    public function placeholdersIn(string $html): array
    {
        preg_match_all(self::PLACEHOLDER_PATTERN, $html, $matches);

        return array_values(array_unique(array_map('intval', $matches[1] ?? [])));
    }

    /**
     * Recomputes a campaign's per-link click totals from the click rows.
     *
     * The counters are incremented live as clicks arrive; this is the repair
     * path for when they drift — a restored backup, a deleted recipient, a
     * bug. It is a single grouped statement rather than a row-by-row loop.
     */
    public function refreshLinkCounts(Campaign $campaign): void
    {
        DB::statement(
            'UPDATE campaign_links cl
                LEFT JOIN (
                    SELECT campaign_link_id,
                           COUNT(*) AS total,
                           COUNT(DISTINCT campaign_recipient_id) AS uniques
                      FROM email_clicks
                     WHERE campaign_id = ?
                     GROUP BY campaign_link_id
                ) c ON c.campaign_link_id = cl.id
               SET cl.click_count = COALESCE(c.total, 0),
                   cl.unique_click_count = COALESCE(c.uniques, 0),
                   cl.updated_at = ?
             WHERE cl.campaign_id = ?',
            [$campaign->id, now(), $campaign->id]
        );
    }
}
