<?php

namespace Tests\Feature\Hardening;

use App\Models\Automation;
use App\Models\AutomationStep;
use App\Models\Campaign;
use App\Models\CampaignLog;
use App\Models\Email;
use App\Models\EmailTemplate;
use App\Models\Import;
use App\Models\Mailbox;
use App\Models\Role;
use App\Models\Segment;
use App\Models\SmtpAccount;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One account may never reach another account's records.
 *
 * This application does not use Laravel policies. It has two layers instead:
 * the `AccountScope` global scope, so another tenant's row is simply not found
 * — a 404, which does not even confirm the record exists — and `permission:`
 * middleware on 106 routes for what a role may do. A third mechanism saying
 * the same thing would be ceremony.
 *
 * What that arrangement does need is proof, because it is enforced by a scope
 * being armed rather than by a check written at each call site: forget the
 * scope in one query and nothing anywhere complains. So rather than asserting
 * that policies exist, this walks every route that binds a tenant-owned model
 * and asks for somebody else's record by id.
 *
 * The list of bindings is taken from the router, not written by hand, and the
 * test fails if a new binding appears without cover — otherwise the next model
 * added would quietly go untested.
 */
class CrossTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $mine;

    protected User $theirs;

    /** @var array<string, int> route parameter name => id owned by the OTHER account */
    protected array $foreign = [];

    /**
     * Bindings deliberately not covered here, and why.
     *
     * The public routes are protected by a URL signature rather than by the
     * tenant scope — the recipient has no session at all — so they are covered
     * where that signature is: the tracking and unsubscribe tests.
     *
     * @var array<int, string>
     */
    protected const NOT_TENANT_SCOPED = [
        'id',        // verify-email/{id}/{hash} — signed, and the user's own
        'token',     // reset-password/{token}   — the token is the secret
        'path',      // storage/{path}           — the filesystem, not a model
        'link',      // t/c/{link}/{recipient}   — signed tracking
        'recipient', // t/o/{recipient}          — signed tracking
        'subscriber', // unsubscribe/preferences — signed
        'campaign',  // appears only as the optional second half of those two
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->mine = $this->provision('Mine Ltd', 'owner@mine.test');
        $this->theirs = $this->provision('Theirs Ltd', 'owner@theirs.test');

        // Everything below belongs to the OTHER account.
        app(TenantManager::class)->runAs($this->theirs->account_id, function () {
            $this->foreign = $this->buildRecords($this->theirs);
        });

        $this->actingAs($this->mine);
        app(TenantManager::class)->set($this->mine->account_id);
    }

    protected function provision(string $company, string $email): User
    {
        $user = app(AccountProvisioner::class)->provision([
            'company_name' => $company, 'name' => 'Owner', 'email' => $email,
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        $subscription = $user->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], [
            'allow_custom_smtp' => true, 'max_smtp_accounts' => 5,
            'allow_inbox' => true, 'max_mailboxes' => 5,
            'allow_automation' => true, 'max_automations' => 10,
            'max_emails_per_month' => 100000, 'max_team_members' => 5,
        ])]);

        $user->account->refresh();

        return $user;
    }

    /**
     * One record of every tenant-owned kind, keyed by the route parameter that
     * binds it.
     *
     * @return array<string, int>
     */
    protected function buildRecords(User $owner): array
    {
        $accountId = $owner->account_id;

        $list = SubscriberList::factory()->forAccount($owner->account)->create(['name' => 'Their list']);
        $tag = Tag::factory()->forAccount($owner->account)->create(['name' => 'Their tag']);
        $segment = Segment::factory()->forAccount($owner->account)->create(['name' => 'Their segment']);
        $smtp = SmtpAccount::factory()->forAccount($owner->account)->create(['name' => 'Their relay']);
        $campaign = Campaign::factory()->forAccount($owner->account)
            ->create(['name' => 'Their campaign', 'subject' => 'Theirs']);

        Subscriber::factory()->forAccount($owner->account)->create(['email' => 'theirs@example.com']);

        $template = EmailTemplate::create([
            'account_id' => $accountId, 'user_id' => $owner->id,
            'name' => 'Their template', 'subject' => 'Theirs', 'html' => '<p>Theirs</p>',
        ]);

        $mailbox = Mailbox::create([
            'account_id' => $accountId, 'user_id' => $owner->id,
            'name' => 'Their mailbox', 'email' => 'support@theirs.test',
            'imap_host' => 'imap.theirs.test', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'support@theirs.test', 'imap_password' => 'secret',
        ]);

        $email = Email::create([
            'account_id' => $accountId, 'mailbox_id' => $mailbox->id,
            'direction' => 'incoming', 'folder_type' => 'inbox',
            'message_id' => '<theirs@example.test>', 'subject' => 'Their private message',
            'from_email' => 'someone@example.com', 'to' => ['support@theirs.test'],
            'body_text' => 'Confidential', 'received_at' => now(),
        ]);

        $draft = Email::create([
            'account_id' => $accountId, 'mailbox_id' => $mailbox->id,
            'direction' => 'outgoing', 'folder_type' => 'drafts', 'is_draft' => true,
            'subject' => 'Their draft', 'from_email' => 'support@theirs.test',
            'to' => ['someone@example.com'], 'body_text' => 'Draft body',
        ]);

        $thread = \App\Models\EmailThread::create([
            'account_id' => $accountId, 'mailbox_id' => $mailbox->id,
            'thread_key' => 'theirs-thread-key', 'campaign_id' => $campaign->id,
            'subject' => 'Their conversation', 'reply_status' => 'new',
            'messages_count' => 1, 'last_message_at' => now(),
        ]);

        $automation = Automation::create([
            'account_id' => $accountId, 'user_id' => $owner->id,
            'name' => 'Their automation', 'trigger_type' => 'subscriber_added', 'status' => 'draft',
        ]);

        AutomationStep::create([
            'automation_id' => $automation->id, 'position' => 1, 'type' => 'wait',
            'config' => ['amount' => 1, 'unit' => 'days'],
        ]);

        $attachment = \App\Models\EmailAttachment::create([
            'email_id' => $email->id, 'account_id' => $accountId,
            'name' => 'their-private-file.pdf', 'mime_type' => 'application/pdf',
            'size' => 1024, 'disk' => 'local', 'path' => 'attachments/x/their.pdf',
        ]);

        $import = Import::create([
            'account_id' => $accountId, 'user_id' => $owner->id,
            'original_name' => 'theirs.csv', 'file_path' => 'imports/x/theirs.csv', 'status' => 'completed',
        ]);

        $log = CampaignLog::create([
            'account_id' => $accountId, 'campaign_id' => $campaign->id,
            'type' => 'campaign', 'status' => 'sent',
            'recipient_email' => 'theirs@example.com', 'subject' => 'Theirs',
        ]);

        // A CUSTOM role of theirs. The system roles are deliberately global
        // (account_id null) and both accounts are meant to see those, so only
        // an account's own role is a cross-tenant question at all.
        $role = Role::create([
            'account_id' => $accountId, 'name' => 'Their custom role',
            'slug' => 'their-custom-role', 'is_system' => false,
        ]);

        $member = User::factory()->create([
            'account_id' => $accountId, 'name' => 'Their colleague',
            'email' => 'colleague@theirs.test', 'email_verified_at' => now(),
            'role_id' => Role::withoutGlobalScopes()->where('slug', Role::STAFF)->value('id'),
        ]);

        return [
            'attachment' => $attachment->id,
            'automation' => $automation->id,
            'role' => $role->id,
            'campaignId' => $campaign->id,
            'draft' => $draft->id,
            'email' => $email->id,
            'import' => $import->id,
            'list' => $list->id,
            'log' => $log->id,
            'mailbox' => $mailbox->id,
            'segment' => $segment->id,
            'smtpAccount' => $smtp->id,
            'tag' => $tag->id,
            'template' => $template->id,
            'thread' => $thread->id,
            'user' => $member->id,
            'step' => AutomationStep::where('automation_id', $automation->id)->value('id'),
        ];
    }

    // ------------------------------------------------------------- the sweep

    /**
     * Every GET route that binds a tenant-owned model, asked for somebody
     * else's record.
     */
    public function test_no_get_route_hands_over_another_accounts_record(): void
    {
        $checked = 0;

        foreach ($this->boundGetRoutes() as $uri => $params) {
            $url = $this->fill($uri, $params);

            if ($url === null) {
                continue;
            }

            $response = $this->get($url);

            $this->assertContains(
                $response->status(),
                [403, 404],
                "{$url} answered {$response->status()} for another account's record. "
                .'A tenant-owned row must be unreachable, and 404 is preferred so the '
                .'response does not confirm that the record exists.'
            );

            $checked++;
        }

        // A binding that stops being exercised is a binding that stops being
        // tested, so the count itself is asserted.
        $this->assertGreaterThanOrEqual(20, $checked,
            "Only {$checked} bound routes were exercised; the sweep has stopped covering the app.");
    }

    /**
     * The same records, read directly, must not be visible to a query the
     * application makes while this account is bound.
     */
    public function test_the_scope_hides_the_rows_themselves(): void
    {
        $this->assertNull(Campaign::query()->find($this->foreign['campaignId']));
        $this->assertNull(SubscriberList::query()->find($this->foreign['list']));
        $this->assertNull(Tag::query()->find($this->foreign['tag']));
        $this->assertNull(Segment::query()->find($this->foreign['segment']));
        $this->assertNull(SmtpAccount::query()->find($this->foreign['smtpAccount']));
        $this->assertNull(EmailTemplate::query()->find($this->foreign['template']));
        $this->assertNull(Mailbox::query()->find($this->foreign['mailbox']));
        $this->assertNull(Email::query()->find($this->foreign['email']));
        $this->assertNull(Automation::query()->find($this->foreign['automation']));
        $this->assertNull(Import::query()->find($this->foreign['import']));
        $this->assertNull(CampaignLog::query()->find($this->foreign['log']));
        $this->assertNull(Subscriber::query()->where('email', 'theirs@example.com')->first());
    }

    /**
     * A nested step is bound through its automation, so an id belonging to
     * another tenant's automation must not resolve even when the outer id is
     * one of ours.
     */
    public function test_a_nested_step_cannot_be_borrowed_from_another_automation(): void
    {
        $mine = Automation::create([
            'account_id' => $this->mine->account_id, 'user_id' => $this->mine->id,
            'name' => 'My automation', 'trigger_type' => 'subscriber_added', 'status' => 'draft',
        ]);

        $this->get("/automations/{$mine->id}/steps/{$this->foreign['step']}/edit")
            ->assertNotFound();

        $this->get("/automations/{$this->foreign['automation']}/steps/{$this->foreign['step']}/edit")
            ->assertNotFound();
    }

    // ------------------------------------------------------------- machinery

    /**
     * Bound GET routes, straight from the router.
     *
     * @return array<string, array<int, string>>
     */
    protected function boundGetRoutes(): array
    {
        $out = [];

        foreach (app('router')->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $uri = $route->uri();

            if (str_starts_with($uri, '_') || str_starts_with($uri, 'admin')) {
                continue;
            }

            preg_match_all('/\{(\w+)\??\}/', $uri, $m);

            if ($m[1] === []) {
                continue;
            }

            $out[$uri] = $m[1];
        }

        return $out;
    }

    /**
     * Substitutes the other account's ids into a route pattern, or returns
     * null when the route is not one this sweep is about.
     *
     * @param  array<int, string>  $params
     */
    protected function fill(string $uri, array $params): ?string
    {
        $ids = $this->foreign + ['campaign' => $this->foreign['campaignId']];

        foreach ($params as $name) {
            if (in_array($name, self::NOT_TENANT_SCOPED, true)) {
                return null;
            }

            if (! array_key_exists($name, $ids)) {
                $this->fail(
                    "The route {$uri} binds {{$name}}, which this sweep has never heard of. "
                    .'Add a record for it to buildRecords(), or list it in NOT_TENANT_SCOPED '
                    .'with the reason it is protected some other way.'
                );
            }

            $uri = str_replace(['{'.$name.'?}', '{'.$name.'}'], (string) $ids[$name], $uri);
        }

        return '/'.ltrim($uri, '/');
    }
}
