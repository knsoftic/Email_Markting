<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Services\Contacts\SegmentCompiler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a campaign's audience (lists + tags + segments) into rows in
 * campaign_recipients.
 *
 * ── Why this is one INSERT..SELECT ──────────────────────────────────────────
 * A 100k audience must never become 100k ids in PHP memory, and generation has
 * to be safely re-runnable: a worker dying halfway must be able to resume
 * without duplicating anybody. The unique index on
 * (campaign_id, subscriber_id) plus insertOrIgnore gives that for free — a
 * second pass simply inserts nothing for rows that already exist.
 *
 * Suppressed and non-mailable contacts are excluded HERE, but that is not the
 * only check: someone who unsubscribes an hour into a long send would still
 * have a row, so the sender re-checks suppression immediately before each
 * message. Generation-time filtering alone is a compliance hole, not a
 * safeguard.
 */
class RecipientGenerator
{
    /** Rows per INSERT..SELECT pass. */
    protected const CHUNK = 5000;

    public function __construct(protected SegmentCompiler $segments) {}

    /**
     * Builds the audience query for a campaign, without materialising it.
     *
     * The audience shape is {lists: [], tags: [], segments: [], status: null}.
     * An empty audience deliberately matches NOBODY rather than everybody — a
     * campaign with no audience selected must not go to the whole database.
     */
    public function audienceQuery(Campaign $campaign): Builder
    {
        $audience = (array) ($campaign->audience ?? []);

        $lists = $this->ownIds(SubscriberList::class, $audience['lists'] ?? []);
        $tags = $this->ownIds(Tag::class, $audience['tags'] ?? []);
        $segmentIds = $this->ownIds(Segment::class, $audience['segments'] ?? []);

        if (empty($lists) && empty($tags) && empty($segmentIds)) {
            return Subscriber::query()->whereRaw('1 = 0');
        }

        // mailable() is status = active AND not on the suppression list.
        $query = Subscriber::query()->mailable();

        $query->where(function (Builder $q) use ($lists, $tags, $segmentIds) {
            if ($lists) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('list_subscriber')
                    ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                    ->whereIn('list_subscriber.subscriber_list_id', $lists));
            }

            if ($tags) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('subscriber_tag')
                    ->whereColumn('subscriber_tag.subscriber_id', 'subscribers.id')
                    ->whereIn('subscriber_tag.tag_id', $tags));
            }

            foreach ($segmentIds as $segmentId) {
                $segment = Segment::find($segmentId);

                if (! $segment) {
                    continue;
                }

                // Each segment contributes its own compiled condition, wrapped
                // so an "any" segment cannot widen the whole audience.
                $q->orWhere(function (Builder $inner) use ($segment) {
                    $this->segments->forSegment($segment, $inner);
                });
            }
        });

        return $query;
    }

    /** How many contacts a campaign would go to right now. */
    public function count(Campaign $campaign): int
    {
        return $this->audienceQuery($campaign)->count();
    }

    /**
     * Writes the recipient rows. Returns how many were created.
     *
     * Safe to call again: existing rows are ignored, so an interrupted run
     * resumes rather than duplicating.
     */
    public function generate(Campaign $campaign): int
    {
        $created = 0;
        $lastId = 0;

        do {
            // Keyset pagination rather than OFFSET: at 100k rows OFFSET makes
            // the database re-scan everything it already skipped.
            $rows = (clone $this->audienceQuery($campaign))
                ->where('subscribers.id', '>', $lastId)
                ->orderBy('subscribers.id')
                ->limit(self::CHUNK)
                ->get(['subscribers.id', 'subscribers.email', 'subscribers.name']);

            if ($rows->isEmpty()) {
                break;
            }

            $lastId = $rows->last()->id;
            $now = now();

            $payload = $rows->map(fn ($row) => [
                'campaign_id' => $campaign->id,
                'subscriber_id' => $row->id,
                'email' => $row->email,
                'name' => $row->name,
                'status' => 'pending',
                // Minted here so a reply can be matched back to this exact
                // recipient even if the message id is rewritten in transit.
                'reply_token' => Str::random(32),
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            $created += DB::table('campaign_recipients')->insertOrIgnore($payload);
        } while ($rows->count() === self::CHUNK);

        $this->refreshTotal($campaign);

        return $created;
    }

    /** Recomputes total_recipients from the rows that actually exist. */
    public function refreshTotal(Campaign $campaign): int
    {
        $total = DB::table('campaign_recipients')->where('campaign_id', $campaign->id)->count();

        Campaign::withoutGlobalScopes()->whereKey($campaign->id)->update([
            'total_recipients' => $total,
            'updated_at' => now(),
        ]);

        $campaign->total_recipients = $total;

        return $total;
    }

    /**
     * A readable summary for the pre-send confirmation screen, so nobody
     * presses Send without seeing who it reaches.
     *
     * @return array<string, mixed>
     */
    public function summarise(Campaign $campaign): array
    {
        $audience = (array) ($campaign->audience ?? []);

        return [
            'lists' => SubscriberList::whereIn('id', $audience['lists'] ?? [])->pluck('name')->all(),
            'tags' => Tag::whereIn('id', $audience['tags'] ?? [])->pluck('name')->all(),
            'segments' => Segment::whereIn('id', $audience['segments'] ?? [])->pluck('name')->all(),
            'reach' => $this->count($campaign),
            // The gap between "in the audience" and "will be mailed" is the
            // number people ask about, so it is shown rather than hidden.
            'excluded' => $this->excludedCount($campaign),
        ];
    }

    /**
     * Contacts that match the audience but will NOT be mailed, because they
     * are unsubscribed, bounced, blocked or suppressed.
     */
    public function excludedCount(Campaign $campaign): int
    {
        $audience = (array) ($campaign->audience ?? []);

        $lists = $this->ownIds(SubscriberList::class, $audience['lists'] ?? []);
        $tags = $this->ownIds(Tag::class, $audience['tags'] ?? []);

        if (empty($lists) && empty($tags)) {
            return 0;
        }

        $all = Subscriber::query()->where(function (Builder $q) use ($lists, $tags) {
            if ($lists) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('list_subscriber')
                    ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                    ->whereIn('list_subscriber.subscriber_list_id', $lists));
            }
            if ($tags) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('subscriber_tag')
                    ->whereColumn('subscriber_tag.subscriber_id', 'subscribers.id')
                    ->whereIn('subscriber_tag.tag_id', $tags));
            }
        })->count();

        $mailable = Subscriber::query()->mailable()->where(function (Builder $q) use ($lists, $tags) {
            if ($lists) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('list_subscriber')
                    ->whereColumn('list_subscriber.subscriber_id', 'subscribers.id')
                    ->whereIn('list_subscriber.subscriber_list_id', $lists));
            }
            if ($tags) {
                $q->orWhereExists(fn ($sub) => $sub->selectRaw(1)
                    ->from('subscriber_tag')
                    ->whereColumn('subscriber_tag.subscriber_id', 'subscribers.id')
                    ->whereIn('subscriber_tag.tag_id', $tags));
            }
        })->count();

        return max(0, $all - $mailable);
    }

    /**
     * Restricts posted ids to the current account. The tenant scope already
     * does this; going through the model keeps the guarantee explicit.
     *
     * @param  class-string  $model
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    protected function ownIds(string $model, mixed $ids): array
    {
        $ids = array_filter(array_map('intval', (array) $ids));

        return $ids ? $model::whereIn('id', $ids)->pluck('id')->all() : [];
    }
}
