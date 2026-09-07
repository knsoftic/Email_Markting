<?php

namespace Tests\Feature\Contacts;

use App\Jobs\Contacts\ProcessSubscriberImport;
use App\Models\CustomField;
use App\Models\Import;
use App\Models\Segment;
use App\Models\Subscriber;
use App\Models\SubscriberList;
use App\Models\Suppression;
use App\Models\Tag;
use App\Models\User;
use App\Services\AccountProvisioner;
use App\Support\TenantManager;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->seed([PermissionSeeder::class, RoleSeeder::class, PlanSeeder::class]);

        $this->owner = app(AccountProvisioner::class)->provision([
            'company_name' => 'Importers Ltd', 'name' => 'Owner', 'email' => 'owner@import.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $this->owner->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($this->owner);
        app(TenantManager::class)->set($this->owner->account_id);
    }

    protected function upload(string $contents, string $name = 'contacts.csv'): Import
    {
        $file = UploadedFile::fake()->createWithContent($name, $contents);

        $this->post('/imports', ['file' => $file])->assertRedirect();

        return Import::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** Runs the queued job synchronously so assertions can inspect the result. */
    protected function process(Import $import): Import
    {
        app(ProcessSubscriberImport::class, ['importId' => $import->id]);

        (new ProcessSubscriberImport($import->id))->handle(
            app(TenantManager::class),
            app(\App\Services\Contacts\SuppressionService::class),
            app(\App\Services\Contacts\ListService::class),
        );

        return $import->fresh();
    }

    protected function configure(Import $import, array $overrides = []): Import
    {
        $import->forceFill(array_merge([
            'mapping' => ['Email' => 'email', 'Name' => 'name', 'Country' => 'country'],
            'options' => array_merge($import->options ?? [], [
                'duplicates' => 'skip',
                'status' => 'active',
                'consent_status' => 'explicit',
                'source' => 'import',
            ]),
            'status' => 'pending',
        ], $overrides))->save();

        return $import->fresh();
    }

    // ------------------------------------------------------------- upload

    public function test_upload_detects_headers_and_counts_rows(): void
    {
        $import = $this->upload(
            "Email,Name,Country\na@example.com,Alice,Pakistan\nb@example.com,Bob,India\n"
        );

        $this->assertSame('mapping', $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(['Email', 'Name', 'Country'], $import->options['headers']);
        $this->assertSame($this->owner->account_id, $import->account_id);

        // The uploaded list must land on the private disk, never public.
        $this->assertStringStartsWith('imports/'.$this->owner->account_id.'/', $import->file_path);
        Storage::disk('local')->assertExists($import->file_path);
    }

    public function test_a_semicolon_file_with_a_bom_is_read_correctly(): void
    {
        $import = $this->upload(
            "\xEF\xBB\xBFEmail;Name\nsemi@example.com;Zoë\n",
            'european.csv'
        );

        $this->assertSame(['Email', 'Name'], $import->options['headers'],
            'The BOM must not become part of the first header name.');
        $this->assertSame(';', $import->options['delimiter']);

        $this->configure($import, ['mapping' => ['Email' => 'email', 'Name' => 'name']]);
        $this->process($import);

        $this->assertDatabaseHas('subscribers', [
            'account_id' => $this->owner->account_id,
            'email' => 'semi@example.com',
            'name' => 'Zoë',
        ]);
    }

    public function test_an_unreadable_file_is_rejected_without_leaving_a_record(): void
    {
        $before = Import::withoutGlobalScopes()->count();

        $this->post('/imports', [
            'file' => UploadedFile::fake()->createWithContent('empty.csv', ''),
        ])->assertSessionHas('error');

        $this->assertSame($before, Import::withoutGlobalScopes()->count());
    }

    public function test_mapping_requires_an_email_column(): void
    {
        $import = $this->upload("Name,Country\nAlice,Pakistan\n");

        $this->post("/imports/{$import->id}/map", [
            'mapping' => ['Name' => 'name', 'Country' => 'country'],
            'duplicates' => 'skip',
            'status' => 'active',
            'consent_status' => 'unknown',
        ])->assertSessionHas('error');

        $this->assertEmpty($import->fresh()->mapping);
    }

    public function test_starting_an_import_queues_it_rather_than_processing_inline(): void
    {
        Queue::fake();

        $import = $this->configure($this->upload("Email\nq@example.com\n"));

        $this->post("/imports/{$import->id}/start")->assertRedirect();

        Queue::assertPushed(ProcessSubscriberImport::class);
        $this->assertSame(0, Subscriber::withoutGlobalScopes()->count(),
            'The HTTP request must not import any rows itself.');
    }

    // ---------------------------------------------------------- processing

    public function test_a_clean_file_imports_every_row(): void
    {
        $import = $this->configure($this->upload(
            "Email,Name,Country\na@example.com,Alice Smith,Pakistan\nb@example.com,Bob Jones,India\n"
        ));

        $import = $this->process($import);

        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported_count);
        $this->assertSame(0, $import->invalid_count);

        $alice = Subscriber::withoutGlobalScopes()->firstWhere('email', 'a@example.com');
        $this->assertSame('Alice Smith', $alice->name);
        $this->assertSame('Alice', $alice->first_name, 'A single name column must be split.');
        $this->assertSame('Smith', $alice->last_name);
        $this->assertSame('active', $alice->status);
        $this->assertSame('explicit', $alice->consent_status);
    }

    public function test_invalid_addresses_are_counted_and_reported_not_imported(): void
    {
        $import = $this->configure($this->upload(
            "Email,Name\ngood@example.com,Good\nnot-an-email,Bad\n,Blank\n"
        ));

        $import = $this->process($import);

        $this->assertSame(1, $import->imported_count);
        $this->assertSame(2, $import->invalid_count);
        $this->assertCount(2, $import->error_rows);
        $this->assertSame('Invalid or missing email address', $import->error_rows[0]['reason']);
    }

    public function test_duplicates_are_skipped_by_default(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->create([
            'email' => 'existing@example.com', 'name' => 'Original Name',
        ]);

        $import = $this->configure($this->upload(
            "Email,Name\nexisting@example.com,Changed Name\nnew@example.com,Fresh\n"
        ));

        $import = $this->process($import);

        $this->assertSame(1, $import->imported_count);
        $this->assertSame(1, $import->duplicate_count);
        $this->assertSame('Original Name',
            Subscriber::withoutGlobalScopes()->firstWhere('email', 'existing@example.com')->name);
    }

    public function test_update_mode_fills_gaps_without_wiping_existing_values(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->create([
            'email' => 'existing@example.com', 'name' => 'Original Name', 'company' => 'Keep Me Ltd',
        ]);

        $import = $this->upload("Email,Name,Company\nexisting@example.com,New Name,\n");
        $this->configure($import, [
            'mapping' => ['Email' => 'email', 'Name' => 'name', 'Company' => 'company'],
            'options' => array_merge($import->options ?? [], [
                'duplicates' => 'update', 'status' => 'active',
                'consent_status' => 'explicit', 'source' => 'import',
            ]),
        ]);

        $import = $this->process($import->fresh());

        $subscriber = Subscriber::withoutGlobalScopes()->firstWhere('email', 'existing@example.com');

        $this->assertSame(1, $import->updated_count);
        $this->assertSame('New Name', $subscriber->name);
        $this->assertSame('Keep Me Ltd', $subscriber->company,
            'A blank cell must not erase an existing value.');
    }

    public function test_a_suppressed_address_is_imported_but_never_as_active(): void
    {
        Suppression::factory()->forAccount($this->owner->account)->create([
            'email' => 'optout@example.com', 'reason' => 'unsubscribed',
        ]);

        $import = $this->configure($this->upload(
            "Email,Name\noptout@example.com,Opted Out\nok@example.com,Fine\n"
        ));

        $import = $this->process($import);

        $this->assertSame(1, $import->suppressed_count);
        $this->assertSame('unsubscribed',
            Subscriber::withoutGlobalScopes()->firstWhere('email', 'optout@example.com')->status,
            'An import must never re-activate an address that opted out.');
        $this->assertSame('active',
            Subscriber::withoutGlobalScopes()->firstWhere('email', 'ok@example.com')->status);
    }

    public function test_the_plan_contact_limit_stops_the_import_and_says_so(): void
    {
        $this->owner->account->subscription->update(['overrides' => ['max_contacts' => 2]]);

        $import = $this->configure($this->upload(
            "Email\none@example.com\ntwo@example.com\nthree@example.com\nfour@example.com\n"
        ));

        $import = $this->process($import);

        $this->assertSame(2, $import->imported_count);
        $this->assertSame('completed', $import->status);
        $this->assertStringContainsString('plan contact limit', (string) $import->last_error);
        $this->assertSame(2, Subscriber::withoutGlobalScopes()
            ->where('account_id', $this->owner->account_id)->count());
    }

    public function test_imported_contacts_join_the_chosen_lists_and_tags(): void
    {
        $list = SubscriberList::factory()->forAccount($this->owner->account)->create();
        $tag = Tag::factory()->forAccount($this->owner->account)->create();

        $import = $this->upload("Email\nx@example.com\ny@example.com\n");
        $this->configure($import, [
            'mapping' => ['Email' => 'email'],
            'list_ids' => [$list->id],
            'tag_ids' => [$tag->id],
        ]);

        $this->process($import->fresh());

        $this->assertSame(2, $list->fresh()->total_count);
        $this->assertSame(2, $list->fresh()->active_count);
        $this->assertSame(2, $tag->fresh()->subscribers_count);
    }

    public function test_custom_field_columns_are_stored_in_the_json_column(): void
    {
        CustomField::factory()->forAccount($this->owner->account)->create([
            'name' => 'Tier', 'key' => 'tier', 'type' => 'text',
        ]);

        $import = $this->upload("Email,Tier\ngold@example.com,gold\n");
        $this->configure($import, ['mapping' => ['Email' => 'email', 'Tier' => 'custom:tier']]);

        $this->process($import->fresh());

        $this->assertSame('gold',
            Subscriber::withoutGlobalScopes()->firstWhere('email', 'gold@example.com')->customValue('tier'));
    }

    public function test_a_tampered_mapping_cannot_write_to_an_arbitrary_column(): void
    {
        $import = $this->upload("Email,Sneaky\nz@example.com,1\n");

        $this->post("/imports/{$import->id}/map", [
            'mapping' => ['Email' => 'email', 'Sneaky' => 'is_super_admin'],
            'duplicates' => 'skip',
            'status' => 'active',
            'consent_status' => 'unknown',
        ])->assertRedirect();

        $this->assertSame(['Email' => 'email'], $import->fresh()->mapping,
            'Only known target fields may survive mapping.');
    }

    public function test_cancelling_stops_the_import_at_the_next_chunk(): void
    {
        $import = $this->configure($this->upload("Email\nc1@example.com\n"));

        $import->forceFill(['status' => 'cancelled'])->save();

        $result = $this->process($import->fresh());

        $this->assertSame('cancelled', $result->status);
        $this->assertSame(0, Subscriber::withoutGlobalScopes()->count());
    }

    public function test_progress_endpoint_reports_live_counters(): void
    {
        $import = $this->configure($this->upload("Email\np1@example.com\np2@example.com\n"));
        $this->process($import);

        $this->getJson("/imports/{$import->id}/progress")
            ->assertOk()
            ->assertJson([
                'status' => 'completed',
                'total_rows' => 2,
                'processed_rows' => 2,
                'imported' => 2,
                'finished' => true,
            ]);
    }

    public function test_one_account_cannot_open_another_accounts_import(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Other Ltd', 'name' => 'Other', 'email' => 'other@import.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);
        $other->forceFill(['email_verified_at' => now()])->save();

        $import = $this->upload("Email\nsecret@example.com\n");

        $this->actingAs($other);
        app(TenantManager::class)->set($other->account_id);

        $this->get("/imports/{$import->id}")->assertNotFound();
        $this->getJson("/imports/{$import->id}/progress")->assertNotFound();
    }

    // ------------------------------------------------------------- export

    public function test_export_streams_the_selected_columns(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->create([
            'email' => 'export@example.com', 'name' => 'Export Me', 'country' => 'Pakistan',
        ]);

        $response = $this->post('/exports', [
            'source' => 'all',
            'columns' => ['email', 'name', 'country'],
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $this->streamed($response);

        $this->assertStringContainsString('Email,Name,Country', $csv);
        $this->assertStringContainsString('export@example.com,"Export Me",Pakistan', $csv);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'A BOM keeps Excel from mangling UTF-8.');
    }

    public function test_export_by_segment_uses_the_segment_rules(): void
    {
        $segment = Segment::factory()->forAccount($this->owner->account)->withRules([
            ['field' => 'country', 'operator' => 'is', 'value' => 'Pakistan'],
        ])->create();

        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'in@example.com', 'country' => 'Pakistan']);
        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'out@example.com', 'country' => 'France']);

        $csv = $this->streamed($this->post('/exports', [
            'source' => 'segment',
            'segment_id' => $segment->id,
            'columns' => ['email'],
        ]));

        $this->assertStringContainsString('in@example.com', $csv);
        $this->assertStringNotContainsString('out@example.com', $csv);
    }

    public function test_export_never_reaches_another_accounts_contacts(): void
    {
        $other = app(AccountProvisioner::class)->provision([
            'company_name' => 'Rival Ltd', 'name' => 'Rival', 'email' => 'rival@import.test',
            'password' => 'Password123!', 'timezone' => 'UTC',
        ]);

        Subscriber::factory()->forAccount($other->account)->create(['email' => 'rival-contact@example.com']);
        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'mine@example.com']);

        $foreignSegment = Segment::factory()->forAccount($other->account)->create();

        // A foreign segment id must be rejected by validation.
        $this->post('/exports', [
            'source' => 'segment',
            'segment_id' => $foreignSegment->id,
            'columns' => ['email'],
        ])->assertSessionHasErrors('segment_id');

        $csv = $this->streamed($this->post('/exports', ['source' => 'all', 'columns' => ['email']]));

        $this->assertStringContainsString('mine@example.com', $csv);
        $this->assertStringNotContainsString('rival-contact@example.com', $csv);
    }

    public function test_export_can_be_limited_to_mailable_contacts(): void
    {
        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'ok@example.com']);
        Subscriber::factory()->forAccount($this->owner->account)->unsubscribed()->create(['email' => 'gone@example.com']);
        Subscriber::factory()->forAccount($this->owner->account)->create(['email' => 'blocked@example.com']);
        Suppression::factory()->forAccount($this->owner->account)->create(['email' => 'blocked@example.com']);

        $csv = $this->streamed($this->post('/exports', [
            'source' => 'all',
            'mailable_only' => '1',
            'columns' => ['email'],
        ]));

        $this->assertStringContainsString('ok@example.com', $csv);
        $this->assertStringNotContainsString('gone@example.com', $csv);
        $this->assertStringNotContainsString('blocked@example.com', $csv);
    }

    protected function streamed($response): string
    {
        ob_start();
        $response->baseResponse->sendContent();

        return (string) ob_get_clean();
    }
}
