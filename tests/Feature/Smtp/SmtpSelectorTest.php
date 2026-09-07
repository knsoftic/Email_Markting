<?php

namespace Tests\Feature\Smtp;

use App\Models\Plan;
use App\Models\SmtpAccount;
use App\Models\SmtpAssignment;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Services\Smtp\SmtpSelector;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The selector decides who sends and charges the limit. A mistake here either
 * blows past a provider's ceiling (getting the account blocked) or takes a
 * healthy account out of service.
 */
class SmtpSelectorTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected SmtpSelector $selector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Senders Ltd', 'name' => 'Owner', 'email' => 'owner@senders.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->allow(['allow_custom_smtp' => true, 'allow_smtp_rotation' => true]);

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);

        $this->selector = app(SmtpSelector::class);
    }

    protected function allow(array $overrides): void
    {
        $subscription = $this->owner->account->subscription;
        $subscription->update(['overrides' => array_merge($subscription->overrides ?? [], $overrides)]);
        $this->owner->account->refresh();
    }

    protected function own(array $attributes = []): SmtpAccount
    {
        return SmtpAccount::factory()->forAccount($this->owner->account)->create($attributes);
    }

    // ------------------------------------------------- atomic reservation

    public function test_the_daily_limit_is_never_exceeded_even_by_one(): void
    {
        $account = $this->own(['daily_limit' => 500]);

        $granted = 0;

        // 600 attempts against a 500 ceiling. Because the check and the
        // increment are one statement, exactly 500 may succeed.
        for ($i = 0; $i < 600; $i++) {
            if ($this->selector->reserveOn($account)) {
                $granted++;
            }
        }

        $this->assertSame(500, $granted);
        $this->assertSame(500, (int) $account->fresh()->sent_today);
    }

    public function test_the_last_slot_can_only_be_taken_once(): void
    {
        $account = $this->own(['daily_limit' => 10, 'sent_today' => 9, 'day_reset_at' => now()]);

        $this->assertTrue($this->selector->reserveOn($account), 'The 10th send must be allowed.');
        $this->assertFalse($this->selector->reserveOn($account), 'The 11th must not.');
        $this->assertSame(10, (int) $account->fresh()->sent_today);
    }

    public function test_reservation_is_a_single_statement_with_no_read_first(): void
    {
        $account = $this->own(['daily_limit' => 5]);

        DB::enableQueryLog();
        $this->selector->reserveOn($account);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $log, 'Reserving must be exactly one statement.');
        $this->assertStringStartsWith('update', strtolower(trim($log[0]['query'])));
        $this->assertStringNotContainsString('select', strtolower($log[0]['query']),
            'A read-then-write would let two workers both take the last slot.');
    }

    public function test_every_window_is_enforced_independently(): void
    {
        $hourly = $this->own(['hourly_limit' => 2]);
        $this->assertTrue($this->selector->reserveOn($hourly));
        $this->assertTrue($this->selector->reserveOn($hourly));
        $this->assertFalse($this->selector->reserveOn($hourly), 'The hourly ceiling must bite.');

        $monthly = $this->own(['monthly_limit' => 1]);
        $this->assertTrue($this->selector->reserveOn($monthly));
        $this->assertFalse($this->selector->reserveOn($monthly), 'The monthly ceiling must bite.');
    }

    // ------------------------------------------------------------ rollover

    public function test_a_stale_window_rolls_over_inside_the_same_statement(): void
    {
        $account = $this->own([
            'daily_limit' => 10,
            'sent_today' => 10,
            'day_reset_at' => now()->subDay(),
            'hourly_limit' => 5,
            'sent_this_hour' => 5,
            'hour_reset_at' => now()->subHours(2),
        ]);

        $this->assertTrue(
            $this->selector->reserveOn($account),
            'Yesterday being full must not block today.'
        );

        $fresh = $account->fresh();

        $this->assertSame(1, (int) $fresh->sent_today, 'The counter restarts at this send, not at zero.');
        $this->assertSame(1, (int) $fresh->sent_this_hour);
        $this->assertTrue($fresh->day_reset_at->greaterThanOrEqualTo(now()->startOfDay()));
    }

    public function test_a_current_window_is_not_reset(): void
    {
        $account = $this->own(['daily_limit' => 10, 'sent_today' => 4, 'day_reset_at' => now()->startOfDay()]);

        $this->selector->reserveOn($account);

        $this->assertSame(5, (int) $account->fresh()->sent_today);
    }

    // ----------------------------------------------------------- blocking

    public function test_a_cooling_down_account_is_not_charged(): void
    {
        $account = SmtpAccount::factory()->forAccount($this->owner->account)->inCooldown()->create();

        $this->assertFalse($this->selector->reserveOn($account));
        $this->assertSame(0, (int) $account->fresh()->sent_today);
    }

    public function test_an_expired_cooldown_is_usable_again(): void
    {
        $account = $this->own(['cooldown_until' => now()->subMinute(), 'consecutive_failures' => 5]);

        $this->assertTrue($this->selector->reserveOn($account));
    }

    public function test_an_inactive_or_deleted_account_is_not_charged(): void
    {
        $inactive = SmtpAccount::factory()->forAccount($this->owner->account)->inactive()->create();
        $this->assertFalse($this->selector->reserveOn($inactive));

        $deleted = $this->own();
        $deleted->delete();
        $this->assertFalse($this->selector->reserveOn($deleted));
    }

    public function test_a_deleted_account_is_not_even_offered(): void
    {
        $keep = $this->own();
        $deleted = $this->own();
        $deleted->delete();

        $offered = $this->selector->candidatesFor($this->owner->account)->pluck('id');

        $this->assertTrue($offered->contains($keep->id));
        $this->assertFalse(
            $offered->contains($deleted->id),
            'Deleting an SMTP account must stop it being used — withoutGlobalScopes() once removed '
            .'the soft-delete scope along with the tenant scope, so deleted credentials kept sending.'
        );
    }

    // ------------------------------------------------------------ release

    public function test_releasing_gives_the_slot_back(): void
    {
        $account = $this->own(['daily_limit' => 1]);

        $this->assertTrue($this->selector->reserveOn($account));
        $this->assertFalse($this->selector->reserveOn($account));

        $this->selector->release($account);

        $this->assertTrue(
            $this->selector->reserveOn($account),
            'A send that never left the machine must not consume the quota.'
        );
    }

    public function test_release_cannot_drive_a_counter_negative(): void
    {
        $account = $this->own();

        $this->selector->release($account);
        $this->selector->release($account);

        $fresh = $account->fresh();

        $this->assertSame(0, (int) $fresh->sent_today);
        $this->assertSame(0, (int) $fresh->total_sent);
    }

    // --------------------------------------------------------- candidates

    public function test_rotation_moves_to_the_next_account_when_one_fills_up(): void
    {
        $a = $this->own(['name' => 'A', 'daily_limit' => 2, 'priority' => 0]);
        $b = $this->own(['name' => 'B', 'daily_limit' => 2, 'priority' => 1]);

        $used = [];

        for ($i = 0; $i < 4; $i++) {
            $used[] = $this->selector->reserve($this->owner->account)?->name;
        }

        sort($used);

        $this->assertSame(['A', 'A', 'B', 'B'], $used, 'Four sends must spread across both 2-send accounts.');
        $this->assertNull(
            $this->selector->reserve($this->owner->account),
            'With everything full the selector must return null, not overspend.'
        );

        $this->assertSame(2, (int) $a->fresh()->sent_today);
        $this->assertSame(2, (int) $b->fresh()->sent_today);
    }

    public function test_without_the_rotation_feature_only_one_account_is_offered(): void
    {
        $this->allow(['allow_smtp_rotation' => false]);
        $this->own(['name' => 'A']);
        $this->own(['name' => 'B']);

        $this->assertCount(1, $this->selector->candidatesFor($this->owner->account->fresh()));
    }

    public function test_a_plan_without_custom_smtp_cannot_use_its_own_accounts(): void
    {
        $this->allow(['allow_custom_smtp' => false, 'allow_admin_smtp' => false]);
        $this->own();

        $this->assertCount(0, $this->selector->candidatesFor($this->owner->account->fresh()));
        $this->assertNull($this->selector->reserve($this->owner->account->fresh()));
    }

    // -------------------------------------------------- admin assignments

    public function test_an_unassigned_admin_account_reaches_nobody(): void
    {
        SmtpAccount::factory()->global()->create(['name' => 'Shared']);

        $this->assertCount(0, $this->selector->candidatesFor($this->owner->account));
    }

    public function test_an_admin_account_assigned_to_all_is_offered(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'Shared']);
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->assertSame(['Shared'], $this->selector->candidatesFor($this->owner->account)->pluck('name')->all());
    }

    public function test_a_plan_assignment_only_reaches_that_plan(): void
    {
        $shared = SmtpAccount::factory()->global()->create(['name' => 'BusinessOnly']);
        $business = Plan::where('slug', 'business')->firstOrFail();

        SmtpAssignment::create([
            'smtp_account_id' => $shared->id, 'scope' => 'plan', 'plan_id' => $business->id,
        ]);

        // The account is on Starter, so it must not see it.
        $this->assertCount(0, $this->selector->candidatesFor($this->owner->account));

        $this->owner->account->subscription->update(['plan_id' => $business->id]);

        $this->assertSame(
            ['BusinessOnly'],
            $this->selector->candidatesFor($this->owner->account->fresh())->pluck('name')->all()
        );
    }

    public function test_an_account_assignment_reaches_only_that_account(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@senders.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        $shared = SmtpAccount::factory()->global()->create(['name' => 'JustForRival']);
        SmtpAssignment::create([
            'smtp_account_id' => $shared->id, 'scope' => 'account', 'account_id' => $other->account_id,
        ]);

        $this->assertCount(0, $this->selector->candidatesFor($this->owner->account));
        $this->assertCount(1, $this->selector->candidatesFor($other->account));
    }

    public function test_a_plan_without_admin_smtp_cannot_use_a_shared_account(): void
    {
        $this->allow(['allow_admin_smtp' => false]);

        $shared = SmtpAccount::factory()->global()->create();
        SmtpAssignment::create(['smtp_account_id' => $shared->id, 'scope' => 'all']);

        $this->assertCount(0, $this->selector->candidatesFor($this->owner->account->fresh()));
    }

    public function test_one_account_never_sees_another_accounts_own_smtp(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival2@senders.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        SmtpAccount::factory()->forAccount($other->account)->create(['name' => 'RivalSmtp']);
        $this->own(['name' => 'MySmtp']);

        $this->assertSame(['MySmtp'], $this->selector->candidatesFor($this->owner->account)->pluck('name')->all());
    }

    // -------------------------------------------------------- health

    public function test_failures_accumulate_and_trigger_a_cooldown(): void
    {
        config(['knsoftic.smtp_failure_threshold' => 3, 'knsoftic.smtp_cooldown_minutes' => 15]);

        $account = $this->own();

        $this->assertFalse($this->selector->recordFailure($account, 'Connection refused'));
        $this->assertFalse($this->selector->recordFailure($account, 'Connection refused'));
        $this->assertTrue(
            $this->selector->recordFailure($account, 'Connection refused'),
            'The third failure must trip the breaker.'
        );

        $fresh = $account->fresh();

        $this->assertSame(3, (int) $fresh->consecutive_failures);
        $this->assertNotNull($fresh->cooldown_until);
        $this->assertFalse($this->selector->reserveOn($fresh), 'A cooled-down account must stop being charged.');
    }

    public function test_a_success_clears_the_failure_streak(): void
    {
        $account = $this->own();

        $this->selector->recordFailure($account, 'blip');
        $this->selector->recordSuccess($account->fresh());

        $fresh = $account->fresh();

        $this->assertSame(0, (int) $fresh->consecutive_failures);
        $this->assertNull($fresh->cooldown_until);
        $this->assertNotNull($fresh->last_success_at);
    }

    public function test_a_stored_error_never_contains_the_credential(): void
    {
        $account = $this->own(['username' => 'user@example.com', 'password' => 'sup3r-s3cret']);

        $this->selector->recordFailure(
            $account,
            'Expected response code "235" but got "535", with message "535 auth failed for user@example.com / sup3r-s3cret"'
        );

        $stored = (string) $account->fresh()->last_error;

        $this->assertStringNotContainsString('sup3r-s3cret', $stored);
        $this->assertStringNotContainsString('user@example.com', $stored);
        $this->assertStringContainsString('[redacted]', $stored);
    }

    // --------------------------------------------------------------- usage

    public function test_usage_is_buffered_and_written_once_per_flush(): void
    {
        $account = $this->own();

        for ($i = 0; $i < 50; $i++) {
            $this->selector->bufferUsage($account->id, $this->owner->account_id, sent: 1);
        }

        $this->assertSame(0, DB::table('smtp_usage')->count(), 'Nothing is written until the flush.');

        $this->selector->flushUsage();

        $row = DB::table('smtp_usage')->first();

        $this->assertNotNull($row);
        $this->assertSame(50, (int) $row->sent);
        $this->assertSame(1, DB::table('smtp_usage')->count(), '50 sends must be one row, not 50.');

        // A second flush on the same day accumulates rather than replacing.
        $this->selector->bufferUsage($account->id, $this->owner->account_id, sent: 5, failed: 2);
        $this->selector->flushUsage();

        $row = DB::table('smtp_usage')->first();

        $this->assertSame(55, (int) $row->sent);
        $this->assertSame(2, (int) $row->failed);
    }
}
