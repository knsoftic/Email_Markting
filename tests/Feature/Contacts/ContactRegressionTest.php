<?php

namespace Tests\Feature\Contacts;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regressions for defects that were found after the module was first built.
 * Each of these shipped broken once; none of them may ship broken again.
 */
class ContactRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);
        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Probe Ltd', 'name' => 'Owner', 'email' => 'owner@probe.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    public function test_re_adding_a_soft_deleted_contact_restores_it_instead_of_crashing(): void
    {
        $s = Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'gone@example.com']);
        $s->delete();

        $this->post('/subscribers', [
            'email' => 'gone@example.com',
            'name' => 'Back Again',
            'status' => 'active',
            'consent_status' => 'unknown',
        ])->assertRedirect();

        $this->assertSame(1, Subscriber::withoutGlobalScopes()->where('email','gone@example.com')->whereNull('deleted_at')->count());
    }

    public function test_bulk_delete_is_refused_without_the_delete_permission(): void
    {
        $staffRole = Role::withoutGlobalScopes()->where('slug', Role::STAFF)->first();
        $staffRole->permissions()->syncWithoutDetaching(
            Permission::whereIn('slug', ['contacts.view','contacts.update'])->pluck('id')
        );
        $staff = User::factory()->forAccount($this->owner->account)->create(['role_id' => $staffRole->id]);

        $victims = Subscriber::factory()->forAccount($this->owner->account)->count(3)->create();

        $this->actingAs($staff->fresh())->post('/subscribers/bulk', [
            'action' => 'delete',
            'ids' => $victims->pluck('id')->all(),
        ])->assertForbidden();

        $this->assertSame(3, Subscriber::withoutGlobalScopes()
            ->where('account_id', $this->owner->account_id)->whereNull('deleted_at')->count());
    }

    public function test_a_warning_flash_is_actually_rendered(): void
    {
        // The app layout used to include the flash partial only for
        // status/success/error, silently swallowing every 'warning' the
        // controllers set — including the "nothing was selected" bulk reply.
        // from() sets the referer so back() returns to the contacts screen,
        // which is where a real browser would land.
        $this->from('/subscribers')
            ->followingRedirects()
            ->post('/subscribers/bulk', [
                'action' => 'change_status',
                'ids' => [999999],
                'status' => 'active',
            ])
            ->assertOk()
            ->assertSee('Nothing was selected.');
    }

    public function test_a_failed_import_resumes_instead_of_re_reading_the_file(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $import = \App\Models\Import::create([
            'user_id' => $this->owner->id,
            'original_name' => 'partial.csv',
            'file_path' => 'imports/x.csv',
            'status' => 'failed',
            'mapping' => ['Email' => 'email'],
            'total_rows' => 1000,
            'processed_rows' => 600,
            'imported_count' => 590,
            'invalid_count' => 10,
        ]);

        $this->post("/imports/{$import->id}/start")->assertRedirect();

        $import->refresh();

        $this->assertSame(600, $import->processed_rows, 'Resuming must keep the cursor.');
        $this->assertSame(590, $import->imported_count, 'Resuming must keep the counters.');
        $this->assertSame('pending', $import->status);
    }

    public function test_starting_over_explicitly_resets_the_cursor(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $import = \App\Models\Import::create([
            'user_id' => $this->owner->id,
            'original_name' => 'partial.csv',
            'file_path' => 'imports/x.csv',
            'status' => 'failed',
            'mapping' => ['Email' => 'email'],
            'total_rows' => 1000,
            'processed_rows' => 600,
            'imported_count' => 590,
        ]);

        $this->post("/imports/{$import->id}/start", ['restart' => '1'])->assertRedirect();

        $import->refresh();

        $this->assertSame(0, $import->processed_rows);
        $this->assertSame(0, $import->imported_count);
    }
}
