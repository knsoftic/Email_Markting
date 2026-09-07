<?php

namespace Tests\Feature\Tracking;

use App\Models\Campaign;
use App\Models\CampaignLink;
use App\Models\CampaignRecipient;
use App\Models\EmailClick;
use App\Models\EmailOpen;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Campaigns\EmailCompiler;
use App\Services\Campaigns\MessageBuilder;
use App\Services\Campaigns\RecipientGenerator;
use App\Services\Tracking\TrackingLinkRewriter;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Open and click tracking.
 *
 * The failure modes worth guarding here are not "does it count" — they are
 * "does it count the same person twice as unique", "can somebody record a
 * click against another contact", and "is the redirect an open redirect".
 */
class TrackingTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@tracking.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->account->subscription->update(['overrides' => [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5, 'max_emails_per_month' => 100000,
        ]]);
        $this->owner->account->refresh();

        app(TenantManager::class)->set($this->owner->account_id);

        SmtpAccount::factory()->forAccount($this->owner->account)->create();

        $this->campaign = $this->makeCampaign();
    }

    protected int $fixtureSeq = 0;

    protected function makeCampaign(array $overrides = [], array $extraBlocks = []): Campaign
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();

        // Each campaign gets its own contacts: an email is unique per account,
        // so a second campaign reusing the first one's addresses would collide.
        $batch = ++$this->fixtureSeq;

        for ($i = 1; $i <= 2; $i++) {
            Subscriber::factory()->forAccount($this->owner->account)
                ->create(['email' => "reader{$batch}-{$i}@example.com", 'first_name' => "Reader{$i}"])
                ->lists()->attach($list->id, ['subscribed_at' => now()]);
        }

        $doc = ['settings' => [], 'blocks' => array_merge([
            ['id' => 'h', 'type' => 'heading', 'settings' => ['text' => 'Hello']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['text' => 'Shop', 'href' => 'https://shop.example.com/sale']],
            ['id' => 't', 'type' => 'text', 'settings' => [
                'html' => '<p>Or read <a href="https://blog.example.com/post">the post</a>, '
                    .'or mail <a href="mailto:hi@example.com">us</a>.</p>',
            ]],
        ], $extraBlocks, [
            ['id' => 'f', 'type' => 'footer', 'settings' => ['companyLine' => 'Senders Ltd']],
        ])];

        $compiler = app(EmailCompiler::class);

        $campaign = Campaign::factory()->forAccount($this->owner->account)->create(array_merge([
            'name' => 'Tracked campaign',
            'subject' => 'Hello',
            'from_name' => 'Senders Ltd',
            'from_email' => 'hello@senders.test',
            'status' => 'sending',
            'blocks' => $doc,
            'html' => $compiler->compile($doc),
            'plain_text' => $compiler->compileText($doc),
            'audience' => ['lists' => [$list->id]],
            'track_opens' => true,
            'track_clicks' => true,
        ], $overrides));

        app(RecipientGenerator::class)->generate($campaign);

        return $campaign->fresh();
    }

    protected function recipient(int $index = 0): CampaignRecipient
    {
        return CampaignRecipient::where('campaign_id', $this->campaign->id)
            ->orderBy('id')->get()->get($index);
    }

    protected function rewriter(): TrackingLinkRewriter
    {
        return app(TrackingLinkRewriter::class);
    }

    // ------------------------------------------------------------ rewriting

    public function test_only_real_web_links_are_wrapped(): void
    {
        $found = $this->rewriter()->extract((string) $this->campaign->html);

        $this->assertArrayHasKey('https://shop.example.com/sale', $found);
        $this->assertArrayHasKey('https://blog.example.com/post', $found);

        foreach (array_keys($found) as $url) {
            $this->assertStringStartsNotWith('mailto:', $url);
        }
    }

    public function test_the_opt_out_link_is_never_routed_through_the_click_tracker(): void
    {
        $prepared = $this->rewriter()->prepare($this->campaign, (string) $this->campaign->html);

        $this->assertStringContainsString('{{unsubscribe_link}}', $prepared['html'],
            'Routing an opt-out through tracking makes it break when tracking is off.');
        $this->assertStringContainsString('{{preferences_link}}', $prepared['html']);

        $this->assertFalse($this->rewriter()->isTrackable('{{unsubscribe_link}}'));
    }

    public function test_preparing_twice_does_not_duplicate_or_re_wrap(): void
    {
        $first = $this->rewriter()->prepare($this->campaign, (string) $this->campaign->html);
        $second = $this->rewriter()->prepare($this->campaign, (string) $this->campaign->html);

        $this->assertSame($first['html'], $second['html']);
        $this->assertSame($first['links'], $second['links']);
        $this->assertSame(2, CampaignLink::where('campaign_id', $this->campaign->id)->count());
    }

    public function test_a_url_that_also_appears_as_visible_text_is_only_rewritten_in_the_href(): void
    {
        $html = '<p>Go to https://shop.example.com/sale — '
            .'<a href="https://shop.example.com/sale">here</a></p>';

        $prepared = $this->rewriter()->prepare($this->campaign, $html);

        $this->assertStringContainsString('Go to https://shop.example.com/sale', $prepared['html'],
            'The visible text is what the reader sees; only the href is a link.');
        $this->assertMatchesRegularExpression('/href="%%KNL\d+%%"/', $prepared['html']);
    }

    public function test_a_campaign_with_click_tracking_off_is_left_alone(): void
    {
        $this->campaign->update(['track_clicks' => false]);

        $prepared = $this->rewriter()->prepare($this->campaign->fresh(), (string) $this->campaign->html);

        $this->assertStringContainsString('https://shop.example.com/sale', $prepared['html']);
        $this->assertSame([], $prepared['links']);
        $this->assertSame(0, CampaignLink::where('campaign_id', $this->campaign->id)->count());
    }

    // --------------------------------------------------------------- pixel

    public function test_the_pixel_is_added_only_when_open_tracking_is_on(): void
    {
        $with = $this->rewriter()->withPixel($this->campaign, '<html><body><p>Hi</p></body></html>');
        $this->assertStringContainsString(TrackingLinkRewriter::PIXEL_PLACEHOLDER, $with);
        $this->assertStringContainsString('</body>', $with);
        $this->assertLessThan(strpos($with, '</body>'), strpos($with, TrackingLinkRewriter::PIXEL_PLACEHOLDER));

        $this->campaign->update(['track_opens' => false]);

        $this->assertStringNotContainsString(
            TrackingLinkRewriter::PIXEL_PLACEHOLDER,
            $this->rewriter()->withPixel($this->campaign->fresh(), '<html><body><p>Hi</p></body></html>')
        );
    }

    // ------------------------------------------------------ built messages

    public function test_a_real_message_carries_signed_per_recipient_urls(): void
    {
        $builder = app(MessageBuilder::class);
        $blueprint = $builder->blueprint($this->campaign);

        $one = $this->recipient(0);
        $two = $this->recipient(1);

        $htmlOne = $builder->forRecipient($blueprint, $this->campaign, $one->subscriber, $one)->getHtmlBody();
        $htmlTwo = $builder->forRecipient($blueprint, $this->campaign, $two->subscriber, $two)->getHtmlBody();

        $this->assertStringNotContainsString('%%KNL', $htmlOne, 'A placeholder must never reach an inbox.');
        $this->assertStringNotContainsString('%%KNPIXEL%%', $htmlOne);

        $this->assertStringContainsString('/t/o/'.$one->id.'?signature=', $htmlOne);
        $this->assertStringContainsString('/t/c/', $htmlOne);
        $this->assertStringContainsString('/t/o/'.$two->id.'?signature=', $htmlTwo);

        $this->assertNotSame($htmlOne, $htmlTwo, 'The two readers get different tracking URLs.');
    }

    public function test_a_test_send_gets_the_real_destinations_and_an_inert_pixel(): void
    {
        $builder = app(MessageBuilder::class);
        $blueprint = $builder->blueprint($this->campaign);

        $sample = Subscriber::query()->mailable()->first();

        $html = $builder->forRecipient($blueprint, $this->campaign, $sample)->getHtmlBody();

        $this->assertStringNotContainsString('%%KNL', $html);
        $this->assertStringNotContainsString('%%KNPIXEL%%', $html);
        $this->assertStringContainsString('https://shop.example.com/sale', $html,
            'A test send must look like the real thing, with working links.');
        $this->assertStringContainsString('data:image/gif;base64,', $html);
        $this->assertStringNotContainsString('/t/o/', $html, 'A test must not record an open.');
    }

    // -------------------------------------------------------------- opens

    public function test_the_pixel_records_an_open_and_returns_an_image(): void
    {
        $recipient = $this->recipient();

        $response = $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]));

        $response->assertOk();
        $this->assertSame('image/gif', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->assertSame(1, EmailOpen::where('campaign_recipient_id', $recipient->id)->count());
        $this->assertSame(1, (int) $recipient->fresh()->open_count);
        $this->assertNotNull($recipient->fresh()->first_opened_at);
        $this->assertSame(1, (int) $this->campaign->fresh()->unique_opens);
    }

    public function test_a_second_open_by_the_same_reader_is_counted_but_not_as_unique(): void
    {
        $recipient = $this->recipient();
        $url = URL::signedRoute('track.open', ['recipient' => $recipient->id]);

        $this->get($url)->assertOk();
        $firstOpenedAt = $recipient->fresh()->first_opened_at;

        $this->get($url)->assertOk();

        $campaign = $this->campaign->fresh();

        $this->assertSame(2, (int) $campaign->opened_count);
        $this->assertSame(1, (int) $campaign->unique_opens, 'One person is one unique open.');
        $this->assertSame(2, (int) $recipient->fresh()->open_count);
        $this->assertEquals($firstOpenedAt, $recipient->fresh()->first_opened_at,
            'first_opened_at is the first, not the latest.');
    }

    public function test_an_unsigned_pixel_url_is_refused(): void
    {
        $recipient = $this->recipient();

        $this->get("/t/o/{$recipient->id}")->assertForbidden();
        $this->assertSame(0, EmailOpen::count());
    }

    public function test_a_tampered_recipient_id_is_refused(): void
    {
        $one = $this->recipient(0);
        $two = $this->recipient(1);

        // Take a valid signature for one recipient and point it at the other.
        $url = URL::signedRoute('track.open', ['recipient' => $one->id]);
        $tampered = str_replace("/t/o/{$one->id}?", "/t/o/{$two->id}?", $url);

        $this->get($tampered)->assertForbidden();

        $this->assertSame(0, EmailOpen::where('campaign_recipient_id', $two->id)->count(),
            'Nobody may record engagement against somebody else.');
    }

    public function test_an_unknown_recipient_still_gets_an_image(): void
    {
        // Same response as a real one: this endpoint must not report which
        // ids exist.
        $this->get(URL::signedRoute('track.open', ['recipient' => 999999]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/gif');
    }

    public function test_a_campaign_with_open_tracking_off_records_nothing(): void
    {
        $this->campaign->update(['track_opens' => false]);
        $recipient = $this->recipient();

        $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]))->assertOk();

        $this->assertSame(0, EmailOpen::count());
        $this->assertSame(0, (int) $recipient->fresh()->open_count);
    }

    public function test_a_proxy_fetch_is_recorded_but_labelled(): void
    {
        $recipient = $this->recipient();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; GoogleImageProxy)'])
            ->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]))
            ->assertOk();

        $this->assertSame('gmail-proxy', EmailOpen::first()->device,
            'An open by a machine is still recorded, but a report should be able to say so.');
    }

    // ------------------------------------------------------------- clicks

    public function test_a_click_records_and_redirects_to_the_stored_destination(): void
    {
        $builder = app(MessageBuilder::class);
        $builder->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)
            ->where('url', 'https://shop.example.com/sale')->firstOrFail();

        $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]))
            ->assertRedirect('https://shop.example.com/sale');

        $this->assertSame(1, EmailClick::where('campaign_recipient_id', $recipient->id)->count());
        $this->assertSame(1, (int) $link->fresh()->click_count);
        $this->assertSame(1, (int) $link->fresh()->unique_click_count);
        $this->assertSame(1, (int) $this->campaign->fresh()->unique_clicks);
    }

    public function test_a_click_implies_an_open_even_when_the_pixel_never_loaded(): void
    {
        $builder = app(MessageBuilder::class);
        $builder->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]));

        $this->assertNotNull($recipient->fresh()->first_opened_at,
            'Most clients block images; a click is proof the message was read.');
        $this->assertSame(1, (int) $this->campaign->fresh()->unique_opens);
        $this->assertGreaterThanOrEqual(
            (int) $this->campaign->fresh()->unique_clicks,
            (int) $this->campaign->fresh()->unique_opens,
            'A campaign can never have more unique clicks than unique opens.'
        );
    }

    /**
     * Security gateways follow every URL in a message to check it. Counting
     * that as a person reading the email would invent attention that never
     * happened — for every recipient behind that gateway at once.
     */
    public function test_a_scanner_click_is_recorded_but_does_not_manufacture_an_open(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; Mimecast Link Protect)'])
            ->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]));

        $this->assertSame(1, EmailClick::count(), 'The click itself is still the raw truth and is recorded.');
        $this->assertSame('security-scanner', EmailClick::first()->device);

        $this->assertNull($recipient->fresh()->first_opened_at,
            'A machine following a link is not evidence that a person read the message.');
        $this->assertSame(0, (int) $this->campaign->fresh()->unique_opens);
    }

    public function test_a_person_clicking_still_implies_an_open(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0'])
            ->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]));

        $this->assertNull(EmailClick::first()->device, 'An ordinary browser is not a machine.');
        $this->assertNotNull($recipient->fresh()->first_opened_at);
        $this->assertSame(1, (int) $this->campaign->fresh()->unique_opens);
    }

    public function test_clicking_twice_counts_twice_but_is_one_unique(): void
    {
        $builder = app(MessageBuilder::class);
        $builder->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();
        $url = URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]);

        $this->get($url);
        $this->get($url);

        $this->assertSame(2, (int) $link->fresh()->click_count);
        $this->assertSame(1, (int) $link->fresh()->unique_click_count);
        $this->assertSame(2, (int) $this->campaign->fresh()->clicked_count);
        $this->assertSame(1, (int) $this->campaign->fresh()->unique_clicks);
    }

    public function test_a_link_from_another_campaign_cannot_be_paired_with_this_recipient(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $other = $this->makeCampaign(['name' => 'Other campaign']);
        app(MessageBuilder::class)->blueprint($other);

        $foreignLink = CampaignLink::where('campaign_id', $other->id)->firstOrFail();
        $recipient = $this->recipient();

        $this->get(URL::signedRoute('track.click', [
            'link' => $foreignLink->id, 'recipient' => $recipient->id,
        ]))->assertOk()->assertSee('no longer available');

        $this->assertSame(0, EmailClick::count());
    }

    public function test_the_redirect_destination_never_comes_from_the_url(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        $url = URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]);

        // Appending a destination invalidates the signature; even if it did
        // not, nothing reads it.
        $this->get($url.'&to=https://evil.example.com')->assertForbidden();

        $this->assertSame(0, EmailClick::count());
    }

    public function test_a_stored_destination_that_is_not_http_is_refused_at_redirect_time(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        // A row can outlive the code that wrote it, so the check runs again.
        $link->forceFill(['url' => "java\tscript:alert(1)"])->save();

        $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]))
            ->assertOk()
            ->assertSee('no longer available');

        $this->assertSame(0, EmailClick::count());
    }

    // ------------------------------------------------------------- repair

    public function test_the_counters_can_be_rebuilt_from_the_event_rows(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        foreach ([$this->recipient(0), $this->recipient(1)] as $recipient) {
            $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]));
            $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]));
        }

        // Simulate drift.
        $this->campaign->forceFill([
            'opened_count' => 99, 'unique_opens' => 99, 'clicked_count' => 99, 'unique_clicks' => 99,
        ])->save();
        $link->forceFill(['click_count' => 99, 'unique_click_count' => 99])->save();

        app(\App\Services\Tracking\TrackingRecorder::class)->refreshCounts($this->campaign->id);
        $this->rewriter()->refreshLinkCounts($this->campaign);

        $campaign = $this->campaign->fresh();

        $this->assertSame(2, (int) $campaign->opened_count);
        $this->assertSame(2, (int) $campaign->unique_opens);
        $this->assertSame(2, (int) $campaign->clicked_count);
        $this->assertSame(2, (int) $campaign->unique_clicks);
        $this->assertSame(2, (int) $link->fresh()->click_count);
        $this->assertSame(2, (int) $link->fresh()->unique_click_count);
    }

    // ------------------------------------------------------------ tenancy

    public function test_tracking_rows_belong_to_the_campaigns_account(): void
    {
        app(MessageBuilder::class)->blueprint($this->campaign);

        $recipient = $this->recipient();
        $link = CampaignLink::where('campaign_id', $this->campaign->id)->firstOrFail();

        app(TenantManager::class)->forget();

        $this->get(URL::signedRoute('track.open', ['recipient' => $recipient->id]));
        $this->get(URL::signedRoute('track.click', ['link' => $link->id, 'recipient' => $recipient->id]));

        $this->assertSame(
            $this->owner->account_id,
            (int) EmailOpen::withoutGlobalScopes()->first()->account_id,
            'A public route has no tenant bound; the account comes from the campaign.'
        );
        $this->assertSame(
            $this->owner->account_id,
            (int) EmailClick::withoutGlobalScopes()->first()->account_id
        );
    }
}
