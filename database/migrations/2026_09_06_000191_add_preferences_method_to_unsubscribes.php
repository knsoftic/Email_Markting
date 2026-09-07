<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'preferences' to the ways somebody can opt out.
 *
 * Leaving every list on the preferences page is a full opt-out, and it is
 * recorded as one — but it is not the same act as clicking Unsubscribe, and a
 * sender reading their own reports should be able to tell the two apart. A
 * truncated value would have quietly become the column default instead.
 *
 * Raw ALTER rather than a Blueprint change: Laravel's change() needs
 * doctrine/dbal for enum columns, and this is one statement.
 */
return new class extends Migration
{
    private const METHODS = "'link','one_click','manual','import','complaint','preferences'";

    private const ORIGINAL = "'link','one_click','manual','import','complaint'";

    public function up(): void
    {
        DB::statement(
            'ALTER TABLE unsubscribes MODIFY method ENUM('.self::METHODS.") NOT NULL DEFAULT 'link'"
        );
    }

    public function down(): void
    {
        // Rows written by the preferences page would not fit the old set, so
        // they are moved to the closest truthful value first — they really
        // were a link click, just on a different page.
        DB::table('unsubscribes')->where('method', 'preferences')->update(['method' => 'link']);

        DB::statement(
            'ALTER TABLE unsubscribes MODIFY method ENUM('.self::ORIGINAL.") NOT NULL DEFAULT 'link'"
        );
    }
};
